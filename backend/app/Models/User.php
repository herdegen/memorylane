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
        'pin_code',
        'avatar_id',
        'person_id',
        'preferences',
        'guest_expires_at',
        'guest_person_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin_code',
        'remember_token',
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
        ];
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
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
