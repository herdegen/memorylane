<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonProposal;
use App\Models\PersonRelationship;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fiches communes à plusieurs familles : qui modifie quoi.
 *
 * La FAMILLE RÉFÉRENTE d'une fiche commune est celle de la personne
 * elle-même si elle a un compte, sinon la plus proche par le sang (ses
 * descendants avant les collatéraux), à égalité la première à l'avoir eue.
 * Ses membres modifient directement. Les autres familles complètent
 * directement un champ vide, mais une correction devient une PROPOSITION
 * que la référente accepte ou refuse. Changer les parents d'une fiche
 * commune est toujours une proposition (la référente complète seulement un
 * parent manquant) ; une proposition de la référente est tranchée par les
 * autres familles. Les photos restent hors de cette hiérarchie.
 *
 * Une modification venue d'une autre famille peut être contestée (« Je ne
 * suis pas d'accord ») : l'ancienne valeur revient et une discussion
 * s'ouvre ; la référente tranche (celui qui conteste peut aussi se ranger
 * à la modification).
 */
class SharedFiches
{
    /** Champs soumis à la hiérarchie (ajout direct, correction proposée). */
    public const FIELDS = [
        'first_name', 'last_name', 'maiden_name', 'gender',
        'birth_date', 'birth_place', 'death_date', 'death_place',
        'address', 'notes',
    ];

    public const PARENT_FIELDS = ['father_id', 'mother_id'];

    /** Fenêtre des nouveautés et des désaccords possibles. */
    public const NEWS_DAYS = 90;

    /** @var array<string, Collection> */
    private array $families = [];

    /** @var array<string, ?Household> */
    private array $referents = [];

    public function __construct(
        private GenealogyService $genealogy,
    ) {}

    /**
     * Cercles famille d'une fiche, du plus ancien rattachement au plus récent.
     *
     * @return Collection<int, Household>
     */
    public function families(Person $person): Collection
    {
        return $this->families[$person->id] ??= Household::query()
            ->join('household_person as hp', 'hp.household_id', '=', 'households.id')
            ->where('hp.person_id', $person->id)
            ->where('households.kind', Household::KIND_FAMILY)
            ->orderBy('hp.created_at')
            ->orderBy('households.id')
            ->get(['households.id', 'households.name']);
    }

    public function isShared(Person $person): bool
    {
        return $this->families($person)->count() > 1;
    }

    public function referent(Person $person): ?Household
    {
        if (array_key_exists($person->id, $this->referents)) {
            return $this->referents[$person->id];
        }
        $families = $this->families($person);
        if ($families->count() < 2) {
            return $this->referents[$person->id] = $families->first();
        }

        $ids = $families->pluck('id')->all();
        $id = Cache::remember(
            'shared-fiche-referent:'.$person->id.':'.md5(implode(',', $ids)),
            600,
            fn () => $this->computeReferent($person, $ids),
        );

        return $this->referents[$person->id] = $families->firstWhere('id', $id) ?? $families->first();
    }

    /** @param  list<string>  $familyIds  dans l'ordre d'ancienneté */
    private function computeReferent(Person $person, array $familyIds): string
    {
        $members = DB::table('household_user as hu')
            ->join('users as u', 'u.id', '=', 'hu.user_id')
            ->whereIn('hu.household_id', $familyIds)
            ->whereNull('u.guest_expires_at')
            ->whereNotNull('u.person_id')
            ->get(['hu.household_id', 'u.person_id']);

        // La personne elle-même, si elle a un compte dans une des familles.
        foreach ($familyIds as $id) {
            if ($members->contains(fn ($m) => $m->household_id === $id && $m->person_id === $person->id)) {
                return $id;
            }
        }

        // Sinon la famille qui compte le membre le plus proche d'elle par le
        // sang, vu depuis elle : ses descendants passent avant ses neveux.
        $score = $this->genealogy->closeness($person->id);
        $best = null;
        $bestScore = INF;
        foreach ($familyIds as $id) {
            foreach ($members->where('household_id', $id) as $member) {
                $s = $score[$member->person_id] ?? null;
                if ($s !== null && $s < $bestScore) {
                    [$best, $bestScore] = [$id, $s];
                }
            }
        }

        return $best ?? $familyIds[0];
    }

    /** Membre de la famille référente (ou fiche d'une seule famille). */
    public function isReferentMember(User $user, Person $person): bool
    {
        if (! $this->isShared($person)) {
            return true;
        }
        $referent = $this->referent($person);

        return $referent !== null && in_array($referent->id, $user->familyCircleIds(), true);
    }

    /** La famille du compte pour cette fiche (sa famille courante d'abord). */
    public function familyOfUserFor(User $user, Person $person): ?Household
    {
        $mine = $user->familyCircleIds();
        $families = $this->families($person)->filter(fn ($h) => in_array($h->id, $mine, true));
        $current = $user->currentFamilyCircle()?->id;

        return $families->firstWhere('id', $current) ?? $families->first();
    }

    /**
     * Sépare des changements validés [champ => valeur] en [directs, proposés
     * (champ => [ancien, nouveau])]. Seuls les champs qui changent vraiment
     * peuvent devenir une proposition.
     *
     * @return array{0: array<string, mixed>, 1: array<string, array{0: mixed, 1: mixed}>}
     */
    public function split(User $user, Person $person, array $changes): array
    {
        if (! $this->isShared($person)) {
            return [$changes, []];
        }
        $referent = $this->isReferentMember($user, $person);

        $direct = [];
        $proposed = [];
        foreach ($changes as $field => $value) {
            $isParent = in_array($field, self::PARENT_FIELDS, true);
            if (! $isParent && ! in_array($field, self::FIELDS, true)) {
                $direct[$field] = $value;

                continue;
            }
            $old = PersonChange::normalizeValue($field, $person->getRawOriginal($field));
            $new = PersonChange::normalizeValue($field, $value);
            if ($old === $new) {
                continue;
            }
            $addition = $old === null || ($field === 'gender' && $old === 'U');
            $isDirect = $referent ? (! $isParent || $addition) : (! $isParent && $addition);
            if ($isDirect) {
                $direct[$field] = $value;
            } else {
                $proposed[$field] = [$old, $new];
            }
        }

        return [$direct, $proposed];
    }

    /**
     * Enregistre une proposition (la même, déjà en attente, n'est pas
     * doublée).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $fields
     */
    public function propose(User $user, Person $person, array $fields, ?string $source = null, ?array $removeSpouse = null): PersonProposal
    {
        $changes = array_filter(['fields' => $fields ?: null, 'remove_spouse' => $removeSpouse]);
        $existing = PersonProposal::pending()
            ->where('person_id', $person->id)
            ->where('user_id', $user->id)
            ->where('origin', 'proposal')
            ->get()
            ->first(fn (PersonProposal $p) => $p->changes == $changes);
        if ($existing) {
            return $existing;
        }

        $proposal = PersonProposal::create([
            'person_id' => $person->id,
            'household_id' => $this->familyOfUserFor($user, $person)?->id,
            'user_id' => $user->id,
            'origin' => 'proposal',
            'changes' => $changes,
            'source' => filled($source) ? trim($source) : null,
        ]);
        $this->forgetCounts($person);

        return $proposal;
    }

    /**
     * Familles qui tranchent : la référente ; pour une proposition venue de
     * la référente elle-même (des parents), les autres familles.
     *
     * @return list<string>
     */
    public function deciderFamilyIds(PersonProposal $proposal): array
    {
        $families = $this->families($proposal->person)->pluck('id')->all();
        if (count($families) < 2) {
            return $families;
        }
        $referent = $this->referent($proposal->person)?->id;
        if (! $proposal->isDispute() && $proposal->household_id === $referent) {
            return array_values(array_diff($families, [$referent]));
        }

        return [$referent];
    }

    public function canDecide(User $user, PersonProposal $proposal): bool
    {
        if (! $proposal->isPending() || ! $proposal->person) {
            return false;
        }
        if ($proposal->isDispute()) {
            // Qui conteste peut se ranger à la modification.
            if ($proposal->disputed_by === $user->id) {
                return true;
            }
        } elseif ($proposal->user_id === $user->id) {
            return false;
        }

        return array_intersect($this->deciderFamilyIds($proposal), $user->familyCircleIds()) !== [];
    }

    /** Lisible par les familles de la fiche, et par qui a proposé. */
    public function canSee(User $user, PersonProposal $proposal): bool
    {
        if ($user->isAdmin() || $proposal->user_id === $user->id || $proposal->disputed_by === $user->id) {
            return true;
        }
        if (! $proposal->person) {
            return false;
        }

        return array_intersect($this->families($proposal->person)->pluck('id')->all(), $user->familyCircleIds()) !== [];
    }

    public function accept(PersonProposal $proposal, User $by): void
    {
        abort_unless($this->canDecide($by, $proposal), 403);

        DB::transaction(function () use ($proposal, $by) {
            $person = $proposal->person;
            $fields = collect($proposal->changes['fields'] ?? [])->map(fn ($pair) => $pair[1] ?? null)->all();

            foreach (self::PARENT_FIELDS as $field) {
                $parentId = $fields[$field] ?? null;
                if ($parentId && ($parentId === $person->id || Person::descendsFrom($parentId, $person->id))) {
                    abort(422, __('Lien impossible : cette personne descend déjà de la fiche (boucle dans l\'arbre).'));
                }
            }

            PersonChange::forProposal($proposal->id, function () use ($person, $fields, $proposal, $by) {
                if ($fields !== []) {
                    $person->update($fields);
                }
                if ($spouseId = $proposal->changes['remove_spouse']['id'] ?? null) {
                    $ids = [$person->id, $spouseId];
                    sort($ids);
                    PersonRelationship::where('person1_id', $ids[0])->where('person2_id', $ids[1])->get()->each->delete();
                }
                // Un nouveau parent entre dans toutes les familles de la fiche :
                // l'arbre reste entier pour chacune.
                $familyIds = $this->families($person)->pluck('id')->all();
                foreach (self::PARENT_FIELDS as $field) {
                    if ($parent = Person::find($fields[$field] ?? null)) {
                        $parent->circles()->syncWithoutDetaching(array_fill_keys($familyIds, ['added_by' => $by->id]));
                    }
                }
            });

            $proposal->update(['status' => PersonProposal::ACCEPTED, 'decided_by' => $by->id, 'decided_at' => now()]);
        });
        $this->forgetCounts($proposal->person);
    }

    public function reject(PersonProposal $proposal, User $by): void
    {
        abort_unless($this->canDecide($by, $proposal), 403);
        $proposal->update(['status' => PersonProposal::REJECTED, 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->forgetCounts($proposal->person);
    }

    /** Qui a proposé retire sa proposition. */
    public function cancel(PersonProposal $proposal, User $by): void
    {
        abort_unless($proposal->isPending() && ! $proposal->isDispute() && $proposal->user_id === $by->id, 403);
        $proposal->update(['status' => PersonProposal::CANCELLED, 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->forgetCounts($proposal->person);
    }

    /**
     * « Je ne suis pas d'accord » : une modification d'une autre famille.
     * Peut se contester ce qui vient d'une autre famille, récent, pas encore
     * annulé, sur une fiche commune de l'une de mes familles.
     */
    public function canDispute(User $user, PersonChange $change): bool
    {
        if ($change->action !== 'updated' || $change->reverted_at || $change->proposal_id
            || ! $change->user_id || $change->user_id === $user->id
            || $change->created_at->lt(now()->subDays(self::NEWS_DAYS))
            || $this->disputableFields($change) === []) {
            return false;
        }
        $person = Person::find($change->person_id);
        if (! $person || ! $this->isShared($person)) {
            return false;
        }
        $mine = array_values(array_intersect($this->families($person)->pluck('id')->all(), $user->familyCircleIds()));
        if ($mine === []) {
            return false;
        }

        return ! DB::table('household_user')->where('user_id', $change->user_id)->whereIn('household_id', $mine)->exists();
    }

    public function dispute(PersonChange $change, User $by, string $message): PersonProposal
    {
        abort_unless($this->canDispute($by, $change), 403);
        $person = Person::findOrFail($change->person_id);

        // Seuls les champs encore à la valeur contestée reviennent en arrière.
        $fields = [];
        foreach ($this->disputableFields($change) as $field => [$old, $new]) {
            if (PersonChange::normalizeValue($field, $person->getRawOriginal($field)) === $new) {
                $fields[$field] = [$old, $new];
            }
        }
        abort_if($fields === [], 422, __('Cette information a déjà été changée depuis.'));

        $proposal = DB::transaction(function () use ($change, $by, $message, $person, $fields) {
            $author = User::find($change->user_id);
            $proposal = PersonProposal::create([
                'person_id' => $person->id,
                'household_id' => $author ? $this->familyOfUserFor($author, $person)?->id : null,
                'user_id' => $change->user_id,
                'origin' => 'dispute',
                'changes' => ['fields' => $fields],
                'person_change_id' => $change->id,
                'disputed_by' => $by->id,
            ]);
            PersonChange::forProposal($proposal->id, fn () => $person->update(
                collect($fields)->map(fn ($pair) => $pair[0])->all()
            ));
            $change->forceFill(['reverted_at' => now(), 'reverted_by' => $by->id])->save();
            $proposal->messages()->create(['user_id' => $by->id, 'body' => trim($message)]);

            return $proposal;
        });
        $this->forgetCounts($person);

        return $proposal;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function disputableFields(PersonChange $change): array
    {
        return array_intersect_key($change->diff ?? [], array_flip([...self::FIELDS, ...self::PARENT_FIELDS]));
    }

    // ── Listes et compteurs ────────────────────────────────────────────

    /**
     * Fiches communes (au moins deux familles) rattachées à l'une des
     * familles du compte.
     *
     * @return list<string>
     */
    public function sharedIdsFor(User $user): array
    {
        $mine = $user->familyCircleIds();
        if ($mine === []) {
            return [];
        }

        return DB::table('household_person as hp')
            ->join('households as h', 'h.id', '=', 'hp.household_id')
            ->where('h.kind', Household::KIND_FAMILY)
            ->whereIn('hp.person_id', DB::table('household_person')->whereIn('household_id', $mine)->select('person_id'))
            ->groupBy('hp.person_id')
            ->havingRaw('count(*) > 1')
            ->pluck('hp.person_id')
            ->all();
    }

    /** Propositions en attente sur les fiches de mes familles. */
    public function pendingFor(User $user): Builder
    {
        return PersonProposal::pending()
            ->whereIn('person_id', DB::table('household_person')->whereIn('household_id', $user->familyCircleIds())->select('person_id'));
    }

    /** @return Collection<int, PersonProposal> */
    public function toDecideFor(User $user): Collection
    {
        return $this->pendingFor($user)->with('person')->latest()->get()
            ->filter(fn (PersonProposal $p) => $this->canDecide($user, $p))
            ->values();
    }

    /**
     * Modifications des fiches communes de mes familles faites par des
     * comptes d'autres familles (hors propositions, lues à part).
     */
    public function newsFor(User $user, Carbon $since, ?array $sharedIds = null): Builder
    {
        $mine = $user->familyCircleIds();
        $members = DB::table('household_user')->whereIn('household_id', $mine)->select('user_id');

        return PersonChange::query()
            ->whereIn('person_id', $sharedIds ?? $this->sharedIdsFor($user))
            // Seulement depuis que la fiche est dans ma famille (pas l'histoire
            // de l'autre famille d'avant le partage).
            ->whereExists(fn ($q) => $q->from('household_person as hp')
                ->whereColumn('hp.person_id', 'person_changes.person_id')
                ->whereIn('hp.household_id', $mine)
                ->whereColumn('hp.created_at', '<=', 'person_changes.created_at'))
            ->whereIn('action', ['updated', 'spouse_added', 'spouse_removed'])
            ->whereNull('proposal_id')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $members)
            ->where('created_at', '>', $since)
            ->latest('created_at');
    }

    /** Mes propositions tranchées depuis $since. */
    public function decidedFor(User $user, Carbon $since): Builder
    {
        return PersonProposal::where('user_id', $user->id)
            ->where('origin', 'proposal')
            ->whereIn('status', [PersonProposal::ACCEPTED, PersonProposal::REJECTED])
            ->where('decided_by', '!=', $user->id)
            ->where('decided_at', '>', $since);
    }

    public function seenAt(User $user): Carbon
    {
        $seen = data_get($user->preferences, 'shared_fiches.seen_at');
        $floor = now()->subDays(30);

        return $seen ? max(Carbon::parse($seen), $floor) : $floor;
    }

    /**
     * Pastille « Fiches communes » : fiches communes, propositions à trancher
     * et nouveautés depuis la dernière visite (tout à zéro : rien à montrer).
     *
     * @return array{shared: int, to_decide: int, news: int}|null
     */
    public function counts(User $user): ?array
    {
        if ($user->isGuest() || $user->simple_mode || $user->familyCircleIds() === []) {
            return null;
        }

        return Cache::remember('shared-fiches-counts:'.$user->id, 120, function () use ($user) {
            $shared = $this->sharedIdsFor($user);
            $pending = $this->pendingFor($user)->exists();
            $seen = $this->seenAt($user);

            return [
                'shared' => count($shared),
                'to_decide' => $pending ? $this->toDecideFor($user)->count() : 0,
                'news' => ($shared === [] ? 0 : $this->newsFor($user, $seen, $shared)->count())
                    + $this->decidedFor($user, $seen)->count(),
            ];
        });
    }

    public function markSeen(User $user): void
    {
        $user->setPreference('shared_fiches.seen_at', now()->toIso8601String());
        Cache::forget('shared-fiches-counts:'.$user->id);
    }

    /** Les compteurs des membres des familles de la fiche sont à refaire. */
    public function forgetCounts(?Person $person): void
    {
        if (! $person) {
            return;
        }
        unset($this->families[$person->id], $this->referents[$person->id]);
        $users = DB::table('household_user')
            ->whereIn('household_id', DB::table('household_person')->where('person_id', $person->id)->select('household_id'))
            ->pluck('user_id');
        foreach ($users as $id) {
            Cache::forget('shared-fiches-counts:'.$id);
        }
    }
}
