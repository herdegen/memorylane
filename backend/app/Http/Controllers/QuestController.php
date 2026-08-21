<?php

namespace App\Http\Controllers;

use App\Enums\QuestType;
use App\Exceptions\StaleQuestException;
use App\Models\QuestAnswer;
use App\Services\QuestAnswerApplier;
use App\Services\QuestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        $subject = $type->subjectClass()::findOrFail($validated['subject_id']);

        if ($kind === 'no' && ! $type->allowsNo()) {
            return response()->json(['message' => 'Cette question n\'accepte pas la réponse « non ».'], 422);
        }

        $payload = $kind === 'answered'
            ? Validator::make($validated['payload'] ?? [], $type->rules())->validate()
            : [];

        try {
            DB::transaction(function () use ($user, $type, $subject, $kind, $payload) {
                match ($kind) {
                    'answered' => $this->applier->apply($user, $type, $subject, $payload),
                    'no' => $this->applier->applyNo($user, $type, $subject),
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
