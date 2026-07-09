<?php

namespace App\Console\Commands;

use App\Services\S3Service;
use Illuminate\Console\Command;

/**
 * Applique la politique CORS du bucket S3 pour autoriser l'upload multipart
 * direct depuis le navigateur (PUT présigné) et l'exposition de l'ETag.
 *
 * À lancer une fois (et à re-lancer si l'URL de l'app change) :
 *   php artisan s3:configure-cors
 *   php artisan s3:configure-cors https://memorylane.maxibestof.com http://localhost:8000
 */
class ConfigureS3Cors extends Command
{
    protected $signature = 's3:configure-cors {origins?* : Origines autorisées (défaut: APP_URL)}';

    protected $description = 'Configure le CORS du bucket S3 pour l\'upload direct navigateur';

    public function handle(S3Service $s3): int
    {
        $origins = $this->argument('origins');
        if (empty($origins)) {
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl === '') {
                $this->error('APP_URL vide et aucune origine fournie.');
                return self::FAILURE;
            }
            $origins = [$appUrl];
        }

        $this->info('Bucket : ' . ($s3->getBucket() ?? '(non défini)'));
        $this->info('Origines autorisées : ' . implode(', ', $origins));

        try {
            $s3->putBucketCors($origins);
        } catch (\Throwable $e) {
            $this->error('Échec de la configuration CORS : ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('✔ Politique CORS appliquée (PUT/GET/HEAD, ETag exposé).');
        return self::SUCCESS;
    }
}
