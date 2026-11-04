<?php

namespace Tests\Feature;

use App\Mail\FamilyNewsletterMail;
use App\Mail\FriendsNewsletterMail;
use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use App\Services\Newsletter\FeatureSpotlights;
use App\Services\Newsletter\FriendsNewsletterBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lettre du dimanche, édition amis : destinataires (amis seulement, famille
 * + amis avec l'encart « Côté amis », famille seule sans encart), contenu
 * strictement limité aux cercles d'amis, envoi seulement s'il y a du
 * nouveau, historique, désabonnement, langues, options de la commande.
 */
class FriendsNewsletterTest extends TestCase
{
    use RefreshDatabase;

    private Household $family;

    private Household $friends;

    private User $matthieu;   // famille + amis

    private User $julien;     // famille seulement

    private User $sarah;      // amis seulement

    private User $paul;       // amis seulement

    protected function setUp(): void
    {
        parent::setUp();
        // Dimanche 25 octobre 2026 (semaine ISO 43).
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:30', 'Europe/Paris'));
        Notification::fake();

        $this->family = Household::create(['name' => 'Famille', 'kind' => Household::KIND_FAMILY, 'invite_policy' => 'everyone', 'created_by' => User::factory()->create()->id]);
        $this->matthieu = $this->account('Matthieu', 'Herdegen', inFamilyDirectory: true);
        $this->julien = $this->account('Julien', 'Herdegen', inFamilyDirectory: true);
        $this->family->addMember($this->matthieu, $this->matthieu);
        $this->family->addMember($this->julien, $this->matthieu);

        $this->friends = Household::create(['name' => 'La bande à Milou', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => 'admin', 'created_by' => $this->matthieu->id]);
        $this->sarah = $this->account('Sarah', 'Martin');
        $this->paul = $this->account('Paul', 'Durand');
        foreach ([$this->matthieu, $this->sarah, $this->paul] as $member) {
            $this->friends->addMember($member, $this->matthieu);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $first, string $last, bool $inFamilyDirectory = false): User
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'birth_date' => '1988-03-03', 'in_family_directory' => $inFamilyDirectory]);
        $user = User::factory()->create(['name' => "{$first} {$last}", 'email' => strtolower($first).'@example.org', 'person_id' => $person->id]);
        $person->update(['user_id' => $user->id]);

        return $user;
    }

    /** Photo envoyée par $owner (hier par défaut), partagée avec $circle, avec ces fiches identifiées. */
    private function photo(User $owner, ?Household $circle, array $people = [], array $attributes = []): Media
    {
        $media = Media::factory()->photo()->create($attributes + [
            'user_id' => $owner->id,
            'is_source' => false,
            'uploaded_at' => now()->subDay(),
            'taken_at' => '2013-05-01 12:00', // hors de la semaine en cours : pas de souvenir surprise
        ]);
        // Partage et identifications datés de l'envoi (pas « cette semaine » pour une vieille photo).
        $at = ['created_at' => $media->uploaded_at, 'updated_at' => $media->uploaded_at];
        if ($circle) {
            $circle->media()->attach($media->id, ['added_by' => $owner->id] + $at);
        }
        if ($people) {
            $media->people()->attach(collect($people)->mapWithKeys(fn ($p) => [$p->id => $at])->all());
        }

        return $media;
    }

    /**
     * Album $name de $owner partagé avec $circle il y a trois mois (pas une
     * nouveauté), $tagged identifiés sur la 1re photo, $faces visages sans nom.
     */
    private function album(string $name, User $owner, Household $circle, array $tagged, int $faces, array $attributes = []): Album
    {
        $now = now();
        Carbon::setTestNow($now->copy()->subMonths(3));
        $album = Album::create($attributes + ['user_id' => $owner->id, 'name' => $name]);
        $album->households()->attach($circle->id, ['granted_by' => $owner->id]);
        $photos = collect([0, 1])->map(function ($i) use ($album, $owner, $tagged) {
            $media = $this->photo($owner, null, $i === 0 ? $tagged : [], ['uploaded_at' => now()]);
            $album->media()->attach($media->id);

            return $media;
        });
        for ($i = 0; $i < $faces; $i++) {
            DetectedFace::create(['media_id' => $photos[$i % 2]->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20]]);
        }
        Carbon::setTestNow($now);

        return $album;
    }

    private function build(User $user): ?array
    {
        return app(FriendsNewsletterBuilder::class)->build($user->fresh());
    }

    private function sections(?array $issue): \Illuminate\Support\Collection
    {
        return collect($issue['sections'] ?? [])->keyBy('key');
    }

    /** Le mail rendu (HTML puis texte), pour les vérifications de contenu. */
    private function rendered(Mailable $mail): string
    {
        return $mail->render()."\n".view($mail->content()->text, $mail->content()->with)->render();
    }

    // ── Contenu ────────────────────────────────────────────────────────

    public function test_lettre_amis_nouvelles_photos_identification_et_decouverte(): void
    {
        $this->photo($this->paul, $this->friends);
        $this->photo($this->paul, $this->friends, [$this->sarah->person]);

        $issue = $this->build($this->sarah);
        $by = $this->sections($issue);

        $this->assertSame(2, $by['new_photos']['count']);
        $this->assertSame(['Paul'], $by['new_photos']['uploaders']);
        $this->assertSame(1, $by['tagged_you']['count']);
        $this->assertArrayHasKey($by['spotlight']['ref'], FeatureSpotlights::friends());
        $this->assertSame('La bande à Milou', $issue['circle']);
        $this->assertStringContainsString('La bande à Milou', $issue['subject']);
        $this->assertStringContainsString('2 nouvelles photos', $issue['subject']);

        $html = (new FriendsNewsletterMail($this->sarah, $issue))->render();
        $this->assertStringContainsString('La semaine entre amis', $html);
        $this->assertStringContainsString('/newsletter/unsubscribe/'.$this->sarah->id, $html);
    }

    public function test_anniversaires_souvenir_relances_et_qui_est_ce(): void
    {
        // Anniversaire de Paul dans 2 jours, souvenir de la même semaine en 2023.
        $this->paul->person->update(['birth_date' => '1990-10-27']);
        $memory = $this->photo($this->paul, $this->friends, [$this->sarah->person, $this->paul->person], [
            'uploaded_at' => now()->subMonths(6), 'taken_at' => '2023-10-24 20:00',
        ]);
        DetectedFace::create(['media_id' => $memory->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20]]);

        $by = $this->sections($this->build($this->sarah));

        $this->assertSame('Paul Durand', $by['birthdays']['items'][0]['name']);
        $this->assertSame($memory->id, $by['years_ago']['ref']);
        $this->assertSame(3, $by['years_ago']['years']);
        $this->assertSame('Paul', $by['years_ago']['who']);
        // Relances : comment avez-vous connu Paul (ensemble sur la photo) ? Un visage à nommer.
        $this->assertSame($this->paul->person_id, $by['how_met']['ref']);
        $this->assertSame(1, $by['how_met']['photos']);
        $this->assertSame(1, $by['faces']['count']);
        // La découverte ne refait pas la promotion du jeu déjà relancé.
        $this->assertNotSame('qui_est_ce', $by['spotlight']['ref']);
        // Entre amis, pas d'âge.
        $html = (new FriendsNewsletterMail($this->sarah, $this->build($this->sarah)))->render();
        $this->assertStringNotContainsString('36 ans', $html);
        $this->assertStringContainsString('Comment avez-vous connu Paul ?', $html);
    }

    public function test_aucun_contenu_famille_chez_un_ami(): void
    {
        // Monde famille : photo partagée à la famille (où Sarah est même
        // identifiée), fiche familiale avec anniversaire et souvenir.
        $yves = Person::factory()->create(['first_name' => 'Yves', 'last_name' => 'Herdegen', 'in_family_directory' => true, 'birth_date' => '1950-10-26']);
        $this->photo($this->julien, $this->family, [$yves, $this->sarah->person]);
        $this->photo($this->julien, $this->family, [$yves], ['uploaded_at' => now()->subYear(), 'taken_at' => '2020-10-21 10:00']);
        // Album de famille partagé à la famille.
        $album = Album::create(['user_id' => $this->julien->id, 'name' => 'Noël en famille']);
        $album->media()->attach($this->photo($this->julien, null)->id);
        $album->households()->attach($this->family->id, ['granted_by' => $this->julien->id]);

        // Côté amis : une seule nouvelle photo.
        $this->photo($this->paul, $this->friends);

        $issue = $this->build($this->sarah);
        $by = $this->sections($issue);

        $this->assertSame(1, $by['new_photos']['count']);
        $this->assertSame(['Paul'], $by['new_photos']['uploaders']);
        $this->assertArrayNotHasKey('tagged_you', $by->all(), 'photo de famille : pas une photo du cercle');
        $this->assertArrayNotHasKey('birthdays', $by->all());
        $this->assertArrayNotHasKey('years_ago', $by->all());

        $all = json_encode($issue, JSON_UNESCAPED_UNICODE).$this->rendered(new FriendsNewsletterMail($this->sarah, $issue));
        foreach (['Yves', 'Julien', 'Herdegen', 'Noël en famille', 'Famille'] as $familyWord) {
            $this->assertStringNotContainsString($familyWord, $all);
        }
    }

    public function test_pas_de_nouveaute_pas_de_lettre_meme_avec_des_relances(): void
    {
        // Vieille photo du cercle (relance « comment vous êtes-vous connus ? »
        // et visage à nommer possibles) : pas une nouveauté.
        $old = $this->photo($this->paul, $this->friends, [$this->sarah->person, $this->paul->person], ['uploaded_at' => now()->subMonths(2)]);
        DetectedFace::create(['media_id' => $old->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20]]);
        // Nouveauté côté famille seulement.
        $this->photo($this->julien, $this->family);

        $this->assertNull($this->build($this->sarah));

        Mail::fake();
        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Mail::assertNotQueued(FriendsNewsletterMail::class);
        $this->assertSame(0, NewsletterSend::whereIn('user_id', [$this->sarah->id, $this->paul->id])->count());
        Notification::assertNotSentTo($this->sarah, WeeklyDigest::class);
    }

    // ── Visages d'un album du cercle ───────────────────────────────────

    public function test_encart_visages_d_un_album_du_cercle_ou_l_on_apparait(): void
    {
        $album = $this->album('Week-end à Milou', $this->paul, $this->friends, [$this->sarah->person], 3);

        // Des visages à nommer ne suffisent pas à envoyer la lettre.
        $this->assertNull($this->build($this->sarah));

        $this->photo($this->paul, $this->friends);
        $issue = $this->build($this->sarah);
        $by = $this->sections($issue);

        $this->assertSame($album->id, $by['faces_album']['ref']);
        $this->assertSame(3, $by['faces_album']['count']);
        $this->assertSame('Week-end à Milou', $by['faces_album']['album']);
        $this->assertStringEndsWith('/quests/faces?album='.$album->id, $by['faces_album']['url']);
        $this->assertArrayNotHasKey('faces', $by->all(), 'un seul encart « Qui est-ce ? »');
        $this->assertNotSame('qui_est_ce', $by['spotlight']['ref']);
        $all = $this->rendered(new FriendsNewsletterMail($this->sarah, $issue));
        $this->assertStringContainsString('Il y a 3 visages non identifiés dans l’album « Week-end à Milou », où tu apparais.', $all);
        $this->assertStringContainsString('/quests/faces?album='.$album->id, $all);
        NewsletterSend::create(['user_id' => $this->sarah->id, 'edition' => 'friends', 'sections' => $issue['log'], 'subject' => $issue['subject'], 'sent_at' => now()]);

        // Semaine suivante : pas de visages deux lettres de suite.
        Carbon::setTestNow(now()->addWeek());
        $this->photo($this->paul, $this->friends);
        $second = $this->build($this->sarah);
        $this->assertArrayNotHasKey('faces_album', $this->sections($second)->all());
        $this->assertArrayNotHasKey('faces', $this->sections($second)->all());
        NewsletterSend::create(['user_id' => $this->sarah->id, 'edition' => 'friends', 'sections' => $second['log'], 'subject' => $second['subject'], 'sent_at' => now()]);

        // La suivante : l'album est au repos, les visages du cercle reviennent en général.
        Carbon::setTestNow(now()->addWeek());
        $this->photo($this->paul, $this->friends);
        $third = $this->sections($this->build($this->sarah));
        $this->assertArrayNotHasKey('faces_album', $third->all());
        $this->assertSame(3, $third['faces']['count']);
    }

    public function test_pas_d_encart_album_sous_le_seuil_ou_sans_y_figurer(): void
    {
        $this->album('Soirée', $this->paul, $this->friends, [$this->sarah->person], 2);
        $this->album('Sans Sarah', $this->paul, $this->friends, [$this->paul->person], 5);
        $this->photo($this->paul, $this->friends);

        $by = $this->sections($this->build($this->sarah));

        $this->assertArrayNotHasKey('faces_album', $by->all());
        // Le rappel général reste possible.
        $this->assertSame(7, $by['faces']['count']);
    }

    public function test_pas_d_album_de_la_famille_dans_la_lettre_amis(): void
    {
        // Album de la famille ouvert à ceux qui y figurent : Sarah y est
        // identifiée et peut le voir, mais il n'est pas du monde des amis.
        $this->album('Noël en famille', $this->julien, $this->family, [$this->sarah->person], 4, ['share_with_tagged' => true]);
        $this->photo($this->paul, $this->friends);

        $issue = $this->build($this->sarah);

        $this->assertArrayNotHasKey('faces_album', $this->sections($issue)->all());
        $all = json_encode($issue, JSON_UNESCAPED_UNICODE).$this->rendered(new FriendsNewsletterMail($this->sarah, $issue));
        $this->assertStringNotContainsString('Noël en famille', $all);
    }

    public function test_encart_visages_traduit(): void
    {
        $this->album('Week-end à Milou', $this->paul, $this->friends, [$this->sarah->person], 3);
        $this->photo($this->paul, $this->friends);

        $this->sarah->setPreference('locale', 'es');
        $mail = (new FriendsNewsletterMail($this->sarah, $this->build($this->sarah)))->locale('es');
        $mail->assertSeeInHtml('Hay 3 caras sin identificar en el álbum «Week-end à Milou», donde apareces.');
        $mail->assertSeeInHtml('Identificar las caras');
        $mail->assertSeeInText('Ayúdanos a identificarlas:');

        $this->sarah->setPreference('locale', 'en');
        $mail = (new FriendsNewsletterMail($this->sarah, $this->build($this->sarah)))->locale('en');
        $mail->assertSeeInHtml('There are 3 unidentified faces in “Week-end à Milou”, an album you appear in.');
        $mail->assertSeeInText('There are 3 unidentified faces in “Week-end à Milou”, an album you appear in. Help us identify them:');
        $mail->assertDontSeeInText('&#039;');
    }

    // ── Destinataires ──────────────────────────────────────────────────

    public function test_destinataires_amis_famille_et_amis_famille_seule(): void
    {
        Mail::fake();
        $this->photo($this->paul, $this->friends, [$this->sarah->person]);
        $this->photo($this->julien, $this->family);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        // Amis seulement → lettre amis.
        Mail::assertQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->sarah->email));
        Mail::assertNotQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($this->sarah->email));
        // Famille + amis → lettre famille avec l'encart « Côté amis ».
        Mail::assertQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($this->matthieu->email)
            && collect($m->issue['sections'])->contains('key', 'friends'));
        Mail::assertNotQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->matthieu->email));
        // Famille seule → lettre famille sans encart (rien de neuf pour Julien : c'est lui qui a envoyé).
        Mail::assertNotQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->julien->email));
        Mail::assertNotQueued(FamilyNewsletterMail::class, fn ($m) => $m->hasTo($this->julien->email)
            && collect($m->issue['sections'])->contains('key', 'friends'));
        // Paul a envoyé la seule photo du cercle : rien de neuf pour lui.
        Mail::assertNotQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->paul->email));
    }

    public function test_encart_cote_amis_dans_la_lettre_famille(): void
    {
        $this->photo($this->paul, $this->friends);
        $this->photo($this->paul, $this->friends);
        $this->photo($this->julien, $this->family);

        $issue = app(FamilyNewsletterBuilder::class)->build($this->matthieu->fresh());
        $by = $this->sections($issue);

        // Les photos réservées aux amis ne gonflent pas les nouveautés de la famille.
        $this->assertSame(1, $by['new_photos']['count']);
        $this->assertSame(2, $by['friends']['photos']);
        $this->assertSame('La bande à Milou', $by['friends']['circle']);
        $this->assertCount(2, $by['friends']['thumbs']);
        $this->assertStringContainsString('/households/'.$this->friends->id, $by['friends']['url']);

        $html = $this->rendered(new FamilyNewsletterMail($this->matthieu, $issue));
        $this->assertStringContainsString('Côté amis', $html);
        $this->assertStringContainsString('La bande à Milou : 2 nouvelles photos', $html);
    }

    public function test_famille_seule_ou_amis_sans_nouveaute_pas_d_encart(): void
    {
        $this->photo($this->matthieu, $this->family);
        // Nouveauté famille pour Julien et Matthieu ; rien de neuf entre amis.
        $this->photo($this->julien, $this->family);

        $forJulien = app(FamilyNewsletterBuilder::class)->build($this->julien->fresh());
        $forMatthieu = app(FamilyNewsletterBuilder::class)->build($this->matthieu->fresh());

        $this->assertArrayNotHasKey('friends', $this->sections($forJulien)->all());
        $this->assertArrayNotHasKey('friends', $this->sections($forMatthieu)->all());
        $this->assertStringNotContainsString('Côté amis', $this->rendered(new FamilyNewsletterMail($this->matthieu, $forMatthieu)));
    }

    public function test_seuls_les_amis_ont_du_nouveau_la_lettre_famille_part_quand_meme(): void
    {
        $this->photo($this->paul, $this->friends);

        $issue = app(FamilyNewsletterBuilder::class)->build($this->matthieu->fresh());

        $this->assertNotNull($issue);
        $this->assertSame('Cette semaine avec La bande à Milou : 1 nouvelle photo', $issue['subject']);
    }

    // ── Envoi, historique, désabonnement ───────────────────────────────

    public function test_envoi_enregistre_et_varie_d_une_semaine_a_l_autre(): void
    {
        Mail::fake();
        $this->photo($this->paul, $this->friends);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        $send = NewsletterSend::where('user_id', $this->sarah->id)->sole();
        $this->assertSame(FriendsNewsletterBuilder::EDITION, $send->edition);
        $keys = collect($send->sections)->pluck('key');
        $this->assertTrue($keys->contains('new_photos'));
        $this->assertTrue($keys->contains('spotlight'));
        $this->assertTrue($keys->contains('subject'));
        $this->assertStringContainsString('La bande à Milou', $send->subject);

        // Semaine suivante : nouvelle photo, autre découverte et autre tournure d'objet.
        Carbon::setTestNow(now()->addWeek());
        $this->photo($this->paul, $this->friends);
        $next = $this->build($this->sarah);

        $ref = fn (array $log, string $key) => collect($log)->firstWhere('key', $key)['ref'] ?? null;
        $this->assertNotSame($ref($send->sections, 'spotlight'), $ref($next['log'], 'spotlight'));
        $this->assertNotSame($ref($send->sections, 'subject'), $ref($next['log'], 'subject'));
    }

    public function test_relances_sans_rengaine(): void
    {
        $together = $this->photo($this->paul, $this->friends, [$this->sarah->person, $this->paul->person]);
        DetectedFace::create(['media_id' => $together->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20]]);

        $first = $this->build($this->sarah);
        $this->assertArrayHasKey('how_met', $this->sections($first)->all());
        $this->assertArrayHasKey('faces', $this->sections($first)->all());
        NewsletterSend::create(['user_id' => $this->sarah->id, 'edition' => 'friends', 'sections' => $first['log'], 'subject' => $first['subject'], 'sent_at' => now()]);

        // Semaine suivante, du nouveau : ni la même question sur Paul, ni « Qui est-ce ? » deux fois de suite.
        Carbon::setTestNow(now()->addWeek());
        $this->photo($this->paul, $this->friends);
        $second = $this->sections($this->build($this->sarah));

        $this->assertArrayHasKey('new_photos', $second->all());
        $this->assertArrayNotHasKey('how_met', $second->all());
        $this->assertArrayNotHasKey('faces', $second->all());
    }

    public function test_desabonne_ne_recoit_pas_la_lettre_amis(): void
    {
        Mail::fake();
        $this->photo($this->paul, $this->friends);
        $this->sarah->setPreference('newsletter', false);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Mail::assertNotQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->sarah->email));
        $this->assertSame(0, NewsletterSend::where('user_id', $this->sarah->id)->count());
        Notification::assertNotSentTo($this->sarah, WeeklyDigest::class);
    }

    public function test_meme_lien_de_desabonnement_et_page_sans_mot_sur_la_famille(): void
    {
        $url = FriendsNewsletterMail::unsubscribeUrl($this->sarah);
        $this->assertSame(FamilyNewsletterMail::unsubscribeUrl($this->sarah), $url);

        $this->get($url)->assertOk()
            ->assertSee('vos cercles d’amis')
            ->assertDontSee('famille');
        $this->post($url)->assertOk();
        $this->assertFalse($this->sarah->fresh()->wantsNewsletter());
    }

    // ── Langues ────────────────────────────────────────────────────────

    public function test_rendu_en_anglais_et_en_espagnol(): void
    {
        $this->photo($this->paul, $this->friends);
        $this->photo($this->paul, $this->friends, [$this->sarah->person]);

        $this->sarah->setPreference('locale', 'es');
        $es = $this->build($this->sarah);
        $this->assertMatchesRegularExpression('/2 fotos nuevas/', $es['subject']);
        $this->assertStringNotContainsString('nouvelles', $es['subject']);
        $mail = (new FriendsNewsletterMail($this->sarah, $es))->locale('es');
        $mail->assertSeeInHtml('La semana entre amigos');
        $mail->assertSeeInHtml('Hola, Sarah:');
        $mail->assertSeeInHtml('Te identificaron en 1 foto del círculo');
        $mail->assertSeeInText('Esta es tu semana con La bande à Milou.');
        $mail->assertDontSeeInHtml('nouvelles photos');

        $this->sarah->setPreference('locale', 'en');
        $en = $this->build($this->sarah);
        $this->assertStringContainsString('2 new photos', $en['subject']);
        $mail = (new FriendsNewsletterMail($this->sarah, $en))->locale('en');
        $mail->assertSeeInHtml('The week with friends');
        $mail->assertSeeInHtml('You were identified in 1 photo from the circle');
        $mail->assertSeeInText('Here’s your week with La bande à Milou.');
        $mail->assertDontSeeInText('&#039;');
    }

    public function test_encart_cote_amis_traduit(): void
    {
        $this->photo($this->paul, $this->friends);
        $this->matthieu->setPreference('locale', 'es');

        $issue = app(FamilyNewsletterBuilder::class)->build($this->matthieu->fresh());
        $mail = (new FamilyNewsletterMail($this->matthieu, $issue))->locale('es');

        $this->assertSame('Esta semana con La bande à Milou: 1 foto nueva', $issue['subject']);
        $mail->assertSeeInHtml('Con tus amigos');
        $mail->assertSeeInHtml('La bande à Milou: 1 foto nueva');
    }

    // ── Commande ───────────────────────────────────────────────────────

    public function test_apercu_sans_envoi(): void
    {
        Mail::fake();
        $this->photo($this->paul, $this->friends);

        $this->artisan('memorylane:weekly-digest', ['--dry-run' => true])
            ->expectsOutputToContain('[aperçu] Sarah Martin (amis) : ')
            ->expectsOutputToContain('[aperçu] Matthieu Herdegen (famille) : ')
            ->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, NewsletterSend::count());
    }

    public function test_option_user_un_seul_destinataire(): void
    {
        Mail::fake();
        $this->photo($this->paul, $this->friends);

        $this->artisan('memorylane:weekly-digest', ['--user' => $this->sarah->email])->assertSuccessful();

        Mail::assertQueued(FriendsNewsletterMail::class, 1);
        Mail::assertQueued(FriendsNewsletterMail::class, fn ($m) => $m->hasTo($this->sarah->email));
        Mail::assertNotQueued(FamilyNewsletterMail::class);
        $this->assertSame([$this->sarah->id], NewsletterSend::pluck('user_id')->all());
    }
}
