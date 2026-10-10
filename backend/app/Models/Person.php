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
                while (static::where('slug', $person->slug)->exists()) {
                    $person->slug = $originalSlug . '-' . $count++;
                }
            }
        });

        static::updating(function ($person) {
            if ($person->isDirty('name')) {
                $person->slug = Str::slug($person->name);
                $count = 1;
                $originalSlug = $person->slug;
                while (static::where('slug', $person->slug)->where('id', '!=', $person->id)->exists()) {
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
        return $query->withCount(['detectedFaces as matched_faces_count' => function ($q) {
            $q->where('status', 'matched')->whereNotNull('bounding_box');
        }]);
    }

    /**
     * Moments de vie rattachés (frise chronologique).
     */
    public function lifeEvents()
    {
        return $this->hasMany(LifeEvent::class);
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
            ->withPivot('type', 'start_date', 'end_date', 'start_place')
            ->withTimestamps();
    }

    public function spousesAsSecond()
    {
        return $this->belongsToMany(Person::class, 'person_relationships', 'person2_id', 'person1_id')
            ->withPivot('type', 'start_date', 'end_date', 'start_place')
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
     * - répertoire familial : ouvert à qui voit la famille (cercle familial,
     *   ou installation familiale sans cercle) — comportement historique ;
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

        $friendCircles = $user->friendCircleIds();
        $familyOpen = $user->canSeeFamilyDirectory();

        return $query->where(function ($q) use ($user, $friendCircles, $familyOpen) {
            if ($familyOpen) {
                $q->orWhere('people.in_family_directory', true);
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

        return $user->canSeeFamilyDirectory()
            ? $query->where('people.in_family_directory', true)
            : $query;
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
