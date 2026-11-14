<?php

namespace Tests\Feature;

use App\Enums\QuestType;
use App\Models\Household;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\Story;
use App\Models\User;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use App\Services\QuestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les histoires circulent : feuilleton de la lettre du dimanche, carte de
 * l'accueil, petit livre dans l'arbre, questions ouvertes en quêtes.
 */
class StoryCirculationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $reader;
    private Household $family;
    private Person $gaspard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-15 18:30', 'Europe/Paris'));
        $this->family = Household::create(['name' => 'Famille', 'kind' => Household::KIND_FAMILY, 'invite_policy' => 'everyone', 'created_by' => User::factory()->create()->id]);
        $this->author = $this->member('Annick');
        $this->reader = $this->member('Matthieu');
        $this->gaspard = Person::factory()->create(['user_id' => $this->author->id, 'name' => 'Gaspard KIEFFER', 'in_family_directory' => true]);
        $this->family->people()->syncWithoutDetaching([$this->gaspard->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $first): User
    {
        $person = Person::factory()->create(['first_name' => $first, 'last_name' => 'Eisele']);
        $user = User::factory()->create(['name' => "{$first} Eisele", 'person_id' => $person->id]);
        $person->update(['user_id' => $user->id]);
        $this->family->addMember($user, $user);

        return $user;
    }

    private function story(int $chapters = 3, ?Carbon $published = null): Story
    {
        $story = Story::create(['user_id' => $this->author->id, 'title' => 'L’affaire Kieffer', 'summary' => 'En 1890…', 'kind' => 'documented', 'published_at' => $published ?? now()->subDays(2)]);
        $story->people()->attach($this->gaspard->id, ['is_main' => true]);
        for ($i = 0; $i < $chapters; $i++) {
            $story->chapters()->create(['position' => $i, 'title' => "Chapitre ".($i + 1), 'when_label' => '1890', 'blocks' => [['type' => 'text', 'text' => "Texte {$i}"]]]);
        }

        return $story;
    }

    private function episode(): ?array
    {
        $issue = app(FamilyNewsletterBuilder::class)->build($this->reader);

        return collect($issue['sections'] ?? [])->firstWhere('key', 'story');
    }

    private function send(array $issue): void
    {
        NewsletterSend::create(['user_id' => $this->reader->id, 'edition' => 'family', 'sections' => $issue['log'], 'subject' => $issue['subject'], 'sent_at' => now()]);
    }

    public function test_feuilleton_un_chapitre_par_lettre_puis_plus_rien(): void
    {
        $story = $this->story(2);

        $issue = app(FamilyNewsletterBuilder::class)->build($this->reader);
        $first = collect($issue['sections'])->firstWhere('key', 'story');
        $this->assertSame(1, $first['number']);
        $this->assertSame(2, $first['total']);
        $this->assertSame('En 1890…', $first['summary']);
        $this->assertStringContainsString('« L’affaire Kieffer »', $issue['subject']);
        // Une histoire qui commence passe en tête de l'objet, avant les photos.
        $this->assertStringStartsWith('Cette semaine en famille : une histoire de famille, « L’affaire Kieffer »', $issue['subject']);
        // Semaine de l'annonce « Les histoires de famille » (jusqu'au 30/11/2026) : l'épisode la suit aussitôt.
        if (($issue['sections'][0]['ref'] ?? null) === 'histoires') {
            $this->assertSame('story', $issue['sections'][1]['key']);
        }
        $this->send($issue);

        Carbon::setTestNow(now()->addWeek());
        $issue = app(FamilyNewsletterBuilder::class)->build($this->reader);
        $second = collect($issue['sections'])->firstWhere('key', 'story');
        $this->assertSame(2, $second['number']);
        $this->assertSame("{$story->id}:1", $second['ref']);
        $this->send($issue);

        Carbon::setTestNow(now()->addWeek());
        $this->assertNull($this->episode(), 'histoire finie : pas d’encart');
    }

    public function test_pas_d_histoire_chaque_semaine(): void
    {
        $this->story(2, now()->subDays(200)); // trop ancienne pour commencer
        $this->assertNull($this->episode());

        $draft = $this->story(2);
        $draft->update(['published_at' => null]);
        $this->assertNull($this->episode(), 'un brouillon ne part pas');

        // L'auteur ne reçoit pas sa propre histoire en feuilleton.
        $this->story(2);
        $this->assertNull(collect(app(FamilyNewsletterBuilder::class)->build($this->author)['sections'] ?? [])->firstWhere('key', 'story'));
    }

    public function test_carte_d_accueil_et_petit_livre_dans_l_arbre(): void
    {
        $story = $this->story();

        $this->actingAs($this->reader)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('story.id', $story->id)->where('story.new', true)->where('story.chapters', 3));

        $nodes = collect($this->actingAs($this->reader)->getJson('/family-tree/data')->assertOk()->json())->keyBy('id');
        $this->assertTrue($nodes[$this->gaspard->id]['data']['has_story']);
        $this->assertFalse($nodes[$this->reader->person_id]['data']['has_story']);
    }

    public function test_questions_ouvertes_en_quetes(): void
    {
        $story = $this->story();
        $question = $story->questions()->create(['position' => 0, 'text' => 'Où et quand est-il mort ?']);
        $quests = app(QuestService::class);

        $candidates = collect($quests->candidates($this->reader))->where('type', QuestType::StoryQuestion->value);
        $this->assertSame([$question->id], $candidates->pluck('subject_id')->all());
        $this->assertSame([], collect($quests->candidates($this->author))->where('type', QuestType::StoryQuestion->value)->values()->all(), 'pas à l’auteur');

        $this->actingAs($this->reader)->postJson('/quests/answer', [
            'question_type' => QuestType::StoryQuestion->value,
            'subject_id' => $question->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => 'À Arima vers 1930, d’après tante Jeanne.'],
        ])->assertSuccessful();

        $this->assertSame('À Arima vers 1930, d’après tante Jeanne.', $question->notes()->value('body'));
        $this->assertSame($this->reader->id, $question->notes()->value('user_id'));
        $quests->forget($this->reader);
        $this->assertFalse($quests->gapExists(QuestType::StoryQuestion, $question->fresh(), $this->reader));
    }
}
