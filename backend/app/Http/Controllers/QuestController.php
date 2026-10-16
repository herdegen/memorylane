<?php

namespace App\Http\Controllers;

use App\Enums\QuestType;
use App\Exceptions\StaleQuestException;
use App\Models\Album;
use App\Models\Person;
use App\Models\QuestAnswer;
use App\Services\QuestAnswerApplier;
use App\Services\QuestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Gamification « quêtes » : questions de complétion de données posées sur le
 * Dashboard. Les réponses s'appliquent immédiatement (canal collaboratif
 * write-once, cf. QuestAnswerApplier) et sont journalisées (quest_answers).
 */
class QuestController extends Controller
{
    public function __construct(
        private QuestService $quests,
        private QuestAnswerApplier $applier,
    ) {
    }

    public function next(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'question' => $this->quests->next($user),
            'completed_count' => $this->quests->completedCount($user),
        ]);
    }

    /** Jeu « Qui est-ce ? » plein écran (téléphone), optionnellement limité à un album. */
    public function faces(Request $request)
    {
        $album = $this->gameAlbum($request);

        return Inertia::render('Quests/Faces', [
            'album' => $album ? ['id' => $album->id, 'name' => $album->name] : null,
        ]);
    }

    public function facesNext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exclude' => 'nullable|array|max:500',
            'exclude.*' => 'uuid',
        ]);

        return response()->json($this->quests->faceQuestions(
            $request->user(),
            $this->gameAlbum($request),
            $validated['exclude'] ?? [],
        ));
    }

    private function gameAlbum(Request $request): ?Album
    {
        $id = $request->query('album');

        return $id ? Album::accessibleBy($request->user())->findOrFail($id) : null;
    }

    public function answer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_type' => ['required', Rule::enum(QuestType::class)],
            'subject_id' => 'required|uuid',
            'answer_kind' => 'required|in:answered,no,dont_know,skipped',
            'payload' => 'nullable|array',
        ]);

        $user = $request->user();
        $type = QuestType::from($validated['question_type']);
        $kind = $validated['answer_kind'];
        $subject = $type->subjectClass() === Person::class
            ? Person::visibleTo($user)->findOrFail($validated['subject_id'])
            : $type->subjectClass()::findOrFail($validated['subject_id']);

        if ($kind === 'no' && ! $type->allowsNo()) {
            return response()->json(['message' => 'Cette question n\'accepte pas la réponse « non ».'], 422);
        }

        $payload = match ($kind) {
            'answered' => Validator::make($validated['payload'] ?? [], $type->rules())->validate(),
            // Visage : « personne inconnue » (reste visible) ou « pas un visage ».
            'no' => Validator::make($validated['payload'] ?? [], ['reason' => 'nullable|in:stranger,not_face'])->validate(),
            default => [],
        };

        try {
            DB::transaction(function () use ($user, $type, $subject, $kind, $payload) {
                match ($kind) {
                    'answered' => $this->applier->apply($user, $type, $subject, $payload),
                    'no' => $this->applier->applyNo($user, $type, $subject, $payload['reason'] ?? null),
                    default => $this->applier->authorize($user, $type, $subject),
                };

                QuestAnswer::create([
                    'user_id' => $user->id,
                    'question_type' => $type->value,
                    'question_key' => $type->key($subject->id),
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->id,
                    'answer_kind' => $kind,
                    'payload' => $payload ?: null,
                ]);
            });
        } catch (StaleQuestException $e) {
            // Quelqu'un d'autre a complété entre-temps : rien n'est écrit ni
            // journalisé, on enchaîne simplement sur la question suivante.
            $this->quests->forget($user);

            return response()->json([
                'message' => $e->getMessage(),
                'completed_count' => $this->quests->completedCount($user),
                'next' => $this->quests->next($user),
            ], 409);
        }

        $this->quests->forget($user);

        return response()->json([
            'message' => 'Merci !',
            'completed_count' => $this->quests->completedCount($user),
            'next' => $this->quests->next($user),
        ], 201);
    }
}
