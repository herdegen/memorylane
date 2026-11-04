<?php

namespace App\Console\Commands;

use App\Mail\FamilyNewsletterMail;
use App\Mail\FriendsNewsletterMail;
use App\Models\Household;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\MediaService;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use App\Services\Newsletter\FriendsNewsletterBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Le dimanche soir, la lettre du dimanche (sauf désabonnement, une seule
 * préférence pour les deux éditions) :
 *  - membres d'un cercle familial → l'édition famille (FamilyNewsletterBuilder),
 *    avec un encart « Côté amis » s'ils sont aussi dans un cercle d'amis qui
 *    a du nouveau ;
 *  - membres des seuls cercles d'amis → l'édition amis (FriendsNewsletterBuilder),
 *    seulement s'il y a du nouveau dans leurs cercles ;
 *  - push « résumé » en plus pour les membres de cercles qui l'ont activé ;
 *  - comptes sans aucun cercle → l'ancien résumé des nouvelles photos.
 * Rien de neuf : rien ne part, rien n'est enregistré.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'memorylane:weekly-digest
        {--dry-run : Affiche les sujets sans rien envoyer ni enregistrer}
        {--user= : N\'envoyer qu\'à ce compte (adresse e-mail)}';

    protected $description = 'Envoie la lettre du dimanche (éditions famille et amis) et le résumé hebdomadaire';

    public function handle(MediaService $mediaService, FamilyNewsletterBuilder $family, FriendsNewsletterBuilder $friends): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        User::realPeople()
            ->where('email', 'not like', '%.invalid')
            ->when($this->option('user'), fn ($q, $email) => $q->where('email', $email))
            ->each(function (User $user) use ($mediaService, $family, $friends, $dry, &$sent) {
                $edition = $user->newsletterEdition();

                if ($edition === null) {
                    // Sans cercle : l'ancien résumé (mail, et push s'il est activé).
                    if (! $dry && $this->digest($user, $mediaService, mail: true)) {
                        $sent++;
                    }

                    return;
                }

                if ($user->wantsNewsletter()) {
                    $inFamily = $edition === Household::KIND_FAMILY;
                    [$builder, $mail] = $inFamily
                        ? [$family, FamilyNewsletterMail::class]
                        : [$friends, FriendsNewsletterMail::class];
                    $issue = $builder->build($user);
                    if ($issue) {
                        $label = $inFamily ? 'famille' : 'amis';
                        $this->line(($dry ? '[aperçu] ' : '')."{$user->name} ({$label}) : {$issue['subject']}");
                        if (! $dry) {
                            Mail::to($user)->send(new $mail($user, $issue));
                            NewsletterSend::create([
                                'user_id' => $user->id,
                                'edition' => $builder::EDITION,
                                'sections' => $issue['log'],
                                'subject' => $issue['subject'],
                                'sent_at' => now(),
                            ]);
                            $sent++;
                        }
                    }
                }

                // Push « résumé » (indépendant de l'e-mail) s'il y a du nouveau.
                if (! $dry && $user->wantsPush('digest')) {
                    $this->digest($user, $mediaService, mail: false);
                }
            });

        $this->info($dry ? 'Aperçu terminé, rien n\'a été envoyé.' : "{$sent} envoi(s).");

        return self::SUCCESS;
    }

    /** Ancien résumé des nouveaux médias (mail et/ou push). */
    private function digest(User $user, MediaService $mediaService, bool $mail): bool
    {
        $newMedia = Media::accessibleBy($user)
            ->with(['user', 'conversions'])
            ->where('uploaded_at', '>=', now()->subDays(7))
            ->orderByDesc('uploaded_at')
            ->get();

        if ($newMedia->isEmpty()) {
            return false;
        }

        $uploaderNames = $newMedia->pluck('user.name')->filter()->unique()
            ->map(fn ($name) => explode(' ', $name)[0])->values()->all();

        // Vignettes présignées 7 jours (durée de vie du mail, sans session).
        $samples = $newMedia->take(4)->map(fn ($media) => [
            'name' => $media->title ?: $media->original_name,
            'url' => $mediaService->thumbnailUrl($media, expirationMinutes: 60 * 24 * 7, presigned: true),
        ])->all();

        $user->notify(new WeeklyDigest($newMedia->count(), $uploaderNames, $samples, $mail));

        return true;
    }
}
