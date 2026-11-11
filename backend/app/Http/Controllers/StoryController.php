<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Story;
use App\Models\StoryChapter;
use App\Models\StoryNote;
use App\Models\StoryQuestion;
use App\Models\User;
use App\Services\GenealogyService;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Histoires de famille : bibliothèque, lecture, écriture, et les ajouts des
 * lecteurs (« Je sais quelque chose »). Une histoire suit sa personne
 * principale : qui voit cette fiche peut la lire une fois publiée.
 */
class StoryController extends Controller
{
    public function __construct(
        private GenealogyService $genealogy,
        private MediaService $mediaService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $closeness = $this->genealogy->closeness($user->person_id);

        $stories = Story::visibleTo($user)
            ->with(['people', 'chapters'])
            ->get()
            ->sortBy(fn (Story $s) => [$closeness[$s->mainPerson()?->id] ?? PHP_INT_MAX, -($s->published_at ?? $s->updated_at)->timestamp])
            ->values();

        $covers = $this->accessibleMedia($user, $stories->map(fn (Story $s) => $s->mediaIds()[0] ?? null)->filter()->all());
        $this->preparePaths($user, $stories->map(fn (Story $s) => $s->mainPerson())->filter());

        return Inertia::render('Stories/Index', [
            'stories' => $stories->map(fn (Story $s) => $this->cardPayload($s, $user, $covers))->all(),
        ]);
    }

    public function show(Request $request, Story $story)
    {
        $user = $request->user();
        abort_unless($story->isVisibleTo($user), 404);
        $story->load(['people', 'chapters', 'questions.notes.user', 'notes.user', 'user', 'album']);

        $media = $this->accessibleMedia($user, $story->mediaIds());
        $this->preparePaths($user, $story->people);

        return Inertia::render('Stories/Show', [
            'story' => [
                ...$this->basePayload($story),
                'author' => $story->user?->name,
                'published' => $story->published_at !== null,
                'people' => $this->peoplePayload($story, $user),
                'chapters' => $story->chapters->map(fn (StoryChapter $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'when_label' => $c->when_label,
                    // Documents que le lecteur ne voit pas : retirés du récit.
                    'blocks' => collect($c->blocks)
                        ->map(fn ($b) => ($b['type'] ?? null) === 'media'
                            ? (isset($media[$b['media_id'] ?? '']) ? $b + ['media' => $media[$b['media_id']]] : null)
                            : $b)
                        ->filter()->values()->all(),
                ])->all(),
                'disagreements' => self::lines($story->disagreements),
                'questions' => $story->questions->map(fn (StoryQuestion $q) => [
                    'id' => $q->id,
                    'text' => $q->text,
                    'notes' => $q->notes->sortBy('created_at')->map(fn (StoryNote $n) => $this->notePayload($n, $user, $story))->values()->all(),
                ])->all(),
                'notes' => $story->notes->whereNull('question_id')->map(fn (StoryNote $n) => $this->notePayload($n, $user, $story))->values()->all(),
                'documents_count' => count($media),
                'reading_minutes' => $this->readingMinutes($story),
                'album' => $story->album && Album::accessibleBy($user)->whereKey($story->album_id)->exists()
                    ? ['id' => $story->album->id, 'name' => $story->album->name] : null,
            ],
            'canEdit' => $story->canEdit($user),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate(['album' => 'nullable|uuid', 'person' => 'nullable|uuid']);
        $people = collect();
        $album = null;

        if (! empty($validated['album'])) {
            $album = Album::accessibleBy($user)->find($validated['album']);
            abort_unless($album, 404);
            // Les personnes identifiées sur l'album, les plus présentes d'abord.
            $people = Person::visibleTo($user)
                ->whereHas('media', fn ($m) => $m->whereHas('albums', fn ($a) => $a->where('albums.id', $album->id)))
                ->withCount(['media as album_media_count' => fn ($m) => $m->whereHas('albums', fn ($a) => $a->where('albums.id', $album->id))])
                ->orderByDesc('album_media_count')
                ->limit(12)
                ->get();
        }
        if (! empty($validated['person'])) {
            $person = Person::visibleTo($user)->find($validated['person']);
            abort_unless($person, 404);
            $people = $people->reject(fn ($p) => $p->id === $person->id)->prepend($person);
        }

        $chapters = [];
        if ($album) {
            // Brouillon de départ : un chapitre avec les documents de l'album dans l'ordre.
            $ids = Media::accessibleBy($user)
                ->whereHas('albums', fn ($a) => $a->where('albums.id', $album->id))
                ->orderByRaw('taken_at IS NULL, taken_at')->orderBy('created_at')
                ->limit(60)->pluck('id');
            $media = $this->accessibleMedia($user, $ids->all());
            $chapters[] = [
                'title' => $album->name,
                'when_label' => null,
                'blocks' => $ids->map(fn ($id) => ['type' => 'media', 'media_id' => $id, 'caption' => null, 'media' => $media[$id] ?? null])->all(),
            ];
        }

        return Inertia::render('Stories/Edit', [
            'story' => [
                'id' => null,
                'title' => $album?->name ?? '',
                'summary' => $album?->description ?? '',
                'kind' => 'memory',
                'credits' => '',
                'sensitive' => false,
                'disagreements' => '',
                'album_id' => $album?->id,
                'published' => false,
                'people' => $people->values()->map(fn (Person $p, $i) => $this->editPersonPayload($p, $i === 0, null))->all(),
                'chapters' => $chapters,
                'questions' => [],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $story = DB::transaction(function () use ($data, $user) {
            $story = Story::create(['user_id' => $user->id] + $this->attributes($data));
            $this->sync($story, $data);

            return $story;
        });

        return redirect()->route('stories.show', $story)
            ->with('success', $story->published_at ? __('Histoire publiée') : __('Brouillon enregistré'));
    }

    public function edit(Request $request, Story $story)
    {
        $user = $request->user();
        abort_unless($story->canEdit($user) && $story->isVisibleTo($user), 403);
        $story->load(['people', 'chapters', 'questions']);
        $media = $this->accessibleMedia($user, $story->mediaIds());

        return Inertia::render('Stories/Edit', [
            'story' => [
                ...$this->basePayload($story),
                'disagreements' => $story->disagreements ?? '',
                'album_id' => $story->album_id,
                'published' => $story->published_at !== null,
                'people' => $story->people->map(fn (Person $p) => $this->editPersonPayload($p, (bool) $p->pivot->is_main, $p->pivot->role))->all(),
                'chapters' => $story->chapters->map(fn (StoryChapter $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'when_label' => $c->when_label,
                    'blocks' => collect($c->blocks)->map(fn ($b) => ($b['type'] ?? null) === 'media'
                        ? $b + ['media' => $media[$b['media_id'] ?? ''] ?? null]
                        : $b)->all(),
                ])->all(),
                'questions' => $story->questions->map(fn (StoryQuestion $q) => ['id' => $q->id, 'text' => $q->text])->all(),
            ],
        ]);
    }

    public function update(Request $request, Story $story)
    {
        $user = $request->user();
        abort_unless($story->canEdit($user), 403);
        $data = $this->validated($request, $user, $story);

        DB::transaction(function () use ($story, $data) {
            $story->update($this->attributes($data, $story));
            $this->sync($story, $data);
        });

        return redirect()->route('stories.show', $story)->with('success', __('Histoire enregistrée'));
    }

    public function destroy(Request $request, Story $story)
    {
        abort_unless($story->canEdit($request->user()), 403);
        $story->delete();

        return redirect()->route('stories.index')->with('success', __('Histoire supprimée'));
    }

    /** « Je sais quelque chose » : un ajout signé, lisible par tous les lecteurs. */
    public function storeNote(Request $request, Story $story)
    {
        $user = $request->user();
        abort_unless($story->isVisibleTo($user) && $story->published_at, 404);
        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'question_id' => ['nullable', 'uuid', Rule::exists('story_questions', 'id')->where('story_id', $story->id)],
        ]);

        $story->notes()->create(['user_id' => $user->id, 'question_id' => $data['question_id'] ?? null, 'body' => trim($data['body'])]);

        return back()->with('success', __('Merci, c’est ajouté à l’histoire'));
    }

    public function destroyNote(Request $request, StoryNote $note)
    {
        $user = $request->user();
        abort_unless($note->user_id === $user->id || $note->story?->canEdit($user), 403);
        $note->delete();

        return back()->with('success', __('Ajout retiré'));
    }

    // ------------------------------------------------------------------ données

    private function validated(Request $request, User $user, ?Story $story = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'summary' => 'nullable|string|max:2000',
            'kind' => ['required', Rule::in(Story::KINDS)],
            'credits' => 'nullable|string|max:255',
            'sensitive' => 'boolean',
            'disagreements' => 'nullable|string|max:5000',
            'album_id' => 'nullable|uuid',
            'publish' => 'boolean',
            'people' => 'required|array|min:1|max:30',
            'people.*.id' => 'required|uuid|distinct',
            'people.*.role' => 'nullable|string|max:120',
            'people.*.is_main' => 'boolean',
            'chapters' => 'array|max:40',
            'chapters.*.title' => 'required|string|max:200',
            'chapters.*.when_label' => 'nullable|string|max:100',
            'chapters.*.blocks' => 'array|max:80',
            'chapters.*.blocks.*.type' => 'required|in:text,quote,media',
            'chapters.*.blocks.*.text' => 'nullable|string|max:20000',
            'chapters.*.blocks.*.cite' => 'nullable|string|max:255',
            'chapters.*.blocks.*.media_id' => 'nullable|uuid',
            'chapters.*.blocks.*.caption' => 'nullable|string|max:255',
            'questions' => 'array|max:30',
            'questions.*.id' => 'nullable|uuid',
            'questions.*.text' => 'required|string|max:500',
        ], [], [
            'people' => __('personnes'),
        ]);

        // Personnes : seulement des fiches que l'auteur voit.
        $ids = collect($data['people'])->pluck('id');
        $visible = Person::visibleTo($user)->whereIn('id', $ids)->pluck('id');
        abort_if($visible->count() !== $ids->count(), 422, __('Une des personnes n’est pas visible pour vous.'));

        // Documents : seulement des médias que l'auteur voit (ceux déjà cités restent).
        $mediaIds = collect($data['chapters'] ?? [])->flatMap(fn ($c) => collect($c['blocks'] ?? [])->where('type', 'media')->pluck('media_id'))->filter()->unique();
        $already = $story ? collect($story->mediaIds()) : collect();
        $newIds = $mediaIds->diff($already);
        if ($newIds->isNotEmpty()) {
            abort_if(Media::accessibleBy($user)->whereIn('id', $newIds)->count() !== $newIds->count(), 422, __('Un des documents n’est pas visible pour vous.'));
        }

        if (! empty($data['album_id'])) {
            $data['album_id'] = Album::accessibleBy($user)->whereKey($data['album_id'])->exists() ? $data['album_id'] : null;
        }

        return $data;
    }

    private function attributes(array $data, ?Story $story = null): array
    {
        $publish = (bool) ($data['publish'] ?? false);

        return [
            'title' => trim($data['title']),
            'summary' => self::clean($data['summary'] ?? null),
            'kind' => $data['kind'],
            'credits' => self::clean($data['credits'] ?? null),
            'sensitive' => (bool) ($data['sensitive'] ?? false),
            'disagreements' => self::clean($data['disagreements'] ?? null),
            'album_id' => $data['album_id'] ?? $story?->album_id,
            'published_at' => $publish ? ($story?->published_at ?? now()) : null,
        ];
    }

    private function sync(Story $story, array $data): void
    {
        $people = collect($data['people'])->values();
        $mainIndex = $people->search(fn ($p) => ! empty($p['is_main']));
        $story->people()->sync($people->mapWithKeys(fn ($p, $i) => [$p['id'] => [
            'is_main' => $i === ($mainIndex === false ? 0 : $mainIndex),
            'role' => self::clean($p['role'] ?? null),
            'position' => $i,
        ]])->all());

        $story->chapters()->delete();
        foreach (array_values($data['chapters'] ?? []) as $i => $c) {
            $blocks = collect($c['blocks'] ?? [])->map(fn ($b) => match ($b['type']) {
                'text' => filled($b['text'] ?? null) ? ['type' => 'text', 'text' => trim($b['text'])] : null,
                'quote' => filled($b['text'] ?? null) ? ['type' => 'quote', 'text' => trim($b['text']), 'cite' => self::clean($b['cite'] ?? null)] : null,
                'media' => filled($b['media_id'] ?? null) ? ['type' => 'media', 'media_id' => $b['media_id'], 'caption' => self::clean($b['caption'] ?? null)] : null,
            })->filter()->values()->all();
            $story->chapters()->create(['position' => $i, 'title' => trim($c['title']), 'when_label' => self::clean($c['when_label'] ?? null), 'blocks' => $blocks]);
        }

        // Questions : gardées par identifiant (les réponses de la famille y restent attachées).
        $keep = [];
        foreach (array_values($data['questions'] ?? []) as $i => $q) {
            $question = ! empty($q['id']) ? $story->questions()->find($q['id']) : null;
            $question ? $question->update(['position' => $i, 'text' => trim($q['text'])])
                : $question = $story->questions()->create(['position' => $i, 'text' => trim($q['text'])]);
            $keep[] = $question->id;
        }
        $story->questions()->whereNotIn('id', $keep)->delete();
    }

    // ------------------------------------------------------------------ affichage

    private function basePayload(Story $story): array
    {
        return [
            'id' => $story->id,
            'title' => $story->title,
            'summary' => $story->summary,
            'kind' => $story->kind,
            'credits' => $story->credits,
            'sensitive' => $story->sensitive,
            'period' => $this->period($story),
        ];
    }

    private function cardPayload(Story $story, User $user, array $covers): array
    {
        $main = $story->mainPerson();

        return [
            ...$this->basePayload($story),
            'published' => $story->published_at !== null,
            'cover' => $covers[$story->mediaIds()[0] ?? ''] ?? null,
            'main' => $main ? ['id' => $main->id, 'name' => $main->name, 'avatar_url' => $main->avatarUrl(), 'relation' => $this->relation($user, $main)] : null,
            'people_count' => $story->people->count(),
            'chapters_count' => $story->chapters->count(),
        ];
    }

    private function peoplePayload(Story $story, User $user): array
    {
        $visible = Person::visibleTo($user)->whereIn('id', $story->people->pluck('id'))->pluck('id')->flip();

        return $story->people
            ->filter(fn (Person $p) => isset($visible[$p->id]))
            ->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'avatar_url' => $p->avatarUrl(),
                'is_main' => (bool) $p->pivot->is_main,
                'role' => $p->pivot->role,
                'relation' => $this->relation($user, $p),
                'years' => trim(($p->birth_date?->year ?? '').($p->death_date ? '–'.$p->death_date->year : ''), '–') ?: null,
            ])->values()->all();
    }

    private function editPersonPayload(Person $p, bool $main, ?string $role): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'avatar_url' => $p->avatarUrl(), 'is_main' => $main, 'role' => $role];
    }

    private function notePayload(StoryNote $note, User $user, Story $story): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->user?->name,
            'date' => $note->created_at?->toDateString(),
            'can_delete' => $note->user_id === $user->id || $story->canEdit($user),
        ];
    }

    /** Chemins du lecteur vers ces personnes (l'arbre lu une seule fois). */
    private array $paths = [];

    private function preparePaths(User $user, iterable $people): void
    {
        if ($user->person_id && $user->mode() !== 'friends') {
            $this->paths = $this->genealogy->pathsBetween($user->person_id, collect($people)->pluck('id')->all());
        }
    }

    /** Lien du lecteur à une personne : nom usuel, sinon lien lointain. */
    private function relation(User $user, Person $person): ?string
    {
        if (! $user->person_id || $user->mode() === 'friends') {
            return null;
        }
        if ($user->person_id === $person->id) {
            return __('vous');
        }
        $path = $this->paths[$person->id] ?? null;
        if (! $path) {
            return null;
        }

        return GenealogyService::relationLabel($path['edges'], $person->gender)
            ?? GenealogyService::distantRelationLabel($path, Person::whereIn('id', $path['ids'])->get()->keyBy('id')->all());
    }

    /** [id => {id, url, thumb, title, description, transcription, date}] des médias visibles. */
    private function accessibleMedia(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Media::accessibleBy($user)->whereIn('id', $ids)->with('conversions')->get()
            ->mapWithKeys(fn (Media $m) => [$m->id => [
                'id' => $m->id,
                'type' => $m->type,
                'url' => $this->mediaService->thumbnailUrl($m, ['large', 'medium']),
                'thumb' => $this->mediaService->thumbnailUrl($m, ['small', 'thumbnail']),
                'title' => $m->title,
                'description' => $m->description,
                'transcription' => $m->transcription,
            ]])->all();
    }

    /** « 1890 – 1922 » d'après les repères des chapitres (années citées). */
    private function period(Story $story): ?string
    {
        $years = $story->relationLoaded('chapters')
            ? $story->chapters->flatMap(fn ($c) => preg_match_all('/\b(1[0-9]{3}|20[0-9]{2})\b/', (string) $c->when_label, $m) ? $m[1] : [])->map(fn ($y) => (int) $y)
            : collect();

        if ($years->isEmpty()) {
            return null;
        }

        return $years->min() === $years->max() ? (string) $years->min() : $years->min().' – '.$years->max();
    }

    private function readingMinutes(Story $story): int
    {
        $words = $story->chapters->sum(fn ($c) => collect($c->blocks)->sum(fn ($b) => str_word_count(strip_tags($b['text'] ?? ''), 0, 'àâäéèêëîïôöùûüçœÀÂÉÈÊÎÔÙÛÇŒ’\'-')));

        return max(1, (int) round(($words + str_word_count((string) $story->summary)) / 200));
    }

    private static function lines(?string $text): array
    {
        return collect(preg_split('/\R/', (string) $text))->map(fn ($l) => trim(ltrim(trim($l), '-•*')))->filter()->values()->all();
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
