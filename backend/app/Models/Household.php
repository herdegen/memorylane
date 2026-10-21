<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cercle (foyer) : groupe de comptes partageant une mémoire commune, de type
 * famille ou amis. Un compte peut appartenir à plusieurs cercles ; seuls les
 * administrateurs les créent et en gèrent les membres.
 */
class Household extends Model
{
    use HasFactory, HasUuids;

    public const KIND_FAMILY = 'family';
    public const KIND_FRIENDS = 'friends';

    public const INVITE_EVERYONE = 'everyone';
    public const INVITE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'kind',
        'invite_policy',
        'created_by',
        'is_default',
    ];

    public function isFriends(): bool
    {
        return $this->kind === self::KIND_FRIENDS;
    }

    /** Réglage par défaut selon le type : famille ouverte, amis via l'admin. */
    public static function defaultInvitePolicy(string $kind): string
    {
        return $kind === self::KIND_FRIENDS ? self::INVITE_ADMIN : self::INVITE_EVERYONE;
    }

    /**
     * Ce compte peut-il faire entrer quelqu'un dans le cercle (invitation
     * par mail, ajout d'un compte existant) ? L'admin toujours ; sinon, si le
     * cercle est ouvert à tous : ses membres — et, pour le cercle d'accueil
     * familial, quiconque voit la famille (comptes historiques sans cercle).
     */
    /**
     * Responsable du cercle : l'admin ; pour un cercle d'amis, aussi celui
     * qui l'a créé (tout membre peut créer son propre groupe d'amis).
     */
    public function isManagedBy(User $user): bool
    {
        return $user->isAdmin() || ($this->isFriends() && $this->created_by === $user->id);
    }

    public function allowsInvitesFrom(User $user): bool
    {
        if ($this->isManagedBy($user)) {
            return true;
        }
        if ($this->invite_policy !== self::INVITE_EVERYONE) {
            return false;
        }

        return $this->isMember($user)
            || ($this->is_default && ! $this->isFriends() && $user->canSeeFamilyDirectory());
    }

    /**
     * Fiches rattachées à ce cercle (cercles d'amis : visibles des membres).
     */
    public function people()
    {
        return $this->belongsToMany(Person::class, 'household_person')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Foyer d'accueil : rejoint automatiquement par toute personne invitée.
     */
    public static function welcome(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Désigne ce foyer comme foyer d'accueil (un seul à la fois).
     */
    public function makeWelcome(): void
    {
        static::where('is_default', true)->whereKeyNot($this->id)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    /**
     * Comptes membres du foyer.
     */
    public function members()
    {
        return $this->belongsToMany(User::class, 'household_user')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    /**
     * Ajoute un compte au cercle ; dans un cercle d'amis, sa fiche suit.
     */
    public function addMember(User $member, User $by): void
    {
        $this->members()->syncWithoutDetaching([$member->id => ['invited_by' => $by->id]]);

        if ($this->isFriends() && $member->person_id) {
            $this->people()->syncWithoutDetaching([$member->person_id => ['added_by' => $by->id]]);
        }
    }

    /**
     * Créateur du foyer (gère les membres, peut le supprimer).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Médias partagés dans ce foyer (rempli en phase 2b).
     */
    public function media()
    {
        return $this->belongsToMany(Media::class, 'household_media')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /**
     * Nombre de « photos du cercle » (cf. Media::scopeInContext) : partagées
     * au cercle, dans un album partagé au cercle, ou montrant une de ses fiches.
     */
    public function photoCount(): int
    {
        // Fiches d'amis seulement : les membres de la famille rattachés au
        // cercle (ex. son créateur) apparaissent sur toutes leurs photos.
        $people = $this->people()->where('people.in_family_directory', false)->pluck('people.id');

        return Media::where('type', 'photo')
            ->where(function ($w) use ($people) {
                $w->whereHas('households', fn ($h) => $h->whereKey($this->id))
                    ->orWhereHas('albums', fn ($a) => $a->whereHas('households', fn ($h) => $h->whereKey($this->id)))
                    ->orWhereHas('people', fn ($p) => $p->whereIn('people.id', $people));
            })
            ->count();
    }

    public function isMember(?User $user): bool
    {
        return $user !== null && $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Foyers dont l'utilisateur est membre.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($m) => $m->whereKey($user->id));
    }
}
