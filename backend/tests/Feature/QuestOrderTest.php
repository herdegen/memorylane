<?php

namespace Tests\Feature;

use App\Enums\QuestType;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\QuestAnswer;
use App\Models\User;
use App\Services\QuestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rythme des quêtes de l'accueil : blocs de 4 visages + 1 question d'une
 * autre catégorie (photos, famille, vie ; amis en mode amis) qui tourne,
 * proximité de parenté peu pénalisante, reprise d'après les réponses
 * récentes. Les manques parasites sont éteints par des réponses d'un autre
 * compte (elles valent pour tous et n'entrent pas dans l'historique testé).
 */
class QuestOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $other;
    private Person $self;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        $this->self = $this->person(['address' => '1 rue de la Paix, Lille']);
        $this->user->forceFill(['person_id' => $this->self->id])->save();
        $this->silence($this->self);
    }

    private function person(array $attributes = []): Person
    {
        return Person::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'gender' => 'M',
            'birth_date' => now()->subYears(40),
            'birth_place' => 'Lille',
        ], $attributes));
    }

    /** Éteint toutes les questions sur cette fiche, sauf celles listées. */
    private function silence(Person $person, array $except = []): void
    {
        foreach (QuestType::cases() as $type) {
            if ($type->subjectClass() === Person::class && ! $type->perUser() && ! in_array($type, $except, true)) {
                $this->answer($this->other, $type, $person->id, (string) $person->getMorphClass());
            }
        }
    }

    private function answer(User $by, QuestType $type, string $subjectId, string $subjectType, int $minutesAgo = 60): void
    {
        (new QuestAnswer)->forceFill([
            'user_id' => $by->id,
            'question_type' => $type->value,
            'question_key' => $type->key($subjectId),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'answer_kind' => 'answered',
            'created_at' => now()->subMinutes($minutesAgo),
        ])->save();
    }

    /** Réponse récente de l'utilisateur testé, sur un sujet hors du lot. */
    private function answeredRecently(QuestType $type, int $minutesAgo): void
    {
        $this->answer($this->user, $type, (string) Str::uuid(), 'person', $minutesAgo);
    }

    /** Visages à identifier, chacun sur une photo datée (sans autre question). */
    private function faces(int $count, ?Household $circle = null): void
    {
        foreach (range(1, $count) as $i) {
            $photo = Media::factory()->photo()->create(['user_id' => $this->user->id, 'taken_at' => '2020-06-01']);
            if ($circle) {
                $photo->households()->attach($circle->id);
            }
            $this->answer($this->other, QuestType::MediaGeo, $photo->id, $photo->getMorphClass());
            $photo->detectedFaces()->create([
                'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
                'status' => 'unmatched',
                'provider' => 'faceapi',
            ]);
        }
    }

    /** Photos sans date ni lieu : deux questions « photos » chacune. */
    private function undatedPhotos(int $count): array
    {
        return Media::factory()->photo()->count($count)->create(['user_id' => $this->user->id, 'taken_at' => null])->all();
    }

    /** Père et mère de « moi » (distance 1), avec leurs manques famille et vie. */
    private function parents(): array
    {
        $dad = $this->person(['birth_date' => now()->subYears(65)]);
        $mom = $this->person(['gender' => 'F', 'birth_date' => now()->subYears(63)]);
        $this->self->update(['father_id' => $dad->id, 'mother_id' => $mom->id]);

        return [$dad, $mom];
    }

    private function batch(): array
    {
        $service = app(QuestService::class);
        $service->forget($this->user);

        return $service->candidates($this->user->fresh());
    }

    private function categories(array $batch): array
    {
        return array_map(fn ($c) => QuestType::from($c['type'])->category(), $batch);
    }

    private function assertNoCategoryTwiceInARow(array $categories): void
    {
        foreach (array_slice($categories, 1, null, true) as $i => $category) {
            $this->assertNotSame($categories[$i - 1], $category, "même catégorie deux fois de suite en position {$i} : ".implode(' ', $categories));
        }
    }

    public function test_quatre_visages_puis_une_question_d_une_autre_categorie(): void
    {
        $this->faces(10);
        $this->undatedPhotos(3);
        $this->parents();

        $categories = $this->categories($this->batch());

        $this->assertSame(array_fill(0, 4, 'faces'), array_slice($categories, 0, 4));
        $this->assertSame(array_fill(0, 4, 'faces'), array_slice($categories, 5, 4));
        $this->assertNotSame('faces', $categories[4], 'la première question « autre » arrive au plus tard en 5ᵉ position');
        $this->assertNotSame('faces', $categories[9]);
        $this->assertNotSame($categories[4], $categories[9], 'la 5ᵉ question tourne d\'un bloc à l\'autre');
        $this->assertSame('family', $categories[4], 'sans historique, la famille d\'abord');
    }

    public function test_la_question_autre_tourne_entre_photos_famille_et_vie(): void
    {
        $this->undatedPhotos(3);
        $this->parents();

        $categories = $this->categories($this->batch());

        $this->assertSame(['family', 'life', 'photos'], array_slice($categories, 0, 3));
        $this->assertNoCategoryTwiceInARow(array_slice($categories, 0, 9));
    }

    public function test_s_il_manque_des_visages_les_autres_categories_completent_en_rotation(): void
    {
        $this->faces(2);
        $this->undatedPhotos(3);
        $this->parents();

        $categories = $this->categories($this->batch());

        $this->assertSame(['faces', 'faces'], array_slice($categories, 0, 2));
        $this->assertNotContains('faces', array_slice($categories, 2));
        $this->assertNoCategoryTwiceInARow(array_slice($categories, 2, 9));
    }

    public function test_un_grand_parent_passe_devant_les_photos(): void
    {
        // Ancienne pondération : 2 / (1 + 2) contre 3 / (1 + 1) pour une
        // photo — le grand-père passait après toutes les photos.
        [$dad, $mom] = $this->parents();
        $grandpa = $this->person(['birth_date' => now()->subYears(90), 'birth_place' => null]);
        $dad->update(['father_id' => $grandpa->id]);
        $this->silence($dad);
        $this->silence($mom);
        $this->silence($grandpa, [QuestType::BirthPlace]);
        $this->faces(6);
        $this->undatedPhotos(3);

        $keys = array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $this->batch());
        $grandpaAt = array_search("birth_place:{$grandpa->id}", $keys, true);
        $firstPhotoAt = min(array_keys(array_filter($keys, fn ($k) => str_starts_with($k, 'media_'))));

        $this->assertSame(4, $grandpaAt, implode(' ', $keys));
        $this->assertLessThan($firstPhotoAt, $grandpaAt);
    }

    public function test_les_visages_deja_enchaines_avancent_la_question_autre(): void
    {
        $this->faces(10);
        $this->parents();

        // Une question famille il y a 10 min, puis deux visages : encore deux
        // visages, et la question « autre » (pas famille : elle vient de passer).
        $this->answeredRecently(QuestType::BirthDate, 10);
        $this->answeredRecently(QuestType::FaceIdentify, 5);
        $this->answeredRecently(QuestType::FaceIdentify, 4);

        $this->assertSame(['faces', 'faces', 'life', 'faces'], array_slice($this->categories($this->batch()), 0, 4));

        // Quatre visages de plus (le jeu « Qui est-ce ? » compte aussi) : la
        // question « autre » d'abord.
        foreach ([3, 2, 1, 0] as $minutesAgo) {
            $this->answeredRecently(QuestType::FaceIdentify, $minutesAgo);
        }

        $this->assertSame('life', $this->categories($this->batch())[0]);
    }

    public function test_les_visages_ne_sont_pas_repousses_par_les_reponses_recentes(): void
    {
        $this->faces(10);
        $this->parents();

        foreach (range(20, 15) as $minutesAgo) {
            $this->answeredRecently(QuestType::FaceIdentify, $minutesAgo);
        }
        $this->answeredRecently(QuestType::Job, 1);

        $categories = $this->categories($this->batch());

        $this->assertSame(array_fill(0, 4, 'faces'), array_slice($categories, 0, 4));
        $this->assertSame('family', $categories[4], 'la vie vient de passer : la famille');
    }

    public function test_les_reponses_recentes_font_reculer_leur_categorie(): void
    {
        $this->undatedPhotos(3);
        $this->parents();

        $this->assertSame('family', $this->categories($this->batch())[0]);

        $this->answeredRecently(QuestType::ParentFather, 3);
        $this->answeredRecently(QuestType::BirthPlace, 2);

        $categories = $this->categories($this->batch());
        $this->assertSame(['life', 'photos'], array_slice($categories, 0, 2));
        $this->assertGreaterThanOrEqual(3, array_search('family', $categories, true), 'la famille, deux fois récente, recule');
        $this->assertNoCategoryTwiceInARow(array_slice($categories, 0, 9));
    }

    public function test_mode_amis_meme_rythme_sans_changer_le_perimetre(): void
    {
        $circle = Household::factory()->friends()->create(['created_by' => $this->other->id]);
        $circle->members()->attach($this->user->id);
        $this->user->forceFill(['current_household_id' => $circle->id])->save();

        $friends = collect(['Léa', 'Inès', 'Jules'])->map(function ($name) use ($circle) {
            $friend = $this->person(['name' => $name, 'birth_date' => null, 'in_family_directory' => false]);
            $friend->circles()->attach($circle->id);

            return $friend;
        });
        foreach ($friends as $friend) {
            $photo = Media::factory()->photo()->create(['user_id' => $this->user->id, 'taken_at' => null]);
            $photo->people()->attach([$this->self->id, $friend->id]);
        }
        $this->faces(6, $circle);

        $batch = $this->batch();
        $categories = $this->categories($batch);

        $this->assertSame([...array_fill(0, 4, 'faces'), 'friends', 'faces', 'faces'], array_slice($categories, 0, 7));
        $this->assertNoCategoryTwiceInARow(array_slice($categories, 6, 6));
        $this->assertEqualsCanonicalizing(['faces', 'friends', 'family', 'photos'], array_values(array_unique($categories)));
        foreach ($batch as $c) {
            $this->assertFalse(QuestType::from($c['type'])->genealogical(), "pas de généalogie en mode amis : {$c['type']}");
        }
    }
}
