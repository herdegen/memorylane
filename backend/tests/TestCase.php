<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : jamais de test contre la prod. Vérifié AVANT les traits
     * (RefreshDatabase lancerait sinon migrate:fresh sur la base réelle).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if (! $app->environment('testing') || ! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Tests refusés : environnement « {$app->environment()} », base « {$database} ». "
                .'Lancez-les hors de la config de prod en cache (php artisan optimize:clear) ou dans un conteneur jetable.'
            );
        }

        return $app;
    }
}
