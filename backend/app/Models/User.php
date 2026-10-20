<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasUuids, HasPushSubscriptions;

    /**
     * Catégories de notifications push (clés de preferences.push). Une
     * nouvelle catégorie = une entrée ici + une sous-classe de
     * PushNotification + un interrupteur dans le profil.
     */
    public const PUSH_CATEGORIES = ['social', 'birthdays', 'digest'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_id',
        'person_id',
        'current_household_id',
        'preferences',
        'guest_expires_at',
        'guest_person_id',
        'simple_mode',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'tablet_token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'json',
            'guest_expires_at' => 'datetime',
            'simple_mode' => 'boolean',
            'memorial_at' => 'datetime',
            'departure_requested_at' => 'datetime',
            'departure_options' => 'array',
        ];
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * L'arbre est éditable par tous les membres de la famille (historique
     * PersonChange pour annuler) : ni les invités temporaires, ni les
     * tablettes en mode grand-parent.
     */
    public function canEditFamilyTree(): bool
    {
        return ! $this->isGuest() && ! $this->simple_mode;
    }

    /**
     * Comptes de vraies personnes : ni invités temporaires, ni tablettes.
     */
    public function scopeRealPeople($query)
    {
        // Ni invités, ni tablettes, ni comptes « en mémoire » (personne pour
        // recevoir une notification, un accès ou valider une photo).
        return $query->whereNull('guest_expires_at')->where('simple_mode', false)->whereNull('memorial_at');
    }

    /** Compte « en mémoire » (membre décédé) : conservé, plus de connexion. */
    public function isMemorial(): bool
    {
        return $this->memorial_at !== null;
    }

    /**
     * Passe le compte « en mémoire » : connexion et notifications coupées,
     * rien n'est supprimé (photos, souvenirs, fiche, albums restent).
     */
    public function markMemorial(): void
    {
        $this->forceFill(['memorial_at' => now(), 'remember_token' => null])->save();
        $this->pushSubscriptions()->delete();
    }

    public function unmarkMemorial(): void
    {
        $this->forceFill(['memorial_at' => null])->save();
    }

    /**
     * Comptes que $viewer peut voir dans une liste : ceux avec qui il partage
     * au moins un cercle (tous pour un admin).
     */
    public function scopeVisibleTo($query, User $viewer)
    {
        if ($viewer->isAdmin()) {
            return $query;
        }

        return $query->whereHas('households', fn ($h) => $h->whereIn('households.id', $viewer->householdIds()));
    }

    public function isGuest(): bool
    {
        return $this->guest_expires_at !== null;
    }

    public function guestExpired(): bool
    {
        return $this->isGuest() && $this->guest_expires_at->isPast();
    }

    /**
     * Determine if the user can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /** Télévisions reliées au compte (et codes d'appairage validés). */
    public function tvDevices()
    {
        return $this->hasMany(TvDevice::class);
    }

    /**
     * Foyers dont l'utilisateur est membre (plusieurs possibles).
     */
    public function households()
    {
        return $this->belongsToMany(Household::class, 'household_user')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    /**
     * IDs des foyers de l'utilisateur, mémoïsés sur l'instance (utilisé par
     * Media::scopeAccessibleBy, potentiellement plusieurs fois par requête).
     */
    protected ?array $householdIdsCache = null;

    public function householdIds(): array
    {
        return $this->householdIdsCache ??= $this->households()->pluck('households.id')->all();
    }

    /**
     * Cercles (foyers) du compte avec leur type, mémoïsés — servent à la
     * visibilité des fiches (Person::scopeVisibleTo) et au sélecteur.
     */
    protected ?\Illuminate\Support\Collection $circlesCache = null;

    public function circles(): \Illuminate\Support\Collection
    {
        return $this->circlesCache ??= $this->households()
            ->orderBy('households.name')
            ->get(['households.id', 'households.name', 'households.kind']);
    }

    public function forgetCircles(): void
    {
        $this->circlesCache = null;
        $this->householdIdsCache = null;
    }

    /** IDs des cercles d'amis du compte. */
    public function friendCircleIds(): array
    {
        return $this->circles()->where('kind', Household::KIND_FRIENDS)->pluck('id')->all();
    }

    /**
     * Le compte voit-il le répertoire familial (arbre, fiches « famille ») ?
     * Oui s'il est dans un cercle familial ; sans aucun cercle, selon le mode
     * de l'installation (rétrocompatibilité des comptes existants). Un compte
     * qui n'a QUE des cercles d'amis ne voit pas la famille.
     */
    public function canSeeFamilyDirectory(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $circles = $this->circles();
        if ($circles->isEmpty()) {
            return config('memorylane.default_mode') === Household::KIND_FAMILY;
        }

        return $circles->contains('kind', Household::KIND_FAMILY);
    }

    /**
     * Cercle courant (sélecteur de l'en-tête) : celui choisi s'il en est
     * toujours membre, sinon le premier cercle familial, sinon le premier.
     */
    public function currentCircle(): ?Household
    {
        $circles = $this->circles();

        return $circles->firstWhere('id', $this->current_household_id)
            ?? $circles->firstWhere('kind', Household::KIND_FAMILY)
            ?? $circles->first();
    }

    /** Mode d'affichage courant : celui du cercle courant, sinon de l'installation. */
    public function mode(): string
    {
        return $this->currentCircle()?->kind ?? config('memorylane.default_mode');
    }

    /**
     * Les deux utilisateurs partagent-ils au moins un foyer ?
     */
    public function sharesHouseholdWith(User $other): bool
    {
        return array_intersect($this->householdIds(), $other->householdIds()) !== [];
    }

    /**
     * L'utilisateur a-t-il choisi de partager son adresse avec son foyer ?
     * (option du profil, stockée dans preferences ; défaut : non)
     */
    public function sharesAddressWithHousehold(): bool
    {
        return (bool) ($this->preferences['share_address_with_household'] ?? false);
    }

    /**
     * Préférences push par catégorie (défaut : tout à faux tant que
     * l'utilisateur n'a jamais activé les notifications).
     *
     * @return array<string,bool>
     */
    public function pushPreferences(): array
    {
        $stored = $this->preferences['push'] ?? [];
        $prefs = [];
        foreach (self::PUSH_CATEGORIES as $category) {
            $prefs[$category] = (bool) ($stored[$category] ?? false);
        }

        return $prefs;
    }

    /**
     * Faut-il envoyer un push de cette catégorie à cet utilisateur ?
     * Catégorie activée ET au moins un appareil abonné.
     */
    public function wantsPush(string $category): bool
    {
        return ($this->pushPreferences()[$category] ?? false)
            && $this->pushSubscriptions()->exists();
    }

    /**
     * Écrit une préférence (clé en notation pointée, ex. `push.social`) en
     * fusionnant avec l'existant, puis sauvegarde.
     */
    public function setPreference(string $key, mixed $value): void
    {
        $prefs = $this->preferences ?? [];
        data_set($prefs, $key, $value);
        $this->preferences = $prefs;
        $this->save();
    }
}
