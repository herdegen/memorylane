<?php

namespace Tests\Feature;

use App\Enums\QuestType;
use App\Mail\FamilyNewsletterMail;
use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;
use App\Models\QuestAnswer;
use App\Models\User;
use App\Services\GenealogyService;
use App\Services\Newsletter\FamiliarRelation;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use App\Services\Newsletter\OwnFactsFinder;
use App\Services\PersonChangeReverter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Lettre du dimanche (famille) : relances personnelles — visages à nommer
 * d'un album où l'on apparaît, « raconte-nous la vie de… » selon le lien de
 * parenté, fait de sa fiche saisi par un autre à vérifier — avec leur
 * rotation d'une semaine à l'autre, leur plafond par lettre et leurs langues.
 */
class FamilyNewsletterEncartsTest extends TestCase
{
    use RefreshDatabase;

    private const NUDGES = ['fact_check', 'faces_album', 'timeline'];

    private Household $family;

    private User $me;

    private User $yves;

    private User $julien;

    protected function setUp(): void
    {
        parent::setUp();
        // Dimanche 25 octobre 2026.
        Carbon::setTestNow(Carbon::parse('2026-10-25 18:30', 'Europe/Paris'));

        $this->family = Household::create(['name' => 'Famille', 'kind' => Household::KIND_FAMILY, 'invite_policy' => 'everyone', 'created_by' => User::factory()->create()->id]);
        $this->me = $this->member('Matthieu', 'M', '1988-06-16');
        $this->yves = $this->member('Yves', 'M', '1958-03-03');
        $this->julien = $this->member('Julien', 'M', '1990-01-10');
        // Yves est le père de Matthieu (lien posé hors historique : pas un « fait saisi par un autre »).
        $this->me->person->update(['father_id' => $this->yves->person_id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $first, string $gender, string $birth): User
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => 'Herdegen', 'gender' => $gender, 'birth_date' => $birth, 'birth_place' => null]);
        $user = User::factory()->create(['name' => "{$first} Herdegen", 'email' => strtolower($first).'@example.org', 'person_id' => $person->id]);
        $person->update(['user_id' => $user->id]);
        $this->family->addMember($user, $user);

        return $user;
    }

    private function relative(string $first, string $gender, array $attributes = []): Person
    {
        return Person::factory()->create($attributes + ['first_name' => $first, 'last_name' => 'Herdegen', 'gender' => $gender, 'birth_date' => '1960-01-01', 'user_id' => $this->me->id]);
    }

    /** Une nouvelle photo de Julien cette semaine : la lettre a de quoi partir. */
    private function news(): Media
    {
        $photo = Media::factory()->photo()->create(['user_id' => $this->julien->id, 'is_source' => false, 'uploaded_at' => now()->subDay()]);
        $this->family->media()->attach($photo->id, ['added_by' => $this->julien->id]);

        return $photo;
    }

    /** Frise déjà remplie (deux moments) : plus de « raconte-nous sa vie ». */
    private function fullTimeline(Person $person): void
    {
        foreach (['2000-01-01', '2010-01-01'] as $date) {
            LifeEvent::create(['person_id' => $person->id, 'user_id' => $this->me->id, 'type' => 'moment', 'title' => 'Moment', 'event_date' => $date]);
        }
    }

    /**
     * Album « Mariage de Julien » partagé avec la famille, Matthieu identifié
     * sur la première photo, $faces visages sans nom répartis sur les photos.
     */
    private function album(int $faces, ?Household $circle = null, string $name = 'Mariage de Julien', bool $withMe = true): Album
    {
        $circle ??= $this->family;
        $album = Album::create(['user_id' => $this->julien->id, 'name' => $name]);
        $album->households()->attach($circle->id, ['granted_by' => $this->julien->id]);
        for ($i = 0; $i < 2; $i++) {
            $media = Media::factory()->photo()->create(['user_id' => $this->julien->id, 'is_source' => false, 'uploaded_at' => now()->subMonth()]);
            $album->media()->attach($media->id);
            if ($i === 0 && $withMe) {
                $media->people()->attach($this->me->person_id);
            }
        }
        $photos = $album->media()->get();
        for ($i = 0; $i < $faces; $i++) {
            $this->face($photos[$i % 2]);
        }

        return $album;
    }

    private function face(Media $media, float $width = 20): DetectedFace
    {
        return DetectedFace::create(['media_id' => $media->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => $width, 'height' => $width]]);
    }

    private function build(?User $user = null): ?array
    {
        return app(FamilyNewsletterBuilder::class)->build(($user ?? $this->me)->fresh());
    }

    private function sections(?array $issue): Collection
    {
        return collect($issue['sections'] ?? [])->keyBy('key');
    }

    private function record(array $issue, ?User $user = null): void
    {
        NewsletterSend::create(['user_id' => ($user ?? $this->me)->id, 'edition' => 'family', 'sections' => $issue['log'], 'subject' => $issue['subject'], 'sent_at' => now()]);
    }

    /** Fait les modifications au nom de ce compte (historique person_changes). */
    private function as(User $user, callable $edit): void
    {
        Auth::login($user);
        $edit();
        Auth::logout();
        Carbon::setTestNow(now()->addMinutes(5));
    }

    private function facts(): array
    {
        return array_column(app(OwnFactsFinder::class)->for($this->me->fresh()), 'text');
    }

    // ── Visages d'un album ─────────────────────────────────────────────

    public function test_encart_visages_d_un_album_de_la_famille_ou_l_on_apparait(): void
    {
        $this->fullTimeline($this->me->person);
        $this->fullTimeline($this->yves->person);
        $album = $this->album(3);
        $this->news();

        $issue = $this->build();
        $faces = $this->sections($issue)['faces_album'];

        $this->assertSame($album->id, $faces['ref']);
        $this->assertSame(3, $faces['count']);
        $this->assertSame('Mariage de Julien', $faces['album']);
        $this->assertStringEndsWith('/quests/faces?album='.$album->id, $faces['url']);
        // La découverte ne refait pas la promotion du jeu déjà relancé.
        $this->assertNotSame('qui_est_ce', $this->sections($issue)['spotlight']['ref']);

        $mail = new FamilyNewsletterMail($this->me, $issue);
        $mail->assertSeeInHtml('Il y a 3 visages non identifiés dans l’album « Mariage de Julien », où tu apparais.', false);
        $mail->assertSeeInHtml('Identifier les visages');
        $mail->assertSeeInText('Aide-nous à les identifier :');
        $mail->assertSeeInText('/quests/faces?album='.$album->id);
    }

    public function test_pas_d_encart_visages_sous_le_seuil_ni_hors_de_ses_albums(): void
    {
        $this->news();
        // Deux visages seulement.
        $album = $this->album(2);
        $this->assertArrayNotHasKey('faces_album', $this->sections($this->build())->all());

        // Un troisième… dit « inconnu » dans la famille : ne compte pas.
        $photo = $album->media()->first();
        $stranger = $this->face($photo);
        QuestAnswer::create([
            'user_id' => $this->julien->id, 'household_id' => $this->family->id,
            'question_type' => QuestType::FaceIdentify->value, 'question_key' => QuestType::FaceIdentify->key($stranger->id),
            'subject_type' => $stranger->getMorphClass(), 'subject_id' => $stranger->id,
            'answer_kind' => 'no', 'payload' => ['reason' => 'stranger'],
        ]);
        // … ni un visage d'arrière-plan (minuscule).
        $this->face($photo, 1);
        $this->assertArrayNotHasKey('faces_album', $this->sections($this->build())->all());

        // Album plein de visages, mais Matthieu n'y apparaît pas.
        $this->album(6, name: 'Vacances sans moi', withMe: false);
        $this->assertArrayNotHasKey('faces_album', $this->sections($this->build())->all());

        // Le troisième vrai visage : l'encart apparaît.
        $this->face($photo);
        $this->assertSame($album->id, $this->sections($this->build())['faces_album']['ref'] ?? null);
    }

    public function test_pas_d_album_d_amis_dans_la_lettre_famille(): void
    {
        $friends = Household::create(['name' => 'La bande à Milou', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => 'admin', 'created_by' => $this->me->id]);
        $friends->addMember($this->me, $this->me);
        $friends->addMember($this->julien, $this->me);
        $this->album(5, $friends, 'Week-end à Milou');
        $this->news();

        $issue = $this->build();

        $this->assertArrayNotHasKey('faces_album', $this->sections($issue)->all());
        $this->assertStringNotContainsString('Week-end à Milou', json_encode($issue, JSON_UNESCAPED_UNICODE));
    }

    public function test_le_meme_album_ne_revient_pas_la_semaine_suivante(): void
    {
        $this->fullTimeline($this->me->person);
        $this->fullTimeline($this->yves->person);
        $first = $this->album(3, name: 'Mariage de Julien');
        $second = $this->album(4, name: 'Noël 2019');
        $this->news();

        $week1 = $this->build();
        $this->record($week1);
        Carbon::setTestNow(now()->addWeek());
        $this->news();
        $week2 = $this->build();
        $this->record($week2);
        Carbon::setTestNow(now()->addWeek());
        $this->news();
        $week3 = $this->build();

        $refs = [$this->sections($week1)['faces_album']['ref'], $this->sections($week2)['faces_album']['ref']];
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $refs, 'deux semaines, deux albums');
        $this->assertArrayNotHasKey('faces_album', $this->sections($week3)->all(), 'les deux albums sont au repos');
    }

    // ── Frise de vie ───────────────────────────────────────────────────

    public function test_formulation_priorite_et_rotation_de_la_frise(): void
    {
        $anne = $this->relative('Anne', 'F', ['first_name' => 'Anne', 'last_name' => 'Martin']);
        $this->me->person->update(['mother_id' => $anne->id]);
        $this->relative('Léa', 'F', ['birth_date' => '2008-04-02', 'father_id' => $this->me->person_id]);
        $this->relative('Claire', 'F', ['father_id' => $this->yves->person_id, 'birth_date' => '1985-02-02']);
        $jean = $this->relative('Jean', 'M', ['birth_date' => '1930-05-05']);
        $this->yves->person->update(['father_id' => $jean->id]);

        $seen = [];
        for ($week = 0; $week < 7; $week++) {
            $this->news();
            $issue = $this->build();
            $timeline = $this->sections($issue)['timeline'] ?? null;
            $seen[] = $timeline ? [$timeline['relation'], $timeline['title']] : null;
            $this->assertStringEndsWith('#frise', $timeline['url'] ?? '#frise');
            $this->record($issue);
            Carbon::setTestNow(now()->addWeek());
        }

        // Soi d'abord, puis les proches du premier degré (dans un ordre tiré au sort), puis le grand-père.
        $this->assertSame(['self', 'Parle-nous un peu de toi'], $seen[0]);
        $this->assertEqualsCanonicalizing([
            ['father', 'Raconte-nous la vie de ton père'],
            ['mother', 'Raconte-nous la vie de ta mère'],
            ['child', 'Raconte-nous la vie de ta fille Léa'],
            ['sibling', 'Raconte-nous la vie de ta sœur Claire'],
        ], array_slice($seen, 1, 4));
        $this->assertSame(['grandfather', 'Raconte-nous la vie de ton grand-père Jean'], $seen[5]);
        // Personne n'est repris avant son tour (8 envois).
        $this->assertNotContains($seen[6][0] ?? null, ['self', 'father', 'mother', 'child', 'sibling', 'grandfather']);
    }

    // ── Faits saisis par d'autres ──────────────────────────────────────

    public function test_fait_saisi_par_un_autre_mais_ni_par_soi_ni_annule(): void
    {
        $person = $this->me->person;

        // Ce que Matthieu a lui-même saisi (ou la fiche qu'il a créée) : jamais.
        $this->assertSame([], $this->facts());
        $this->as($this->me, fn () => $person->update(['maiden_name' => 'Dupont']));
        $this->assertSame([], $this->facts());

        // Yves indique le lieu de naissance.
        $this->as($this->yves, fn () => $person->update(['birth_place' => 'Lyon, Rhône']));
        $this->assertSame(['Yves a indiqué que tu es né à Lyon : c’est bien ça ?'], $this->facts());

        // Matthieu le corrige lui-même : plus rien à vérifier.
        $this->as($this->me, fn () => $person->update(['birth_place' => 'Mennecy']));
        $this->assertSame([], $this->facts());

        // Julien le change, puis l'administrateur annule : la valeur rétablie
        // est celle de Matthieu, rien à vérifier.
        $this->as($this->julien, fn () => $person->update(['birth_place' => 'Paris']));
        $this->assertSame(['Julien a indiqué que tu es né à Paris : c’est bien ça ?'], $this->facts());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->as($admin, fn () => app(PersonChangeReverter::class)->revert(
            PersonChange::where('person_id', $person->id)->where('user_id', $this->julien->id)->sole(), $admin));
        $this->assertSame('Mennecy', $person->fresh()->birth_place);
        $this->assertSame([], $this->facts());

        // Une union ajoutée par Yves puis annulée : rien non plus.
        $anne = $this->relative('Anne', 'F');
        $this->as($this->yves, fn () => PersonRelationship::create(['person1_id' => min($person->id, $anne->id), 'person2_id' => max($person->id, $anne->id), 'type' => 'spouse', 'start_date' => '2015-06-12']));
        $this->assertCount(1, $this->facts());
        $this->as($admin, fn () => app(PersonChangeReverter::class)->revert(
            PersonChange::where('person_id', $person->id)->where('action', 'spouse_added')->sole(), $admin));
        $this->assertSame([], $this->facts());
    }

    public function test_faits_possibles_et_formulation(): void
    {
        $person = $this->me->person;
        $anne = $this->relative('Anne', 'F', ['last_name' => 'Martin']);
        $claire = $this->relative('Claire', 'F', ['last_name' => 'Durand']);
        $julienPerson = $this->julien->person;

        // Naissance (date et lieu par Yves), union datée et située, parents, métier.
        $this->as($this->yves, fn () => $person->update(['birth_date' => '1988-03-03', 'birth_place' => 'Lyon']));
        $this->as($this->yves, fn () => PersonRelationship::create(['person1_id' => min($person->id, $anne->id), 'person2_id' => max($person->id, $anne->id), 'type' => 'spouse', 'start_date' => '2015-06-12', 'start_place' => 'Évian-les-Bains, Haute-Savoie']));
        $this->as($this->yves, fn () => $person->update(['mother_id' => $claire->id]));
        $this->as($this->yves, fn () => LifeEvent::create(['person_id' => $person->id, 'user_id' => $this->yves->id, 'type' => 'job', 'title' => 'Ingénieur', 'event_date' => '2012-01-01']));
        // Ce que Matthieu a ajouté à sa propre frise : jamais.
        LifeEvent::create(['person_id' => $person->id, 'user_id' => $this->me->id, 'type' => 'education', 'title' => 'Lycée Hoche', 'event_date' => '2003-01-01']);
        // Union répondue à une quête par Julien : « marié en 2020 », sans date exacte.
        $this->as($this->julien, function () use ($person, $julienPerson) {
            PersonRelationship::create(['person1_id' => min($person->id, $julienPerson->id), 'person2_id' => max($person->id, $julienPerson->id), 'type' => 'partner']);
            QuestAnswer::create([
                'user_id' => $this->julien->id, 'question_type' => QuestType::MaritalStatus->value,
                'question_key' => QuestType::MaritalStatus->key($person->id), 'subject_type' => $person->getMorphClass(), 'subject_id' => $person->id,
                'answer_kind' => 'answered', 'payload' => ['spouse_id' => $julienPerson->id, 'type' => 'partner', 'year' => 2020],
            ]);
        });

        $this->assertSame([
            'Julien a indiqué que ta vie avec Julien Herdegen a commencé en 2020 : c’est bien ça ?',
            'Yves a ajouté à ta frise de vie « Ingénieur » (métier, 2012) : c’est bien ça ?',
            'Yves a indiqué que ta mère est Claire Durand : c’est bien ça ?',
            'Yves a indiqué que tu t’es marié avec Anne Martin à Évian-les-Bains le 12 juin 2015 : c’est bien ça ?',
            'Yves a indiqué que tu es né à Lyon le 3 mars 1988 : c’est bien ça ?',
        ], $this->facts());

        // Accords : au féminin, et sans genre connu.
        $person->update(['gender' => 'F']);
        $this->assertContains('Yves a indiqué que tu t’es mariée avec Anne Martin à Évian-les-Bains le 12 juin 2015 : c’est bien ça ?', $this->facts());
        $this->assertContains('Yves a indiqué que tu es née à Lyon le 3 mars 1988 : c’est bien ça ?', $this->facts());
        $person->update(['gender' => 'U']);
        $this->assertContains('Yves a indiqué que tu es né(e) à Lyon le 3 mars 1988 : c’est bien ça ?', $this->facts());
    }

    public function test_auteur_hors_de_la_famille_ou_fiche_invisible(): void
    {
        $person = $this->me->person;
        // Un ami (hors cercle familial) a indiqué le nom de naissance : on ne le nomme pas.
        $sarah = User::factory()->create(['name' => 'Sarah Martin']);
        $this->as($sarah, fn () => $person->update(['maiden_name' => 'Dupont']));
        $this->assertSame(['On a indiqué que ton nom de naissance est Dupont : c’est bien ça ?'], $this->facts());

        // Une conjointe dont la fiche lui est invisible (fiche d'un cercle d'amis) : pas citée.
        $hidden = Person::factory()->create(['first_name' => 'Zoé', 'last_name' => 'Secret', 'in_family_directory' => false, 'user_id' => $sarah->id]);
        $this->as($this->yves, fn () => PersonRelationship::create(['person1_id' => min($person->id, $hidden->id), 'person2_id' => max($person->id, $hidden->id), 'type' => 'spouse', 'start_date' => '2001-01-01']));
        $this->assertStringNotContainsString('Zoé', implode(' ', $this->facts()));
    }

    public function test_un_seul_fait_par_lettre_rotation_et_deux_fois_au_plus(): void
    {
        $this->fullTimeline($this->me->person);
        $this->fullTimeline($this->yves->person);
        $person = $this->me->person;
        $this->as($this->yves, fn () => $person->update(['birth_place' => 'Lyon']));
        $this->as($this->julien, fn () => $person->update(['maiden_name' => 'Dupont']));

        $texts = [];
        $issues = [];
        for ($week = 0; $week < 20; $week++) {
            $this->news();
            $issue = $this->build();
            $facts = collect($issue['sections'])->where('key', 'fact_check');
            $this->assertLessThanOrEqual(1, $facts->count(), 'un fait par lettre au plus');
            $texts[] = $facts->first()['text'] ?? null;
            $issues[] = $issue;
            $this->record($issue);
            Carbon::setTestNow(now()->addWeek());
        }

        // Le plus récent d'abord, puis l'autre ; ensuite les deux sont au
        // repos pendant 8 envois, reviennent chacun une fois, puis plus
        // jamais (deux apparitions au plus).
        $julien = 'Julien a indiqué que ton nom de naissance est Dupont : c’est bien ça ?';
        $yves = 'Yves a indiqué que tu es né à Lyon : c’est bien ça ?';
        $this->assertSame([
            $julien, $yves, null, null, null, null, null, null, null,
            $julien, $yves, null, null, null, null, null, null, null, null, null,
        ], $texts);
        $this->assertSame(2, collect($texts)->filter(fn ($t) => $t === $julien)->count());

        // Le lien mène à sa fiche, rendu dans la lettre.
        $fact = $this->sections($issues[9])['fact_check'];
        $this->assertStringEndsWith('/people/'.$this->me->person_id, $fact['url']);
        $mail = new FamilyNewsletterMail($this->me, $issues[9]);
        $mail->assertSeeInHtml('Petite vérification');
        $mail->assertSeeInHtml('Vérifier sur ma fiche');
        $mail->assertSeeInHtml('Si quelque chose cloche, tu peux le corriger toi-même sur ta fiche.');
        $mail->assertSeeInText($julien);
        $mail->assertSeeInText('Vérifier sur ma fiche :');
    }

    // ── Tutoiement ─────────────────────────────────────────────────────

    public function test_lien_de_parente_tutoye_pour_les_lettres(): void
    {
        $label = fn (array $edges, ?string $gender) => FamiliarRelation::label(GenealogyService::relationLabel($edges, $gender), $gender);

        $this->assertSame('ta mère', $label(['parent'], 'F'));
        $this->assertSame('ton père', $label(['parent'], 'M'));
        $this->assertSame('ta grand-mère', $label(['parent', 'parent'], 'F'));
        $this->assertSame('ton arrière-grand-père', $label(['parent', 'parent', 'parent'], 'M'));
        // « ton » devant une voyelle, même au féminin.
        $this->assertSame('ton arrière-grand-mère', $label(['parent', 'parent', 'parent'], 'F'));
        $this->assertSame('ta tante', $label(['parent', 'parent', 'child'], 'F'));
        $this->assertSame('ton oncle par alliance', $label(['spouse', 'parent', 'parent', 'child'], 'M'));
        $this->assertSame('ta belle-sœur', $label(['parent', 'child', 'spouse'], 'F'));
        $this->assertSame('ta cousine germaine', $label(['parent', 'parent', 'child', 'child'], 'F'));
        // Genre inconnu : masculin, comme le libellé.
        $this->assertSame('ton frère', $label(['parent', 'child'], null));
        $this->assertSame('tes cousins', FamiliarRelation::label('vos cousins', null));
        $this->assertNull(FamiliarRelation::label(null, 'F'));
        // Le reste du site garde le vouvoiement ; l'anglais et l'espagnol passent tels quels.
        $this->assertSame('votre grand-mère', GenealogyService::relationLabel(['parent', 'parent'], 'F'));
        $this->assertSame('your grandmother', $this->inLocale('en', fn () => $label(['parent', 'parent'], 'F')));
        $this->assertSame('tu abuela', $this->inLocale('es', fn () => $label(['parent', 'parent'], 'F')));
    }

    public function test_la_lettre_tutoie(): void
    {
        // Grand-mère à l'honneur, photo où Matthieu est reconnu cette semaine.
        $jeanne = Person::factory()->create(['first_name' => 'Jeanne', 'last_name' => 'Herdegen', 'gender' => 'F', 'birth_date' => '1931-01-01', 'portrait_path' => 'portraits/jeanne.jpg', 'user_id' => $this->me->id]);
        $this->yves->person->update(['mother_id' => $jeanne->id]);
        for ($i = 0; $i < 3; $i++) {
            $media = Media::factory()->photo()->create(['user_id' => $this->julien->id, 'is_source' => false, 'uploaded_at' => now()->subMonth()]);
            $media->people()->attach($i === 0 ? [$jeanne->id, $this->me->person_id] : [$jeanne->id]);
            $this->family->media()->attach($media->id, ['added_by' => $this->julien->id]);
        }

        $issue = $this->build();
        $by = $this->sections($issue);
        $this->assertSame('ta grand-mère', $by['person_of_week']['relation']);
        $this->assertSame('Tu apparais sur une nouvelle photo.', $issue['preheader']);

        $mail = new FamilyNewsletterMail($this->me, $issue);
        $mail->assertSeeInHtml('Ta grand-mère');
        $mail->assertSeeInHtml('Tu es dessus');
        $mail->assertSeeInHtml('Te voilà sur 1 photo');
        $mail->assertSeeInHtml('3 photos dans tes souvenirs. Prends un moment');
        $mail->assertSeeInHtml('Tu reçois ce message chaque dimanche parce que tu fais partie de la famille sur MemoryLane.');
        $mail->assertSeeInText('La personne de la semaine : Jeanne Herdegen, ta grand-mère (née en 1931).');
        $mail->assertSeeInText('Te voilà sur 1 photo : ');
        // Plus aucun vouvoiement (« votre union » désigne le couple : il reste au pluriel).
        $all = $mail->render()."\n".view($mail->content()->text, $mail->content()->with)->render();
        $this->assertDoesNotMatchRegularExpression('/\b(vous|votre|vos)\b/iu', str_replace('votre union', '', $all));
    }

    // ── Plafond et alternance ──────────────────────────────────────────

    public function test_au_plus_deux_relances_en_alternance(): void
    {
        $person = $this->me->person;
        $this->as($this->yves, fn () => $person->update(['birth_place' => 'Lyon']));
        $this->as($this->julien, fn () => $person->update(['maiden_name' => 'Dupont']));
        $this->album(3, name: 'Mariage de Julien');
        $this->album(3, name: 'Noël 2019');

        $weeks = [];
        for ($week = 0; $week < 3; $week++) {
            $this->news();
            $issue = $this->build();
            $keys = collect($issue['sections'])->pluck('key');
            $nudges = $keys->intersect(self::NUDGES)->values()->all();
            $this->assertLessThanOrEqual(2, count($nudges));
            $this->assertLessThanOrEqual(3, $keys->intersect([...self::NUDGES, 'tree_links', 'own_circle', 'quests'])->count());
            $weeks[] = $nudges;
            $this->record($issue);
            Carbon::setTestNow(now()->addWeek());
        }

        // Les trois relances sont possibles chaque semaine ; deux paraissent,
        // et celle qui manquait passe en tête la semaine suivante.
        $this->assertSame(['fact_check', 'faces_album'], $weeks[0]);
        $this->assertSame('timeline', $weeks[1][0]);
        $this->assertCount(2, $weeks[1]);
        $this->assertNotSame($weeks[1], $weeks[2]);
    }

    public function test_relances_seules_ne_font_pas_partir_la_lettre(): void
    {
        $this->as($this->yves, fn () => $this->me->person->update(['birth_place' => 'Lyon']));
        $this->album(4);
        // Une semaine plus tard, rien de neuf ni personne à l'honneur.
        Carbon::setTestNow(now()->addDays(8));

        $this->assertNull($this->build());
    }

    // ── Langues ────────────────────────────────────────────────────────

    public function test_encarts_en_espagnol_et_en_anglais(): void
    {
        $person = $this->me->person;
        $this->as($this->yves, fn () => $person->update(['birth_date' => '1988-03-01', 'birth_place' => 'Lyon']));
        $this->album(3);
        $this->news();

        $this->me->setPreference('locale', 'es');
        $es = $this->build();
        $this->assertSame(['fact_check', 'faces_album'], collect($es['sections'])->pluck('key')->intersect(self::NUDGES)->values()->all());
        $mail = (new FamilyNewsletterMail($this->me, $es))->locale('es');
        $mail->assertSeeInHtml('Yves indicó que naciste en Lyon el 1 de marzo de 1988. ¿Es correcto?');
        $mail->assertSeeInHtml('Hay 3 caras sin identificar en el álbum «Mariage de Julien», donde apareces.');
        $mail->assertSeeInHtml('Revisar en mi ficha');
        $mail->assertSeeInText('Ayúdanos a identificarlas:');
        $mail->assertDontSeeInHtml('visages');

        // Anglais, une autre semaine : la frise passe en tête (absente la semaine d'avant).
        $this->record($es);
        Carbon::setTestNow(now()->addWeek());
        $this->news();
        $this->me->setPreference('locale', 'en');
        $en = $this->build();
        $by = $this->sections($en);
        $this->assertSame('Tell us a little about yourself', $by['timeline']['title']);
        $mail = (new FamilyNewsletterMail($this->me, $en))->locale('en');
        $mail->assertSeeInHtml('Your timeline is still almost empty. Add your schools and qualifications, your jobs and the homes you have lived in: your loved ones will see them on your profile.');
        $mail->assertSeeInText('Tell us a little about yourself');
        $mail->assertDontSeeInText('&#039;');

        // Le fait en anglais (date à l'anglaise) et en français (« 1er mars ») ;
        // la fiche du père en espagnol.
        $this->assertSame(['Yves noted that you were born in Lyon on 1 March 1988. Is that right?'], $this->inLocale('en', fn () => $this->facts()));
        $this->assertSame(['Yves a indiqué que tu es né à Lyon le 1er mars 1988 : c’est bien ça ?'], $this->inLocale('fr', fn () => $this->facts()));
        $this->fullTimeline($person);
        $this->me->setPreference('locale', 'es');
        Carbon::setTestNow(now()->addWeek());
        $this->news();
        $timeline = $this->sections($this->build())['timeline'] ?? null;
        $this->assertSame('Cuéntanos la vida de tu padre', $timeline['title'] ?? null);
    }

    private function inLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
