<?php

namespace App\Http\Controllers;

use App\Models\LifeEvent;
use App\Models\LifeStoryAnswer;
use App\Models\Person;
use App\Models\User;
use App\Services\SharedFiches;
use App\Support\LifeStory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Récit de vie guidé, étape par étape (cf. LifeStory) : chaque étape arrive
 * pré-remplie de ce que la frise et l'arbre savent déjà ; ce qu'on complète
 * va droit sur la frise. Le récit libre (écrit ou à l'oral) est gardé pour
 * être relu ensuite.
 */
class LifeStoryController extends Controller
{
    private const AUDIO_EXTENSIONS = [
        'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'video/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
    ];

    public function __construct(private SharedFiches $shared) {}

    public function show(Request $request, Person $person)
    {
        $user = $request->user();
        abort_unless($user->canEditFamilyTree(), 403);

        $answers = LifeStoryAnswer::where('person_id', $person->id)->where('user_id', $user->id)->get()->keyBy('chapter');
        $chapters = LifeStory::chapters($person);
        $birthYear = $person->birth_date ? (int) $person->birth_date->format('Y') : null;

        return Inertia::render('People/LifeStory', [
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'first_name' => $person->first_name ?: $person->name,
                'birth_year' => $birthYear,
                'is_self' => $user->person_id === $person->id,
                'avatar' => $person->avatarUrl(),
            ],
            'chapters' => collect($chapters)->map(fn ($c) => [
                ...$c,
                'years' => $birthYear && $c['ages'] ? [$birthYear + $c['ages'][0], $c['ages'][1] !== null ? $birthYear + $c['ages'][1] : null] : null,
                'facts' => $this->facts($person, $c['key']),
            ])->values(),
            'rowTypes' => LifeStory::rowTypes(),
            'events' => $this->events($person, $user, $answers),
            'answers' => $answers->map(fn (LifeStoryAnswer $a) => $this->answerPayload($a))->all(),
        ]);
    }

    /**
     * Valide (ou passe) une étape : éléments datés créés ou mis à jour sur la
     * frise, ceux qu'on a retirés supprimés (si on en a le droit), récit
     * gardé.
     */
    public function save(Request $request, Person $person, string $chapter)
    {
        $user = $request->user();
        abort_unless($user->canEditFamilyTree(), 403);
        $def = LifeStory::chapter($person, $chapter) ?? abort(404);
        $data = $request->validate([
            'status' => 'required|in:done,skipped',
            'body' => 'nullable|string|max:20000',
            'rows' => 'array|max:40',
            'rows.*.id' => 'nullable|uuid',
            'rows.*.type' => ['required', Rule::in(LifeStory::allowedTypes($def))],
            'rows.*.title' => 'nullable|string|max:255',
            'rows.*.place' => 'nullable|string|max:255',
            'rows.*.from' => 'required|integer|min:1000|max:2100',
            'rows.*.to' => 'nullable|integer|min:1000|max:2100',
            'removed' => 'array|max:40',
            'removed.*' => 'uuid',
        ]);

        $types = LifeStory::rowTypes();
        $answer = LifeStoryAnswer::firstOrNew(['person_id' => $person->id, 'user_id' => $user->id, 'chapter' => $chapter]);
        $created = $answer->event_ids ?? [];

        DB::transaction(function () use ($data, $person, $user, $types, $answer, &$created) {
            foreach ($data['rows'] ?? [] as $row) {
                $place = trim((string) ($row['place'] ?? '')) ?: null;
                $title = trim((string) ($row['title'] ?? '')) ?: ($types[$row['type']]['title'] ?? $place);
                if (! $title) {
                    continue;
                }
                $to = isset($row['to']) && $row['to'] >= $row['from'] ? $row['to'] : null;
                $attributes = [
                    'type' => $row['type'],
                    'title' => $title,
                    'place' => $place,
                    'event_date' => sprintf('%04d-01-01', $row['from']),
                    'end_date' => $to ? sprintf('%04d-01-01', $to) : null,
                    'date_precision' => 'year',
                ];
                $event = ! empty($row['id']) ? LifeEvent::where('person_id', $person->id)->find($row['id']) : null;
                if ($event) {
                    if ($this->canEdit($user, $person, $event) && $this->changed($event, $attributes)) {
                        $event->update($attributes);
                    }

                    continue;
                }
                $event = LifeEvent::create($attributes + ['person_id' => $person->id, 'user_id' => $user->id]);
                $created[] = $event->id;
            }

            foreach ($data['removed'] ?? [] as $id) {
                $event = LifeEvent::where('person_id', $person->id)->find($id);
                if ($event && $this->canDelete($user, $person, $event)) {
                    $event->delete();
                    $created = array_values(array_diff($created, [$id]));
                }
            }

            $answer->fill([
                'status' => $data['status'],
                'body' => filled($data['body'] ?? null) ? trim($data['body']) : null,
                'event_ids' => array_values(array_unique($created)),
            ])->save();
        });

        $answers = LifeStoryAnswer::where('person_id', $person->id)->where('user_id', $user->id)->get()->keyBy('chapter');

        return response()->json([
            'events' => $this->events($person, $user, $answers),
            'answer' => $this->answerPayload($answer->fresh()),
        ]);
    }

    /** Récit à l'oral d'une étape (remplace le précédent). */
    public function audio(Request $request, Person $person, string $chapter)
    {
        $user = $request->user();
        abort_unless($user->canEditFamilyTree(), 403);
        LifeStory::chapter($person, $chapter) ?? abort(404);
        $request->validate(['audio' => 'required|file|max:204800|mimetypes:'.implode(',', array_keys(self::AUDIO_EXTENSIONS))]);
        $file = $request->file('audio');
        $mime = $file->getMimeType();
        $extension = self::AUDIO_EXTENSIONS[$mime] ?? 'webm';

        $answer = LifeStoryAnswer::firstOrNew(['person_id' => $person->id, 'user_id' => $user->id, 'chapter' => $chapter]);
        $old = $answer->audio_path;
        $path = "life-story/{$person->id}/{$chapter}-".Str::lower(Str::random(10)).".{$extension}";
        Storage::put($path, $file->get(), ['ContentType' => str_replace('video/', 'audio/', $mime), 'visibility' => 'private']);
        $seconds = (float) trim(Process::timeout(60)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file->getRealPath()])->output());
        $answer->fill([
            'audio_path' => $path,
            'audio_duration' => $seconds > 0 ? (int) round($seconds) : ((int) $request->input('duration', 0) ?: null),
            'transcript' => null,
        ])->save();
        if ($old) {
            Storage::delete($old);
        }

        return response()->json(['answer' => $this->answerPayload($answer->fresh()), 'message' => __('Enregistré.')]);
    }

    public function audioFile(Request $request, LifeStoryAnswer $answer)
    {
        abort_unless($answer->audio_path && $answer->isVisibleTo($request->user()), 404);

        return redirect()
            ->away(Storage::temporaryUrl($answer->audio_path, now()->addMinutes(30)))
            ->header('Cache-Control', 'private, max-age=300');
    }

    // ── Présentation ───────────────────────────────────────────────────

    private function answerPayload(LifeStoryAnswer $answer): array
    {
        return [
            'status' => $answer->status,
            'body' => $answer->body,
            'event_ids' => $answer->event_ids ?? [],
            'audio_url' => $answer->audio_path ? route('lifeStory.audio', $answer) : null,
            'audio_duration' => $answer->audio_duration,
        ];
    }

    /**
     * Moments de la frise de la personne, rangés dans l'étape de leur
     * période (les lieux de vie dans chaque étape qu'ils traversent).
     */
    private function events(Person $person, User $user, $answers): array
    {
        $birthYear = $person->birth_date ? (int) $person->birth_date->format('Y') : null;
        $mine = $answers->flatMap(fn ($a) => $a->event_ids ?? [])->flip();
        $chapters = collect(LifeStory::chapters($person));

        return LifeEvent::where('person_id', $person->id)->orderBy('event_date')->get()
            ->map(function (LifeEvent $e) use ($birthYear, $mine, $chapters, $user, $person) {
                $from = (int) $e->event_date->format('Y');
                $to = $e->end_date ? (int) $e->end_date->format('Y') : null;

                return [
                    'id' => $e->id,
                    'type' => $e->type,
                    'title' => $e->title,
                    'place' => $e->place,
                    'from' => $from,
                    'to' => $to,
                    'chapters' => $this->chaptersOf($e->type, $e->title, $from, $to, $birthYear, $chapters),
                    'mine' => isset($mine[$e->id]),
                    'editable' => $this->canEdit($user, $person, $e),
                    'deletable' => $this->canDelete($user, $person, $e),
                ];
            })->values()->all();
    }

    /** Étape(s) où un moment de la frise apparaît. */
    private function chaptersOf(string $type, string $title, int $from, ?int $to, ?int $birthYear, $chapters): array
    {
        $age = $birthYear !== null ? $from - $birthYear : null;
        $byAge = fn (array $keys) => collect($keys)->first(function ($key) use ($chapters, $age) {
            [$min, $max] = $chapters->firstWhere('key', $key)['ages'];

            return $age !== null && $age >= $min && ($max === null || $age < $max);
        });

        if ($type === 'residence') {
            if ($birthYear === null) {
                return ['naissance'];
            }
            $until = $to ?? (int) now()->format('Y');

            return $chapters->filter(fn ($c) => $c['moves'] && $c['ages'] !== null
                && $birthYear + $c['ages'][0] <= $until && ($c['ages'][1] === null || $from < $birthYear + $c['ages'][1]))
                ->pluck('key')->values()->all() ?: ['aujourdhui'];
        }

        $key = match ($type) {
            'bapteme' => 'naissance',
            'communion', 'confirmation' => 'primaire',
            'education' => $byAge(['primaire', 'college', 'lycee', 'etudes'])
                ?? match (true) {
                    (bool) preg_match('/lyc[ée]e/iu', $title) => 'lycee',
                    (bool) preg_match('/coll[èe]ge/iu', $title) => 'college',
                    (bool) preg_match('/[ée]cole/iu', $title) && $age !== null && $age < 11 => 'primaire',
                    default => 'etudes',
                },
            'diplome' => $age !== null && $age < 18 ? 'lycee' : 'etudes',
            'military' => 'service',
            'job' => 'travail',
            'fiancailles', 'mariage', 'mariage_religieux' => 'couple',
            'voyage' => 'voyages',
            default => $age !== null && $age >= 60 ? 'aujourdhui' : 'voyages',
        };

        return [$key];
    }

    /**
     * Ce que l'arbre sait déjà (naissance, unions, enfants, décès) : affiché
     * dans l'étape, jamais recopié sur la frise (il y est déjà).
     *
     * @return list<string>
     */
    private function facts(Person $person, string $chapter): array
    {
        $date = fn ($d) => $d?->locale(app()->getLocale())->translatedFormat('j F Y');
        $at = fn (?string $place) => $place ? ' '.__('à :place', ['place' => $place]) : '';

        return match ($chapter) {
            'naissance' => $person->birth_date || $person->birth_place
                ? [__('Naissance').' : '.trim(($date($person->birth_date) ?? '').$at($person->birth_place))]
                : [],
            'couple' => $person->spouses->map(fn ($s) => __('Union avec :name', ['name' => $s->name])
                .($s->pivot->start_date ? ', '.$date(\Illuminate\Support\Carbon::parse($s->pivot->start_date)) : '')
                .$at($s->pivot->start_place ?? null))->values()->all(),
            'enfants' => Person::where('father_id', $person->id)->orWhere('mother_id', $person->id)->orderBy('birth_date')->get()
                ->map(fn ($c) => $c->name.($c->birth_date ? ', '.__('né(e) le :date', ['date' => $date($c->birth_date)]) : '').$at($c->birth_place))
                ->values()->all(),
            'aujourdhui' => $person->death_date ? [__('Décès').' : '.trim($date($person->death_date).$at($person->death_place))] : [],
            default => [],
        };
    }

    private function canEdit(User $user, Person $person, LifeEvent $event): bool
    {
        return $event->user_id === $user->id || ! $this->shared->isShared($person) || $this->shared->isReferentMember($user, $person);
    }

    private function canDelete(User $user, Person $person, LifeEvent $event): bool
    {
        return ($user->isAdmin() || $event->user_id === $user->id || $person->user_id === $user->id) && $this->canEdit($user, $person, $event);
    }

    private function changed(LifeEvent $event, array $attributes): bool
    {
        return $event->type !== $attributes['type'] || $event->title !== $attributes['title'] || $event->place !== $attributes['place']
            || $event->event_date->format('Y') !== substr($attributes['event_date'], 0, 4)
            || $event->end_date?->format('Y') !== ($attributes['end_date'] ? substr($attributes['end_date'], 0, 4) : null);
    }
}
