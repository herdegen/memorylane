<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonProposal;
use App\Models\User;
use App\Services\SharedFiches;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * « Fiches communes » : les propositions à trancher, les siennes, et ce que
 * les autres familles ont changé sur les fiches que l'on partage avec elles
 * (avec « Je ne suis pas d'accord »). Cf. SharedFiches.
 */
class SharedPeopleController extends Controller
{
    public function __construct(
        private SharedFiches $shared,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_if($user->familyCircleIds() === [], 404);
        $since = now()->subDays(SharedFiches::NEWS_DAYS);
        $seen = $this->shared->seenAt($user);
        $sharedIds = $this->shared->sharedIdsFor($user);

        $toDecide = $this->shared->toDecideFor($user);
        $mine = PersonProposal::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('disputed_by', $user->id))
            ->where(fn ($q) => $q->where('status', PersonProposal::PENDING)->orWhere('decided_at', '>=', $since))
            ->whereNotIn('id', $toDecide->pluck('id'))
            ->with('person')
            ->latest()
            ->limit(50)
            ->get();
        // Les autres propositions en attente sur nos fiches (pour information).
        $others = $this->shared->pendingFor($user)
            ->whereNotIn('id', [...$toDecide->pluck('id'), ...$mine->pluck('id')])
            ->with('person')
            ->latest()
            ->limit(50)
            ->get();
        $news = $sharedIds === [] ? collect() : $this->shared->newsFor($user, $since, $sharedIds)->with('user:id,name')->limit(100)->get();

        $this->shared->markSeen($user);

        return Inertia::render('SharedPeople/Index', [
            'toDecide' => $toDecide->map(fn ($p) => $this->proposalPayload($p, $user))->values(),
            'mine' => $mine->filter(fn ($p) => $p->person)->map(fn ($p) => $this->proposalPayload($p, $user))->values(),
            'others' => $others->filter(fn ($p) => $p->person)->map(fn ($p) => $this->proposalPayload($p, $user))->values(),
            'news' => $news->map(fn ($c) => $this->changePayload($c, $user, $seen))->filter()->values(),
            'families' => $this->familiesSummary($user, $sharedIds),
        ]);
    }

    public function accept(Request $request, PersonProposal $proposal)
    {
        $note = $request->validate(['message' => 'nullable|string|max:2000'])['message'] ?? null;
        $this->shared->accept($proposal, $request->user());
        if (filled($note)) {
            $proposal->messages()->create(['user_id' => $request->user()->id, 'body' => trim($note)]);
        }

        return response()->json(['message' => __('Modification acceptée : la fiche est à jour.')]);
    }

    public function reject(Request $request, PersonProposal $proposal)
    {
        $note = $request->validate(['message' => 'nullable|string|max:2000'])['message'] ?? null;
        $this->shared->reject($proposal, $request->user());
        if (filled($note)) {
            $proposal->messages()->create(['user_id' => $request->user()->id, 'body' => trim($note)]);
        }

        return response()->json(['message' => $proposal->isDispute()
            ? __('L’ancienne valeur est gardée.')
            : __('Proposition refusée.')]);
    }

    public function cancel(Request $request, PersonProposal $proposal)
    {
        $this->shared->cancel($proposal, $request->user());

        return response()->json(['message' => __('Proposition retirée.')]);
    }

    public function message(Request $request, PersonProposal $proposal)
    {
        abort_unless($this->shared->canSee($request->user(), $proposal), 404);
        $body = $request->validate(['message' => 'required|string|max:2000'])['message'];
        $proposal->messages()->create(['user_id' => $request->user()->id, 'body' => trim($body)]);
        $proposal->touch();
        $this->shared->forgetCounts($proposal->person);

        return response()->json(['message' => __('Message envoyé.')]);
    }

    public function dispute(Request $request, PersonChange $change)
    {
        $body = $request->validate(['message' => 'required|string|max:2000'])['message'];
        $proposal = $this->shared->dispute($change, $request->user(), $body);

        return response()->json([
            'message' => __('L’ancienne valeur est rétablie ; :family tranchera.', [
                'family' => Household::whereIn('id', $this->shared->deciderFamilyIds($proposal))->pluck('name')->join(', ') ?: __('la famille référente'),
            ]),
        ]);
    }

    // ── Présentation ───────────────────────────────────────────────────

    private function proposalPayload(PersonProposal $proposal, User $user): array
    {
        $person = $proposal->person;
        $proposal->loadMissing(['user:id,name', 'household:id,name', 'disputer:id,name', 'decider:id,name', 'messages.user:id,name']);
        $pending = $proposal->isPending();

        return [
            'id' => $proposal->id,
            'origin' => $proposal->origin,
            'status' => $proposal->status,
            'created_at' => $proposal->created_at->toIso8601String(),
            'decided_at' => $proposal->decided_at?->toIso8601String(),
            'person' => $this->personPayload($person),
            'by' => $proposal->user?->name,
            'family' => $proposal->household?->name,
            'disputed_by' => $proposal->disputer?->name,
            'decided_by' => $proposal->decider?->name,
            'source' => $proposal->source,
            'changes' => collect($proposal->changes['fields'] ?? [])
                ->map(function ($pair, $field) use ($person, $pending) {
                    [$old, $new] = $pair + [null, null];
                    $current = PersonChange::normalizeValue($field, $person->getRawOriginal($field));

                    return [
                        'label' => __(PersonChange::FIELD_LABELS[$field] ?? $field),
                        'old' => $this->display($field, $old),
                        'new' => $this->display($field, $new),
                        // La fiche a changé depuis la proposition.
                        'current' => $pending && $current !== $old && $current !== $new ? $this->display($field, $current) : null,
                    ];
                })->values(),
            'remove_spouse' => $proposal->changes['remove_spouse']['name'] ?? null,
            'deciders' => Household::whereIn('id', $this->shared->deciderFamilyIds($proposal))->pluck('name'),
            'can_decide' => $this->shared->canDecide($user, $proposal),
            'can_cancel' => $pending && ! $proposal->isDispute() && $proposal->user_id === $user->id,
            'messages' => $proposal->messages->map(fn ($m) => [
                'by' => $m->user?->name,
                'mine' => $m->user_id === $user->id,
                'body' => $m->body,
                'at' => $m->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    private function changePayload(PersonChange $change, User $user, Carbon $seen): ?array
    {
        $person = Person::find($change->person_id);
        if (! $person) {
            return null;
        }
        $author = $change->user;
        $family = $author ? $this->shared->familyOfUserFor($author, $person)?->name : null;

        $items = $change->action === 'updated'
            ? collect($change->diff ?? [])->map(fn ($pair, $field) => [
                'label' => __(PersonChange::FIELD_LABELS[$field] ?? $field),
                'old' => $this->display($field, $pair[0] ?? null),
                'new' => $this->display($field, $pair[1] ?? null),
            ])->values()
            : collect([[
                'label' => __(PersonChange::ACTION_LABELS[$change->action] ?? $change->action),
                'old' => null,
                'new' => $change->diff['spouse_name'] ?? null,
            ]]);

        return [
            'id' => $change->id,
            'at' => $change->created_at->toIso8601String(),
            'is_new' => $change->created_at->gt($seen),
            'person' => $this->personPayload($person),
            'by' => $author?->name,
            'family' => $family,
            'items' => $items,
            'reverted' => $change->reverted_at !== null,
            'can_dispute' => $this->shared->canDispute($user, $change),
        ];
    }

    private function personPayload(Person $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar' => $person->avatarUrl(),
        ];
    }

    private function display(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            in_array($field, SharedFiches::PARENT_FIELDS, true) => Person::withTrashed()->find($value)?->name ?? __('Fiche inconnue'),
            in_array($field, ['birth_date', 'death_date'], true) => Carbon::parse($value)->locale(app()->getLocale())->translatedFormat('j F Y'),
            $field === 'gender' => match ($value) {
                'M' => __('Homme'),
                'F' => __('Femme'),
                default => __('Inconnu'),
            },
            default => (string) $value,
        };
    }

    /**
     * Les autres familles avec qui l'on partage des fiches, et combien.
     *
     * @param  list<string>  $sharedIds
     */
    private function familiesSummary(User $user, array $sharedIds): array
    {
        if ($sharedIds === []) {
            return [];
        }

        return DB::table('household_person as hp')
            ->join('households as h', 'h.id', '=', 'hp.household_id')
            ->where('h.kind', Household::KIND_FAMILY)
            ->whereIn('hp.person_id', $sharedIds)
            ->whereNotIn('h.id', $user->familyCircleIds())
            ->groupBy('h.id', 'h.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->get(['h.name', DB::raw('count(*) as count')])
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->all();
    }
}
