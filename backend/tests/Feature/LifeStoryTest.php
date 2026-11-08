<?php

namespace Tests\Feature;

use App\Models\LifeEvent;
use App\Models\LifeStoryAnswer;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Récit de vie guidé : étapes pré-remplies de la frise et de l'arbre, ce
 * qu'on valide va sur la frise (sans doublon en y revenant), récit gardé.
 */
class LifeStoryTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Person $nicole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create();
        $this->nicole = Person::factory()->create(['user_id' => $this->me->id, 'name' => 'Nicole Duband', 'first_name' => 'Nicole',
            'birth_date' => '1955-07-09', 'birth_place' => 'Cusset', 'death_date' => null]);
    }

    private function event(string $type, string $title, string $from, ?string $to = null, ?User $by = null): LifeEvent
    {
        return LifeEvent::create(['person_id' => $this->nicole->id, 'user_id' => ($by ?? $this->me)->id, 'type' => $type, 'title' => $title,
            'event_date' => $from, 'end_date' => $to, 'date_precision' => 'year']);
    }

    public function test_les_etapes_arrivent_pre_remplies(): void
    {
        $this->event('education', 'École Jules-Ferry', '1962-01-01', '1966-01-01');
        $this->event('residence', 'Mennecy', '1988-01-01', '2016-01-01');
        $this->event('job', 'Mairie', '1980-01-01');

        $this->actingAs($this->me)->get("/people/{$this->nicole->id}/recit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/LifeStory', false)
                ->where('person.birth_year', 1955)
                ->where('chapters.0.key', 'naissance')
                ->where('chapters.0.facts.0', 'Naissance : 9 juillet 1955 à Cusset')
                ->where('chapters.1.years', [1961, 1966])
                ->where('events.0.chapters', ['primaire'])
                ->where('events.1.chapters', ['travail'])
                ->where('events.2.chapters', ['travail', 'couple', 'enfants', 'aujourdhui']));
    }

    public function test_un_lieu_de_vie_sans_fin_dure_jusqu_au_demenagement_suivant(): void
    {
        $this->nicole->update(['birth_place' => 'Cusset, , , ,']);
        $this->event('residence', 'Vichy', '1956-01-01');
        $this->event('residence', 'Les Lilas', '2016-01-01');

        $this->actingAs($this->me)->get("/people/{$this->nicole->id}/recit")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('chapters.0.facts.0', 'Naissance : 9 juillet 1955 à Cusset')
                ->where('events.0.chapters', ['naissance', 'primaire', 'college', 'lycee', 'etudes', 'travail', 'couple', 'enfants', 'aujourdhui'])
                ->where('events.1.chapters', ['travail', 'aujourdhui'])
                // Jamais d'années dans le futur.
                ->where('chapters.10.years', [2015, null]));
    }

    public function test_valider_une_etape_remplit_la_frise_sans_doublon(): void
    {
        $url = "/people/{$this->nicole->id}/recit/primaire";
        $this->actingAs($this->me)->postJson($url, [
            'status' => 'done',
            'body' => 'La maîtresse s’appelait Mme Roux.',
            'rows' => [
                ['type' => 'education', 'title' => 'École du Centre', 'place' => 'Cusset', 'from' => 1961, 'to' => 1966],
                ['type' => 'residence', 'title' => 'Vichy', 'place' => 'Vichy', 'from' => 1963, 'to' => 1970],
            ],
        ])->assertOk();

        $school = LifeEvent::where('type', 'education')->sole();
        $this->assertSame('École du Centre', $school->title);
        $this->assertSame('1961-01-01', $school->event_date->format('Y-m-d'));
        $this->assertSame('1966-01-01', $school->end_date->format('Y-m-d'));
        $this->assertSame('year', $school->date_precision);
        $answer = LifeStoryAnswer::sole();
        $this->assertSame('done', $answer->status);
        $this->assertSame('La maîtresse s’appelait Mme Roux.', $answer->body);
        $this->assertCount(2, $answer->event_ids);

        // Revenu sur l'étape : on corrige l'école, on retire le lieu de vie.
        $residence = LifeEvent::where('type', 'residence')->sole();
        $this->actingAs($this->me)->postJson($url, [
            'status' => 'done',
            'rows' => [['id' => $school->id, 'type' => 'education', 'title' => 'École du Centre', 'place' => 'Cusset', 'from' => 1961, 'to' => 1967]],
            'removed' => [$residence->id],
        ])->assertOk();

        $this->assertSame(1, LifeEvent::count());
        $this->assertSame('1967', $school->fresh()->end_date->format('Y'));
        $this->assertSame([$school->id], LifeStoryAnswer::sole()->event_ids);
    }

    public function test_une_etape_n_accepte_que_ses_elements(): void
    {
        $this->actingAs($this->me)->postJson("/people/{$this->nicole->id}/recit/primaire", [
            'status' => 'done',
            'rows' => [['type' => 'job', 'title' => 'Mairie', 'from' => 1980]],
        ])->assertUnprocessable();
        // Pas de lieu de vie au service militaire (pas de question des déménagements).
        $this->actingAs($this->me)->postJson("/people/{$this->nicole->id}/recit/service", [
            'status' => 'done',
            'rows' => [['type' => 'residence', 'title' => 'Metz', 'from' => 1975]],
        ])->assertUnprocessable();
        $this->actingAs($this->me)->postJson("/people/{$this->nicole->id}/recit/inconnue", ['status' => 'done'])->assertNotFound();
    }

    public function test_on_ne_supprime_pas_ce_que_d_autres_ont_saisi(): void
    {
        $other = User::factory()->create();
        $theirs = $this->event('education', 'Lycée Albert-Londres', '1970-01-01', '1973-01-01', $this->me);
        $this->actingAs($other)->postJson("/people/{$this->nicole->id}/recit/lycee", ['status' => 'done', 'removed' => [$theirs->id]])->assertOk();

        $this->assertModelExists($theirs);
    }

    public function test_le_recit_a_l_oral(): void
    {
        Storage::fake();
        $audio = UploadedFile::fake()->create('recit.webm', 40, 'audio/webm');

        $this->actingAs($this->me)->post("/people/{$this->nicole->id}/recit/naissance/audio", ['audio' => $audio], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Enregistré.');

        $answer = LifeStoryAnswer::sole();
        Storage::assertExists($answer->audio_path);
        $this->actingAs(User::factory()->create())->get("/life-story-audio/{$answer->id}")->assertNotFound();
    }

    public function test_pas_pour_une_tablette(): void
    {
        $this->me->forceFill(['simple_mode' => true])->save();
        $this->actingAs($this->me)->get("/people/{$this->nicole->id}/recit")->assertStatus(302);
    }
}
