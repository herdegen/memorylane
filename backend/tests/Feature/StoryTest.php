<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\Story;
use App\Models\User;
use App\Services\GenealogyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Histoires de famille : écrire, publier, lire selon la fiche principale,
 * documents filtrés selon ce que voit le lecteur, ajouts de la famille ;
 * et autour : liens lointains, décès d'un enfant sur la frise, transcription.
 */
class StoryTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;   // autrice
    private User $bob;     // même famille
    private User $carol;   // autre famille
    private Household $family;
    private Person $gaspard;
    private Person $catherine;

    protected function setUp(): void
    {
        parent::setUp();
        // Les pages vérifiées ici ne dépendent pas du front compilé (test.sh
        // compile après les tests).
        $this->withoutVite();
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->carol = User::factory()->create(['name' => 'Carole']);
        $this->family = Household::factory()->create(['created_by' => $this->alice->id]);
        $this->family->members()->attach([$this->alice->id, $this->bob->id]);
        $other = Household::factory()->create(['created_by' => $this->carol->id]);
        $other->members()->attach($this->carol->id);

        $this->catherine = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Catherine KIEFFER', 'gender' => 'F']);
        $this->gaspard = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Gaspard KIEFFER', 'gender' => 'M', 'mother_id' => $this->catherine->id]);
        // Les fiches rejoignent d'elles-mêmes le cercle de leur auteur.
        $this->family->people()->syncWithoutDetaching([$this->catherine->id, $this->gaspard->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'L’affaire Gaspard Kieffer',
            'summary' => 'En janvier 1890…',
            'kind' => 'documented',
            'credits' => 'Recherches : Annick',
            'sensitive' => true,
            'disagreements' => "Son âge : 20 ou 23 ans\n- Le domicile",
            'publish' => true,
            'people' => [
                ['id' => $this->gaspard->id, 'is_main' => true, 'role' => null],
                ['id' => $this->catherine->id, 'is_main' => false, 'role' => 'sa mère'],
            ],
            'chapters' => [
                ['title' => 'La nuit du 4 janvier', 'when_label' => 'Janvier 1890', 'blocks' => [
                    ['type' => 'text', 'text' => 'Le soir, Gaspard et son frère…'],
                    ['type' => 'quote', 'text' => 'Ferme ton couteau, Gaspard !', 'cite' => 'Le voisin'],
                    ['type' => 'text', 'text' => '   '],
                ]],
                ['title' => 'Charles Foujol', 'when_label' => '1922', 'blocks' => []],
            ],
            'questions' => [['id' => null, 'text' => 'Où et quand est-il mort ?']],
        ], $overrides);
    }

    public function test_ecrire_publier_et_lire_une_histoire(): void
    {
        $this->actingAs($this->alice)->post('/stories', $this->payload())->assertRedirect();
        $story = Story::firstOrFail();

        $this->assertNotNull($story->published_at);
        $this->assertSame($this->gaspard->id, $story->load('people')->mainPerson()->id);
        $this->assertCount(2, $story->chapters);
        $this->assertCount(2, $story->chapters[0]->blocks, 'paragraphe vide retiré');

        $this->actingAs($this->bob)->get("/stories/{$story->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Stories/Show', false)
                ->where('story.title', 'L’affaire Gaspard Kieffer')
                ->where('story.period', '1890 – 1922')
                ->where('story.disagreements', ['Son âge : 20 ou 23 ans', 'Le domicile'])
                ->where('story.people.1.role', 'sa mère')
                ->where('story.chapters.0.blocks.1.cite', 'Le voisin')
                ->where('canEdit', false));

        // Sur la fiche de chaque personne, dans la bibliothèque.
        $this->actingAs($this->bob)->get("/people/{$this->catherine->id}")
            ->assertInertia(fn ($page) => $page->where('stories.0.title', 'L’affaire Gaspard Kieffer')->where('stories.0.role', 'sa mère'));
        $this->actingAs($this->bob)->get('/stories')
            ->assertInertia(fn ($page) => $page->has('stories', 1));
    }

    public function test_une_autre_famille_ne_lit_pas_l_histoire(): void
    {
        $this->actingAs($this->alice)->post('/stories', $this->payload());
        $story = Story::firstOrFail();

        $this->actingAs($this->carol)->get("/stories/{$story->id}")->assertNotFound();
        $this->actingAs($this->carol)->get('/stories')->assertInertia(fn ($page) => $page->has('stories', 0));
        $this->actingAs($this->carol)->post("/stories/{$story->id}/notes", ['body' => 'Coucou'])->assertNotFound();
    }

    public function test_un_brouillon_reste_a_son_auteur(): void
    {
        $this->actingAs($this->alice)->post('/stories', $this->payload(['publish' => false]));
        $story = Story::firstOrFail();

        $this->assertNull($story->published_at);
        $this->actingAs($this->alice)->get("/stories/{$story->id}")->assertOk();
        $this->actingAs($this->bob)->get("/stories/{$story->id}")->assertNotFound();
    }

    public function test_seuls_l_auteur_et_l_admin_modifient(): void
    {
        $this->actingAs($this->alice)->post('/stories', $this->payload());
        $story = Story::firstOrFail();
        $question = $story->questions()->first();

        $this->actingAs($this->bob)->get("/stories/{$story->id}/edit")->assertForbidden();
        $this->actingAs($this->bob)->put("/stories/{$story->id}", $this->payload(['title' => 'Piraté']))->assertForbidden();
        $this->actingAs($this->bob)->delete("/stories/{$story->id}")->assertForbidden();

        // La question gardée par son identifiant (les réponses y restent attachées).
        $this->actingAs($this->alice)->put("/stories/{$story->id}", $this->payload([
            'title' => 'L’affaire Kieffer',
            'questions' => [['id' => $question->id, 'text' => 'Où est-il mort ?'], ['id' => null, 'text' => 'Et sa fille ?']],
        ]))->assertRedirect("/stories/{$story->id}");
        $story->refresh();
        $this->assertSame('L’affaire Kieffer', $story->title);
        $this->assertSame(['Où est-il mort ?', 'Et sa fille ?'], $story->questions()->pluck('text')->all());
        $this->assertSame($question->id, $story->questions()->first()->id);

        $this->actingAs($this->alice)->delete("/stories/{$story->id}")->assertRedirect('/stories');
        $this->assertSoftDeleted($story);
    }

    public function test_personnes_et_documents_limites_a_ce_que_voit_l_auteur(): void
    {
        $stranger = Person::factory()->create(['user_id' => $this->carol->id, 'name' => 'Inconnu']);
        $this->actingAs($this->alice)->post('/stories', $this->payload([
            'people' => [['id' => $stranger->id, 'is_main' => true]],
        ]))->assertStatus(422);

        $hidden = Media::factory()->create(['user_id' => $this->carol->id, 'type' => 'photo']);
        $this->actingAs($this->alice)->post('/stories', $this->payload([
            'chapters' => [['title' => 'X', 'blocks' => [['type' => 'media', 'media_id' => $hidden->id]]]],
        ]))->assertStatus(422);
        $this->assertSame(0, Story::count());
    }

    public function test_les_documents_que_le_lecteur_ne_voit_pas_sont_retires(): void
    {
        $shared = Media::factory()->create(['user_id' => $this->alice->id, 'type' => 'photo', 'transcription' => 'Le crime de la rue de la Folie-Regnault']);
        $shared->households()->attach($this->family->id, ['added_by' => $this->alice->id]);
        $private = Media::factory()->create(['user_id' => $this->alice->id, 'type' => 'photo']);

        $this->actingAs($this->alice)->post('/stories', $this->payload([
            'chapters' => [['title' => 'Documents', 'blocks' => [
                ['type' => 'media', 'media_id' => $shared->id, 'caption' => 'Coupure de presse'],
                ['type' => 'media', 'media_id' => $private->id],
            ]]],
        ]));
        $story = Story::firstOrFail();

        $this->actingAs($this->bob)->get("/stories/{$story->id}")
            ->assertInertia(fn ($page) => $page->has('story.chapters.0.blocks', 1)
                ->where('story.chapters.0.blocks.0.media.transcription', 'Le crime de la rue de la Folie-Regnault')
                ->where('story.documents_count', 1));
        $this->actingAs($this->alice)->get("/stories/{$story->id}")
            ->assertInertia(fn ($page) => $page->has('story.chapters.0.blocks', 2));
    }

    public function test_je_sais_quelque_chose(): void
    {
        $this->actingAs($this->alice)->post('/stories', $this->payload());
        $story = Story::firstOrFail();
        $question = $story->questions()->first();

        $this->actingAs($this->bob)->post("/stories/{$story->id}/notes", ['body' => 'Mort à Arima vers 1930, selon tante Jeanne.', 'question_id' => $question->id])->assertRedirect();
        $this->actingAs($this->bob)->post("/stories/{$story->id}/notes", ['body' => 'Il y a une photo chez moi.'])->assertRedirect();
        $note = $story->notes()->whereNull('question_id')->firstOrFail();

        $this->actingAs($this->alice)->get("/stories/{$story->id}")
            ->assertInertia(fn ($page) => $page->where('story.questions.0.notes.0.author', 'Bob')
                ->where('story.notes.0.body', 'Il y a une photo chez moi.')
                ->where('story.notes.0.can_delete', true));

        // Question d'une autre histoire : refusée.
        $other = Story::create(['user_id' => $this->alice->id, 'title' => 'Autre', 'kind' => 'memory', 'published_at' => now()]);
        $other->people()->attach($this->gaspard->id, ['is_main' => true]);
        $foreign = $other->questions()->create(['text' => '?']);
        $this->actingAs($this->bob)->post("/stories/{$story->id}/notes", ['body' => 'x', 'question_id' => $foreign->id])->assertSessionHasErrors('question_id');

        // Retirer : son auteur ou celui de l'histoire, pas un autre lecteur.
        $dave = User::factory()->create();
        $this->family->members()->attach($dave->id);
        $this->actingAs($dave)->delete("/story-notes/{$note->id}")->assertForbidden();
        $this->actingAs($this->alice)->delete("/story-notes/{$note->id}")->assertRedirect();
        $this->assertModelMissing($note);
    }

    public function test_raconter_l_histoire_d_un_album(): void
    {
        $album = \App\Models\Album::factory()->create(['user_id' => $this->alice->id, 'name' => 'L’affaire Kieffer (1890)']);
        $doc = Media::factory()->create(['user_id' => $this->alice->id, 'type' => 'photo']);
        $album->media()->attach($doc->id);
        $doc->people()->attach($this->gaspard->id);

        $this->actingAs($this->alice)->get("/stories/create?album={$album->id}")
            ->assertInertia(fn ($page) => $page->component('Stories/Edit', false)
                ->where('story.title', 'L’affaire Kieffer (1890)')
                ->where('story.album_id', $album->id)
                ->where('story.people.0.id', $this->gaspard->id)
                ->where('story.chapters.0.blocks.0.media_id', $doc->id));

        $this->actingAs($this->alice)->get("/albums/{$album->id}")
            ->assertInertia(fn ($page) => $page->where('story', null));
    }

    public function test_liens_lointains(): void
    {
        // Moi → Christine → Marie-Anne → Anne → Thadée ; Gaspard est le frère de Thadée.
        $parentOfThadee = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'F']);
        $thadee = Person::factory()->create(['user_id' => $this->alice->id, 'first_name' => 'Thadée', 'name' => 'Thadée KIEFFER', 'gender' => 'M', 'mother_id' => $parentOfThadee->id]);
        $gaspard = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Gaspard', 'gender' => 'M', 'mother_id' => $parentOfThadee->id]);
        $anne = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'F', 'father_id' => $thadee->id]);
        $marie = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'F', 'mother_id' => $anne->id]);
        $christine = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'F', 'mother_id' => $marie->id]);
        $me = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'M', 'mother_id' => $christine->id]);

        $label = function (Person $to) use ($me) {
            $path = app(GenealogyService::class)->pathBetween($me->id, $to->id);

            return GenealogyService::relationLabel($path['edges'], $to->gender)
                ?? GenealogyService::distantRelationLabel($path, Person::whereIn('id', $path['ids'])->get()->keyBy('id')->all());
        };

        $this->assertSame('votre trisaïeul', $label($thadee));
        $this->assertSame('votre quadrisaïeule', $label($parentOfThadee));
        $this->assertSame('frère de Thadée, votre trisaïeul', $label($gaspard));
        $petit = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'M', 'father_id' => $gaspard->id]);
        $this->assertSame('neveu de Thadée, votre trisaïeul', $label($petit));
        $arriere = Person::factory()->create(['user_id' => $this->alice->id, 'gender' => 'F', 'father_id' => $petit->id]);
        $this->assertSame('descendante de '.$parentOfThadee->name.', votre quadrisaïeule', $label($arriere));

        app()->setLocale('en');
        $this->assertSame('brother of Thadée, your great-great-grandfather', $label($gaspard));
    }

    public function test_frise_deces_d_un_enfant_mort_avant_son_parent(): void
    {
        $this->gaspard->update(['death_date' => null]);
        $child = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Petit Gaspard', 'father_id' => $this->gaspard->id, 'birth_date' => '1888-10-08', 'death_date' => '1891-05-04']);

        $items = collect($this->actingAs($this->alice)->getJson("/people/{$this->gaspard->id}/timeline")->assertOk()->json());
        $death = $items->firstWhere('kind', 'child_death');
        $this->assertNotNull($death);
        $this->assertSame('1891-05-04', $death['date']);
        $this->assertSame('Décès de Petit Gaspard', $death['title']);
    }

    public function test_transcription_d_un_document(): void
    {
        $doc = Media::factory()->create(['user_id' => $this->alice->id, 'type' => 'photo']);

        $this->actingAs($this->alice)->putJson("/media/{$doc->id}", ['transcription' => 'Extrait des minutes du greffe'])->assertOk();
        $this->assertSame('Extrait des minutes du greffe', $doc->fresh()->transcription);
        $this->assertSame('Extrait des minutes du greffe', $doc->fresh()->toSearchableArray()['transcription']);
    }
}
