<?php

namespace Tests\Feature;

use App\Jobs\NotifyHouseholdShare;
use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\User;
use App\Notifications\Push\AlbumAccessGranted;
use App\Notifications\Push\HouseholdMemberAdded;
use App\Notifications\Push\MediaSharedToHousehold;
use App\Services\PushNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pushs « sociaux » : ajout à un foyer, partage de médias (groupé), accès à
 * un album. Jamais à l'auteur, jamais la nuit, jamais sans opt-in.
 */
class PushSocialNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        // En journée (heure de Paris) : pas de report d'heures calmes.
        Carbon::setTestNow(Carbon::parse('2026-10-07 14:00', 'Europe/Paris'));
        $this->actor = User::factory()->create(['name' => 'Marie Dupont']);
        $this->member = User::factory()->create(['name' => 'Paul Herdegen']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Abonne un appareil et ouvre toutes les catégories. */
    private function subscribe(User $user): void
    {
        $user->updatePushSubscription("https://push.example.org/{$user->id}", 'k', 't');
        $user->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true]);
    }

    private function household(): Household
    {
        $h = Household::factory()->create(['created_by' => $this->actor->id]);
        $h->members()->attach($this->actor->id);

        return $h;
    }

    public function test_ajout_au_foyer_previent_le_nouvel_entrant_pas_l_auteur(): void
    {
        Notification::fake();
        $this->subscribe($this->actor);
        $this->subscribe($this->member);
        $h = $this->household();

        $this->actingAs($this->actor)->postJson("/households/{$h->id}/members", ['user_id' => $this->member->id])->assertCreated();

        Notification::assertSentTo($this->member, HouseholdMemberAdded::class, function (HouseholdMemberAdded $n) use ($h) {
            $data = $n->toWebPush($this->member, $n)->toArray();

            return $n->actorFirstName === 'Marie'
                && $data['data']['url'] === "/households/{$h->id}"
                && str_contains($data['body'], $h->name);
        });
        Notification::assertNotSentTo($this->actor, HouseholdMemberAdded::class);

        // Ré-ajouter un membre existant : aucun second push.
        $this->actingAs($this->actor)->postJson("/households/{$h->id}/members", ['user_id' => $this->member->id])->assertCreated();
        Notification::assertSentToTimes($this->member, HouseholdMemberAdded::class, 1);
    }

    public function test_pas_de_push_sans_abonnement_ou_categorie_fermee(): void
    {
        Notification::fake();
        $h = $this->household();

        // Sans abonnement.
        $this->actingAs($this->actor)->postJson("/households/{$h->id}/members", ['user_id' => $this->member->id])->assertCreated();
        Notification::assertNothingSent();

        // Abonné mais « Vie du foyer » désactivé.
        $other = User::factory()->create();
        $this->subscribe($other);
        $other->setPreference('push.social', false);
        $this->actingAs($this->actor)->postJson("/households/{$h->id}/members", ['user_id' => $other->id])->assertCreated();
        Notification::assertNothingSent();
    }

    public function test_acces_album_previent_le_beneficiaire_une_seule_fois(): void
    {
        Notification::fake();
        $this->subscribe($this->member);
        $album = Album::factory()->create(['user_id' => $this->actor->id, 'name' => 'Vacances 2019']);

        $this->actingAs($this->actor)->postJson("/albums/{$album->id}/access", ['user_id' => $this->member->id])->assertCreated();
        $this->actingAs($this->actor)->postJson("/albums/{$album->id}/access", ['user_id' => $this->member->id])->assertCreated();

        Notification::assertSentToTimes($this->member, AlbumAccessGranted::class, 1);
        Notification::assertSentTo($this->member, AlbumAccessGranted::class, function (AlbumAccessGranted $n) use ($album) {
            $data = $n->toWebPush($this->member, $n)->toArray();

            return $data['data']['url'] === "/albums/{$album->id}"
                && $data['body'] === "Marie vous a donné accès à l'album « Vacances 2019 ».";
        });
    }

    public function test_partages_successifs_vers_un_foyer_ne_planifient_qu_un_seul_job(): void
    {
        Bus::fake();
        $h = $this->household();
        $h->members()->attach($this->member->id);
        $media = Media::factory()->count(5)->create(['user_id' => $this->actor->id]);

        $this->actingAs($this->actor)->postJson('/media/bulk/household', [
            'media_ids' => $media->take(3)->pluck('id')->all(),
            'household_id' => $h->id,
        ])->assertOk();
        $this->actingAs($this->actor)->postJson('/media/bulk/household', [
            'media_ids' => $media->pluck('id')->all(), // 3 déjà partagés + 2 nouveaux
            'household_id' => $h->id,
        ])->assertOk();

        Bus::assertDispatchedTimes(NotifyHouseholdShare::class, 1);
        $this->assertSame(5, Cache::get(PushNotifier::shareKey($this->actor->id, $h->id) . ':count'));
    }

    public function test_le_job_groupe_envoie_le_total_aux_autres_membres(): void
    {
        Notification::fake();
        $this->subscribe($this->actor);
        $this->subscribe($this->member);
        $h = $this->household();
        $h->members()->attach($this->member->id);

        $key = PushNotifier::shareKey($this->actor->id, $h->id);
        Cache::put("{$key}:count", 37, 600);
        Cache::put("{$key}:scheduled", 1, 600);

        (new NotifyHouseholdShare($this->actor->id, $h->id))->handle(app(PushNotifier::class));

        Notification::assertSentTo($this->member, MediaSharedToHousehold::class, function (MediaSharedToHousehold $n) {
            $data = $n->toWebPush($this->member, $n)->toArray();

            return $n->count === 37 && str_contains($data['body'], 'Marie a partagé 37 souvenirs');
        });
        Notification::assertNotSentTo($this->actor, MediaSharedToHousehold::class);
        $this->assertNull(Cache::get("{$key}:count"));
        $this->assertNull(Cache::get("{$key}:scheduled"));
    }

    public function test_le_soir_le_push_est_differe_au_lendemain_8h(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 22:30', 'Europe/Paris'));
        Notification::fake();
        $this->subscribe($this->member);
        $h = $this->household();

        $this->actingAs($this->actor)->postJson("/households/{$h->id}/members", ['user_id' => $this->member->id])->assertCreated();

        Notification::assertSentTo($this->member, HouseholdMemberAdded::class, function (HouseholdMemberAdded $n) {
            return $n->delay !== null
                && Carbon::instance($n->delay)->setTimezone('Europe/Paris')->format('Y-m-d H:i') === '2026-10-08 08:00';
        });
    }
}
