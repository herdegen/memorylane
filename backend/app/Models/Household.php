<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    protected static function booted(): void
    {
        // Premier cercle famille de l'installation : il reprend les fiches de
        // famille qui n'en avaient pas (répertoire commun d'avant les cercles)
        // — sinon elles disparaîtraient pour ses membres.
        static::created(function (self $household) {
            if (! $household->isFamily() || static::where('kind', self::KIND_FAMILY)->count() !== 1) {
                return;
            }
            DB::table('household_person')->insertUsing(
                ['household_id', 'person_id', 'added_by', 'created_at', 'updated_at'],
                DB::table('people')
                    ->selectRaw('?, people.id, ?, NOW(), NOW()', [$household->id, $household->created_by])
                    ->where('people.in_family_directory', true)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('household_person as hp')
                        ->join('households as h', 'h.id', '=', 'hp.household_id')
                        ->whereColumn('hp.person_id', 'people.id')
                        ->where('h.kind', self::KIND_FAMILY)),
            );
        });
    }

    public function isFriends(): bool
    {
        return $this->kind === self::KIND_FRIENDS;
    }

    public function isFamily(): bool
    {
        return $this->kind === self::KIND_FAMILY;
    }

    /** Réglage par défaut selon le type : famille ouverte, amis via l'admin. */
    public static function defaultInvitePolicy(string $kind): string
    {
        return $kind === self::KIND_FRIENDS ? self::INVITE_ADMIN : self::INVITE_EVERYONE;
    }

    /**
     * Ce compte peut-il faire entrer quelqu'un dans le cercle (invitation
     * par mail, ajout d'un compte existant) ? L'admin toujours ; sinon, si le
     * cercle est ouvert à tous : ses membres seulement (plusieurs familles
     * sur une installation : on n'entre pas dans celle des autres).
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

        return $this->isMember($user);
    }

    /**
     * Fiches rattachées à ce cercle, visibles de ses membres : celles du
     * cercle d'amis, ou celles de la famille (une fiche commune à deux
     * familles est rattachée à leurs deux cercles).
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
     * Ajoute un compte au cercle ; sa fiche suit (dans une famille : si
     * c'est une fiche de famille).
     */
    public function addMember(User $member, User $by): void
    {
        $this->members()->syncWithoutDetaching([$member->id => ['invited_by' => $by->id]]);
        $member->forgetCircles();

        if ($member->person_id && ($this->isFriends()
            || Person::whereKey($member->person_id)->where('in_family_directory', true)->exists())) {
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
