<?php

namespace Tests\Feature;

use App\Mail\FamilyNewsletterMail;
use App\Models\Household;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Lettre du dimanche (famille) : contenu par destinataire, variété d'une
 * semaine à l'autre, envoi réservé à la famille, désabonnement.
 */
class FamilyNewsletterTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $cousin;
    private Household $family;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:30', 'Europe/Paris'));

        $this->family = Household::create(['name' => 'Famille', 'kind' => Household::KIND_FAMILY, 'invite_policy' => 'everyone', 'created_by' => User::factory()->create()->id]);
        $this->me = $this->member('Matthieu');
        $this->cousin = $this->member('Julien');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $first): User
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => 'Herdegen', 'birth_date' => '1988-06-16']);
        $user = User::factory()->create(['name' => "{$first} Herdegen", 'person_id' => $person->id]);
        $person->update(['user_id' => $user->id]);
        $this->family->addMember($user, $user);

        return $user;
    }

    /** Fiche de la famille avec portrait et N photos partagées avec la famille. */
    private function featuredPerson(string $first, int $photos = 3): Person
    {
        $person = Person::factory()->create(['first_name' => $first, 'in_family_directory' => true, 'portrait_path' => "portraits/{$first}.jpg"]);
        for ($i = 0; $i < $photos; $i++) {
            $media = Media::factory()->photo()->create(['user_id' => $this->cousin->id, 'is_source' => false, 'uploaded_at' => now()->subDays(30)]);
            $media->people()->attach($person->id);
            $this->family->media()->attach($media->id, ['added_by' => $this->cousin->id]);
        }

        return $person;
    }

    private function build(User $user): ?array
    {
        return app(FamilyNewsletterBuilder::class)->build($user);
    }

    public function test_nouvelles_photos_personne_de_la_semaine_et_decouverte(): void
    {
        $photo = Media::factory()->photo()->create(['user_id' => $this->cousin->id, 'is_source' => false, 'uploaded_at' => now()->subDay()]);
        $this->family->media()->attach($photo->id, ['added_by' => $this->cousin->id]);
        $this->featuredPerson('Yves');

        $issue = $this->build($this->me);
        $by = collect($issue['sections'])->keyBy('key');

        $this->assertSame(1, $by['new_photos']['count']);
        $this->assertSame(['Julien'], $by['new_photos']['uploaders']);
        $this->assertSame('Yves', $by['person_of_week']['first_name']);
        $this->assertArrayHasKey('spotlight', $by->all());
        $this->assertStringContainsString('1 nouvelle photo', $issue['subject']);

        // Le gabarit se rend (HTML et texte), lien de désabonnement compris.
        $html = (new FamilyNewsletterMail($this->me, $issue))->render();
        $this->assertStringContainsString('Yves', $html);
        $this->assertStringContainsString('/newsletter/unsubscribe/'.$this->me->id, $html);
    }

    public function test_rien_de_neuf_ni_personne_a_l_honneur_pas_de_lettre(): void
    {
        // Une semaine plus tard : les fiches du décor ne sont plus « nouvelles ».
        Carbon::setTestNow(now()->addDays(8));
        $this->assertNull($this->build($this->me));
    }

    public function test_la_lettre_change_d_une_semaine_a_l_autre(): void
    {
        $this->featuredPerson('Yves');
        $this->featuredPerson('Anne');

        $first = $this->build($this->me);
        NewsletterSend::create(['user_id' => $this->me->id, 'sections' => $first['log'], 'subject' => $first['subject'], 'sent_at' => now()]);

        Carbon::setTestNow(now()->addWeek());
        $second = $this->build($this->me);

        $pick = fn ($issue, $key) => collect($issue['sections'])->firstWhere('key', $key)['ref'] ?? null;
        $this->assertNotSame($pick($first, 'person_of_week'), $pick($second, 'person_of_week'), 'autre personne de la semaine');
        $this->assertNotSame($pick($first, 'spotlight'), $pick($second, 'spotlight'), 'autre fonctionnalité');
    }

    public function test_pas_de_coup_de_pouce_frise_pour_un_enfant(): void
    {
        $baby = Person::factory()->create(['first_name' => 'Valentine', 'birth_date' => now()->subYear(), 'father_id' => $this->me->person_id, 'in_family_directory' => true]);
        $this->featuredPerson('Yves');

        $issue = $this->build($this->me);
        $timeline = collect($issue['sections'])->firstWhere('key', 'timeline');

        $this->assertNotSame($baby->id, $timeline['ref'] ?? null);
    }

    public function test_envoi_famille_amis_et_desabonnes(): void
    {
        Mail::fake();
        Notification::fake();
        $this->featuredPerson('Yves');

        // Un compte des seuls cercles d'amis : l'ancien résumé, pas la lettre.
        $friendsCircle = Household::create(['name' => 'Amis', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => 'admin', 'created_by' => $this->me->id]);
        $friend = User::factory()->create(['name' => 'Sarah']);
        $friendsCircle->addMember($friend, $this->me);
        $photo = Media::factory()->photo()->create(['user_id' => $this->me->id, 'is_source' => false, 'uploaded_at' => now()->subDay()]);
        $friendsCircle->media()->attach($photo->id, ['added_by' => $this->me->id]);

        $this->cousin->setPreference('newsletter', false);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Mail::assertQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($this->me->email));
        Mail::assertNotQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($this->cousin->email));
        Mail::assertNotQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($friend->email));
        Notification::assertSentTo($friend, WeeklyDigest::class);
        $this->assertSame(1, NewsletterSend::where('user_id', $this->me->id)->count());
    }

    public function test_apercu_sans_envoi(): void
    {
        Mail::fake();
        $this->featuredPerson('Yves');

        $this->artisan('memorylane:weekly-digest', ['--dry-run' => true])
            ->expectsOutputToContain('[aperçu] Matthieu Herdegen')
            ->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, NewsletterSend::count());
    }

    public function test_desabonnement_depuis_le_mail(): void
    {
        $url = FamilyNewsletterMail::unsubscribeUrl($this->me);

        // Ouvrir le lien (ou un antivirus qui le visite) ne désabonne pas.
        $this->get($url)->assertOk()->assertSee('Me désabonner');
        $this->assertTrue($this->me->fresh()->wantsNewsletter());

        // Le bouton (ou le « un clic » de la messagerie, sans session) désabonne.
        $this->post($url)->assertOk()->assertSee('noté');
        $this->assertFalse($this->me->fresh()->wantsNewsletter());

        // Lien falsifié : refusé.
        $this->post(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $this->post(URL::signedRoute('newsletter.resubscribe', ['user' => $this->me->id]))->assertOk();
        $this->assertTrue($this->me->fresh()->wantsNewsletter());
    }

    public function test_reglage_depuis_le_profil(): void
    {
        $this->actingAs($this->me)->putJson('/profile/newsletter', ['enabled' => false])->assertOk();
        $this->assertFalse($this->me->fresh()->wantsNewsletter());
    }
}
