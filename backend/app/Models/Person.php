<?php

namespace App\Models;

use App\Observers\PersonObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

#[ObservedBy(PersonObserver::class)]
class Person extends Model
{
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'notes' => $this->notes ?? null,
        ];
    }

    /**
     * Réindexe seulement si un champ indexé a changé (Scout réindexait à
     * chaque sauvegarde, même pour un compteur ou une date technique).
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged(['name', 'first_name', 'last_name', 'notes']);
    }

    protected $table = 'people';

    protected $fillable = [
        'user_id',
        'in_family_directory',
        'name',
        'first_name',
        'last_name',
        'slug',
        'maiden_name',
        'birth_date',
        'birth_place',
        'death_date',
        'death_place',
        'address',
        'avatar_media_id',
        'notes',
        'father_id',
        'mother_id',
        'gender',
        'gedcom_id',
    ];

    /**
     * L'adresse est confidentielle : les fiches sont lisibles par tout compte
     * connecté et Person est sérialisé partout (index, arbre, fratries…).
     * Masquée par défaut, elle n'est rendue visible (makeVisible) que sur la
     * fiche, pour le propriétaire/admin ou le foyer avec opt-in.
     */
    protected $hidden = [
        'portrait_path',
        'portrait_source',
        'address',
        'address_city',
        'address_latitude',
        'address_longitude',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Lieux de naissance et de décès saisis ou modifiés : géocodés en
        // tâche de fond (carte « Dans le temps », récit de vie).
        static::saved(function ($person) {
            \App\Jobs\GeocodePendingPlaces::enqueueChanged($person, ['birth_place', 'death_place']);
        });

        // Une nouvelle fiche de famille entre dans le cercle famille de qui la
        // crée (son cercle courant), si on ne l'a pas déjà rangée ailleurs ;
        // auteur hors de toute famille (admin) et une seule famille sur
        // l'installation : dans celle-ci. (Non renseigné = valeur par défaut
        // de la colonne : fiche de famille.)
        static::created(function ($person) {
            if ($person->in_family_directory === false) {
                return;
            }
            $by = auth()->user() ?? $person->user;
            $circle = $by?->currentFamilyCircle();
            if (! $circle) {
                $families = Household::where('kind', Household::KIND_FAMILY)->limit(2)->get(['id']);
                $circle = $families->count() === 1 ? $families->first() : null;
            }
            if ($circle && ! $person->circles()->where('kind', Household::KIND_FAMILY)->exists()) {
                $person->circles()->syncWithoutDetaching([$circle->id => ['added_by' => $by?->id]]);
            }
        });

        // Nouvelle photo de profil → portrait stocké à refaire.
        static::saved(function ($person) {
            if ($person->wasChanged('avatar_media_id') || ($person->wasRecentlyCreated && $person->avatar_media_id)) {
                \App\Jobs\RefreshPersonPortrait::for($person->id);
            }
        });

        // name reste le nom d'affichage : composé depuis prénom + nom
        // quand ils sont renseignés (saving passe avant creating/updating)
        static::saving(function ($person) {
            if ($person->first_name || $person->last_name) {
                $person->name = trim($person->first_name . ' ' . $person->last_name);
            }

            // Géocodage BAN de l'adresse de résidence (couvre front, Filament
            // et tinker). Résultat caché côté service : pas d'appel réseau
            // répété pour une même adresse.
            if ($person->isDirty('address')) {
                $geo = $person->address
                    ? app(\App\Services\GeocodeService::class)->addressFor($person->address)
                    : null;
                $person->address_city = $geo['city'] ?? null;
                $person->address_latitude = $geo['latitude'] ?? null;
                $person->address_longitude = $geo['longitude'] ?? null;
            }
        });

        static::creating(function ($person) {
            if (empty($person->slug)) {
                $person->slug = Str::slug($person->name);
                // Make unique if needed
                $count = 1;
                $originalSlug = $person->slug;
                // withTrashed : la contrainte d'unicité couvre aussi la corbeille.
                while (static::withTrashed()->where('slug', $person->slug)->exists()) {
                    $person->slug = $originalSlug . '-' . $count++;
                }
            }
        });

        static::updating(function ($person) {
            if ($person->isDirty('name')) {
                $person->slug = Str::slug($person->name);
                $count = 1;
                $originalSlug = $person->slug;
                while (static::withTrashed()->where('slug', $person->slug)->where('id', '!=', $person->id)->exists()) {
                    $person->slug = $originalSlug . '-' . $count++;
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function avatar()
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function media()
    {
        return $this->belongsToMany(Media::class, 'media_person')
            ->withPivot('face_coordinates')
            ->withTimestamps();
    }

    public function detectedFaces()
    {
        return $this->hasMany(DetectedFace::class);
    }

    /**
     * Charge `matched_faces_count` : le nombre de visages confirmés (matched,
     * avec bounding box) — nécessaire pour savoir si un avatar-visage existe.
     * Contrainte partagée entre fiches, recherche, arbre et admin.
     */
    public function scopeWithMatchedFacesCount($query)
    {
        return $query->withCount(self::matchedFacesCountRelation());
    }

    /** Compteur des visages validés et recadrables (avatar-visage). */
    public static function matchedFacesCountRelation(): array
    {
        return ['detectedFaces as matched_faces_count' => fn ($q) => $q->usableForAvatar()];
    }

    /**
     * Moments de vie rattachés (frise chronologique).
     */
    public function lifeEvents()
    {
        return $this->hasMany(LifeEvent::class);
    }

    /** Histoires où la personne apparaît (principale ou non). */
    public function stories()
    {
        return $this->belongsToMany(Story::class, 'story_person')->withPivot(['is_main', 'role', 'position']);
    }

    public function father()
    {
        return $this->belongsTo(Person::class, 'father_id');
    }

    public function mother()
    {
        return $this->belongsTo(Person::class, 'mother_id');
    }

    public function childrenAsFather()
    {
        return $this->hasMany(Person::class, 'father_id');
    }

    public function childrenAsMother()
    {
        return $this->hasMany(Person::class, 'mother_id');
    }

    public function getChildrenAttribute()
    {
        return Person::where('father_id', $this->id)
            ->orWhere('mother_id', $this->id)
            ->get();
    }

    public function spousesAsFirst()
    {
        return $this->belongsToMany(Person::class, 'person_relationships', 'person1_id', 'person2_id')
            ->withPivot('type', 'start_date', 'end_date', 'start_place', 'media_id')
            ->withTimestamps();
    }

    public function spousesAsSecond()
    {
        return $this->belongsToMany(Person::class, 'person_relationships', 'person2_id', 'person1_id')
            ->withPivot('type', 'start_date', 'end_date', 'start_place', 'media_id')
            ->withTimestamps();
    }

    public function getSpousesAttribute()
    {
        return $this->spousesAsFirst->merge($this->spousesAsSecond);
    }

    public function getParentsAttribute()
    {
        return collect([$this->father, $this->mother])->filter()->values();
    }

    /**
     * URL de l'avatar affiché partout : le portrait stocké (léger, cadré,
     * versionné → cache navigateur illimité) ; en attendant qu'il soit fait,
     * l'ancien comportement (photo de profil, sinon recadrage du visage —
     * nécessite matched_faces_count chargé).
     */
    public function avatarUrl(): ?string
    {
        if ($this->portrait_path) {
            return route('people.portrait', ['person' => $this->id, 'v' => substr((string) $this->portrait_source, 0, 12)]);
        }
        if ($this->avatar) {
            return route('people.avatarImage', $this);
        }
        if (($this->matched_faces_count ?? 0) > 0) {
            return url("/people/{$this->id}/face-avatar");
        }

        return null;
    }

    /**
     * Cercles d'amis auxquels la fiche est rattachée.
     */
    public function circles()
    {
        return $this->belongsToMany(Household::class, 'household_person')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /**
     * Fiches que le compte peut voir — POINT UNIQUE de la visibilité :
     * - fiche de famille : membres des cercles famille auxquels elle est
     *   rattachée (plusieurs familles sur une même installation, chacune
     *   chez elle ; une fiche commune est rattachée à plusieurs familles) ;
     *   sans aucun cercle famille dans l'installation, répertoire commun ;
     * - fiche d'un cercle d'amis : membres de ce cercle seulement ;
     * - toujours : sa propre fiche, celles qu'on a créées, la fiche invitée.
     * L'admin voit tout.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $query;
        }

        // Fiche archivée (plus dans aucune famille) : invisible de tous.
        $query->whereNull('people.archived_at');
        $friendCircles = $user->friendCircleIds();
        $familyCircles = $user->familyCircleIds();
        // Installation d'une seule famille sans cercle famille : répertoire
        // commun (comportement d'avant le multi-familles).
        $sharedDirectory = $user->canSeeFamilyDirectory() && $user->usesSharedFamilyDirectory();

        return $query->where(function ($q) use ($user, $friendCircles, $familyCircles, $sharedDirectory) {
            if ($sharedDirectory) {
                $q->orWhere('people.in_family_directory', true);
            } elseif ($familyCircles !== []) {
                // Multi-familles : les fiches de famille de SES cercles famille.
                $q->orWhere(fn ($f) => $f->where('people.in_family_directory', true)
                    ->whereHas('circles', fn ($c) => $c->whereIn('households.id', $familyCircles)));
            }
            if ($friendCircles !== []) {
                $q->orWhereHas('circles', fn ($c) => $c->whereIn('households.id', $friendCircles));
            }
            $q->orWhere('people.user_id', $user->id);
            foreach (array_filter([$user->person_id, $user->guest_person_id]) as $own) {
                $q->orWhere('people.id', $own);
            }
        });
    }

    /**
     * Fiches de SA famille courante (arbre, TV) : le répertoire familial qu'il
     * voit, réduit à son cercle famille courant quand l'installation en
     * compte (plusieurs familles : jamais deux arbres mélangés). Vide s'il ne
     * voit pas la famille.
     */
    public function scopeInFamilyOf($query, User $user)
    {
        if (! $user->canSeeFamilyDirectory()) {
            return $query->whereRaw('1 = 0');
        }
        $query->visibleTo($user)->where('people.in_family_directory', true);
        $family = $user->usesSharedFamilyDirectory() ? null : $user->currentFamilyCircle();

        return $family ? $query->whereHas('circles', fn ($c) => $c->whereKey($family->id)) : $query;
    }

    /**
     * Vrai si la fiche $personId descend de $ancestorId (remontée des
     * parents génération par génération, une requête par génération).
     * Sert à refuser un lien parent/enfant qui créerait une boucle.
     */
    public static function descendsFrom(string $personId, string $ancestorId): bool
    {
        $seen = [];
        $frontier = [$personId];

        while ($frontier !== []) {
            $parents = static::withTrashed()->whereIn('id', $frontier)
                ->get(['father_id', 'mother_id'])
                ->flatMap(fn ($p) => [$p->father_id, $p->mother_id])
                ->filter()->unique()->values()->all();

            if (in_array($ancestorId, $parents, true)) {
                return true;
            }
            $seen += array_flip($frontier);
            $frontier = array_values(array_filter($parents, fn ($id) => ! isset($seen[$id])));
        }

        return false;
    }

    public function isVisibleTo(?User $user): bool
    {
        return static::visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Fiches du CONTEXTE courant (cercle choisi dans l'en-tête) — filtre
     * d'affichage, toujours inclus dans visibleTo : cercle d'amis courant →
     * ses fiches (+ la sienne) ; sinon → le répertoire familial visible.
     */
    public function scopeInContext($query, User $user)
    {
        $query->visibleTo($user);
        $circle = $user->currentCircle();

        if ($circle?->isFriends()) {
            return $query->where(function ($q) use ($circle, $user) {
                $q->whereHas('circles', fn ($c) => $c->whereKey($circle->id));
                if ($user->person_id) {
                    $q->orWhere('people.id', $user->person_id);
                }
            });
        }

        if (! $user->canSeeFamilyDirectory()) {
            return $query;
        }
        $query->where('people.in_family_directory', true);

        // Plusieurs familles : celle du cercle courant seulement.
        if ($circle && ! $user->usesSharedFamilyDirectory()) {
            $query->whereHas('circles', fn ($c) => $c->whereKey($circle->id));
        }

        return $query;
    }

    /**
     * Liaison implicite {person} : une fiche invisible pour le compte connecté
     * n'existe pas (404) — couvre fiche, frise, avatars, parenté, arbre…
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->resolveRouteBindingQuery($this->newQuery(), $value, $field);

        if ($user = auth()->user()) {
            $query->visibleTo($user);
        }

        return $query->first();
    }

    /**
     * Version réduite d'une fiche d'un autre cercle, montrée sur une photo
     * que le viewer a le droit de voir : le nom seul (ni dates, ni notes, ni
     * avatar, ni lien vers la fiche), marquée `restricted`.
     */
    public static function restrictedStub(Person $person): Person
    {
        $stub = new Person;
        $stub->forceFill([
            'id' => $person->id,
            'name' => $person->name,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
        ]);
        $stub->setAttribute('restricted', true);
        $stub->exists = true;

        return $stub;
    }
}
