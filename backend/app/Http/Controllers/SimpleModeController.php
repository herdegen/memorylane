<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\CelebrationService;
use App\Services\GenealogyService;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Mode grand-parent : interface tablette réduite à trois actions (regarder
 * les photos, voir sa famille, raconter une photo). Mêmes droits de lecture
 * que n'importe quel membre (Media::accessibleBy) ; seule écriture possible :
 * les mémos vocaux.
 */
class SimpleModeController extends Controller
{
    /** Liens de parenté au plus pour l'écran « Ma famille ». */
    private const FAMILY_MAX_STEPS = 3;

    /** Ordre d'affichage de la famille : conjoint, enfants, petits-enfants… */
    private const FAMILY_ORDER = ['spouse', 'child', 'child.child', 'child.child.child', 'parent.child', 'parent', 'parent.parent'];

    /** Diaporama : nombre maximal de photos chargées d'un coup. */
    private const PHOTOS_LIMIT = 300;

    public function __construct(
        protected MediaService $mediaService,
        protected CelebrationService $celebrations,
        protected GenealogyService $genealogy,
    ) {}

    public function home(Request $request)
    {
        $user = $request->user();

        // Mode grand-parent = mode famille : un compte normal dans un cercle
        // d'amis n'a rien à y faire (les comptes tablette, eux, y restent).
        if (! $user->simple_mode && $user->mode() === \App\Models\Household::KIND_FRIENDS) {
            return redirect()->route('dashboard');
        }

        $person = $user->person_id
            ? Person::with('avatar')->withMatchedFacesCount()->find($user->person_id)
            : null;
        $today = today();

        return Inertia::render('Simple/Home', [
            'firstName' => $person ? self::shortName($person) : strtok($user->name, ' '),
            'avatarUrl' => $person ? $this->avatarUrl($person) : null,
            'today' => $today->toDateString(),
            'memory' => $this->memoryOfTheDay($user, $today),
            'celebration' => $this->nextCelebration($user, $today),
        ]);
    }

    public function photos(Request $request)
    {
        $validated = $request->validate([
            'person' => 'nullable|uuid',
            'start' => 'nullable|uuid',
        ]);
        $user = $request->user();
        $today = today();

        $query = $this->photoQuery($user);
        $person = null;

        if (! empty($validated['person'])) {
            $person = Person::visibleTo($user)->find($validated['person']);
            abort_unless($person, 404);
            $query->whereHas('people', fn ($p) => $p->where('people.id', $validated['person']))
                ->orderByRaw('taken_at IS NULL')
                ->orderBy('taken_at');
        } else {
            // Souvenirs du jour d'abord, puis un mélange qui change chaque jour.
            $query->orderByRaw(
                '(EXTRACT(MONTH FROM taken_at) = ? AND EXTRACT(DAY FROM taken_at) = ?) IS TRUE DESC',
                [$today->month, $today->day],
            )->orderByRaw('md5(media.id::text || ?)', [$today->toDateString()]);
        }

        $media = $query->limit(self::PHOTOS_LIMIT)->get();
        $photos = $media->map(fn (Media $m) => $this->photoPayload($m, $user))->values();

        $startIndex = 0;
        if (! empty($validated['start'])) {
            $startIndex = max(0, $photos->search(fn ($p) => $p['id'] === $validated['start']) ?: 0);
        }

        return Inertia::render('Simple/Photos', [
            'photos' => $photos,
            'startIndex' => $startIndex,
            'personName' => $person ? self::shortName($person) : null,
        ]);
    }

    public function family(Request $request)
    {
        $user = $request->user();
        $members = [];

        if ($user->person_id) {
            $paths = $this->genealogy->pathsFrom($user->person_id, self::FAMILY_MAX_STEPS);
            $people = Person::visibleTo($user)->whereIn('id', array_keys($paths))
                ->with('avatar.conversions')
                ->withMatchedFacesCount()
                ->get();

            foreach ($people as $p) {
                $edges = $paths[$p->id];
                $label = GenealogyService::relationLabel($edges, $p->gender);
                if ($label === null) {
                    continue;
                }
                $rank = array_search(implode('.', $edges), self::FAMILY_ORDER, true);
                $members[] = [
                    'id' => $p->id,
                    'name' => self::shortName($p),
                    'relation' => $label,
                    'avatar_url' => $this->avatarUrl($p),
                    'deceased' => $p->death_date !== null,
                    'sort' => [$rank === false ? count(self::FAMILY_ORDER) : $rank, count($edges), $p->birth_date?->timestamp ?? PHP_INT_MAX],
                ];
            }

            usort($members, fn ($a, $b) => $a['sort'] <=> $b['sort']);
            $members = array_map(fn ($m) => array_diff_key($m, ['sort' => true]), $members);
        }

        return Inertia::render('Simple/Family', ['members' => $members]);
    }

    public function record(Request $request, Media $media)
    {
        Gate::authorize('view', $media);
        $media->load(['conversions', 'people', 'albums'])->loadCount('voiceNotes');

        return Inertia::render('Simple/Record', [
            'photo' => $this->photoPayload($media, $request->user()),
            'maxSeconds' => \App\Models\VoiceNote::MAX_SECONDS,
        ]);
    }

    /** Photos visibles par le membre, avec ce qu'il faut pour les légendes. */
    private function photoQuery(User $user)
    {
        return Media::accessibleBy($user)
            ->where('type', 'photo')
            ->with(['conversions', 'people', 'albums'])
            ->withCount('voiceNotes');
    }

    /**
     * Souvenir du jour : une photo prise un même jour les années passées,
     * sinon une photo datée tirée au sort (la même toute la journée).
     */
    private function memoryOfTheDay(User $user, Carbon $today): ?array
    {
        $seed = $today->toDateString();

        $media = $this->photoQuery($user)
            ->whereNotNull('taken_at')
            ->whereRaw('EXTRACT(MONTH FROM taken_at) = ?', [$today->month])
            ->whereRaw('EXTRACT(DAY FROM taken_at) = ?', [$today->day])
            ->whereRaw('EXTRACT(YEAR FROM taken_at) < ?', [$today->year])
            ->orderByRaw('md5(media.id::text || ?)', [$seed])
            ->first();

        $sameDay = $media !== null;
        $media ??= $this->photoQuery($user)
            ->whereNotNull('taken_at')
            ->orderByRaw('md5(media.id::text || ?)', [$seed])
            ->first();

        if (! $media) {
            return null;
        }

        $years = $today->year - $media->taken_at->year;

        return $this->photoPayload($media, $user) + [
            'heading' => $sameDay
                ? ($years === 1 ? 'Il y a un an, jour pour jour' : "Il y a {$years} ans, jour pour jour")
                : 'Un souvenir pour aujourd\'hui',
        ];
    }

    /** Prochain anniversaire du cercle proche (aujourd'hui ou d'ici 15 jours). */
    private function nextCelebration(User $user, Carbon $today): ?array
    {
        $next = $this->celebrations
            ->upcomingBirthdays($today, 15, $this->celebrations->closeCircleQuery($user)->with('avatar.conversions')->withMatchedFacesCount())
            ->sortBy('days_until')
            ->first();

        if (! $next) {
            return null;
        }

        $person = $next['person'];
        $relation = null;
        if ($user->person_id && $path = $this->genealogy->pathBetween($user->person_id, $person->id)) {
            $relation = GenealogyService::relationLabel($path['edges'], $person->gender);
        }

        return [
            'person_id' => $person->id,
            'name' => self::shortName($person),
            'age' => $next['age'],
            'days_until' => $next['days_until'],
            'relation' => $relation,
            'avatar_url' => $this->avatarUrl($person),
        ];
    }

    /** Photo + légende lisible (titre utile, date, qui est dessus). */
    private function photoPayload(Media $media, User $user): array
    {
        return [
            'id' => $media->id,
            'url' => $this->mediaService->thumbnailUrl($media, ['large', 'medium']),
            'title' => $this->caption($media),
            'when' => $media->taken_at ? self::whenLabel($media->taken_at) : null,
            'who' => $media->people
                ->map(fn (Person $p) => $p->id === $user->person_id ? 'vous' : self::shortName($p))
                ->values()
                ->all(),
            'voice_notes_count' => $media->voice_notes_count ?? 0,
        ];
    }

    /** Titre saisi s'il est parlant, sinon le nom de l'album, sinon rien. */
    private function caption(Media $media): ?string
    {
        $title = trim((string) $media->title);
        $looksLikeFile = $title === ''
            || preg_match('/\.(jpe?g|png|heic|heif|webp|gif)$/i', $title)
            || preg_match('/^(IMG|DSC|PXL|VID|MVIMG|Screenshot|WhatsApp|PANO)[\s_-]/i', $title)
            || preg_match('/^[\d_\-\s]+$/', $title);

        if (! $looksLikeFile) {
            return $title;
        }

        return $media->albums->first()?->name;
    }

    /** « juillet 1978 » (ou « 1978 » si le jour est le 1er janvier, souvent une date approximative). */
    public static function whenLabel(Carbon $date): string
    {
        if ($date->month === 1 && $date->day === 1) {
            return (string) $date->year;
        }

        return $date->locale('fr')->translatedFormat('F Y');
    }

    /** Premier prénom usuel (« Suzanne » pour « Suzanne Geneviève Clothilde »). */
    public static function shortName(Person $person): string
    {
        return strtok(trim($person->first_name ?: $person->name), ' ') ?: $person->name;
    }

    private function avatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return route('people.avatarImage', $person);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }
}
