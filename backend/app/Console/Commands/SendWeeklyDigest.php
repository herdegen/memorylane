<?php

namespace App\Console\Commands;

use App\Mail\FamilyNewsletterMail;
use App\Models\Household;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\MediaService;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Le dimanche soir :
 *  - membres de la famille → la lettre du dimanche (FamilyNewsletterBuilder),
 *    sauf s'ils s'en sont désabonnés ; push « résumé » en plus si activé ;
 *  - comptes des seuls cercles d'amis → l'ancien résumé des nouvelles photos
 *    (en attendant leur propre édition).
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'memorylane:weekly-digest
        {--dry-run : Affiche les sujets sans rien envoyer ni enregistrer}
        {--user= : N\'envoyer qu\'à ce compte (adresse e-mail)}';

    protected $description = 'Envoie la lettre du dimanche (famille) et le résumé hebdomadaire (amis)';

    public function handle(MediaService $mediaService, FamilyNewsletterBuilder $builder): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        User::realPeople()
            ->where('email', 'not like', '%.invalid')
            ->when($this->option('user'), fn ($q, $email) => $q->where('email', $email))
            ->each(function (User $user) use ($mediaService, $builder, $dry, &$sent) {
                $family = $user->circles()->contains('kind', Household::KIND_FAMILY);

                if ($family) {
                    if ($user->wantsNewsletter()) {
                        $issue = $builder->build($user);
                        if ($issue) {
                            $this->line(($dry ? '[aperçu] ' : '')."{$user->name} : {$issue['subject']}");
                            if (! $dry) {
                                Mail::to($user)->send(new FamilyNewsletterMail($user, $issue));
                                NewsletterSend::create([
                                    'user_id' => $user->id,
                                    'edition' => FamilyNewsletterBuilder::EDITION,
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

                    return;
                }

                if (! $dry && $this->digest($user, $mediaService, mail: true)) {
                    $sent++;
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
