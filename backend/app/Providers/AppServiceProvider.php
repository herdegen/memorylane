<?php

namespace App\Providers;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Policies\AlbumPolicy;
use App\Policies\HouseholdPolicy;
use App\Policies\MediaPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // En prod : migrate:fresh/refresh/reset/rollback et db:wipe interdits
        // (incident du 07/10 : rollback qui a supprimé une table).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Tableau de bord des tâches de fond (/horizon) : admins seulement.
        \Laravel\Horizon\Horizon::auth(fn ($request) => (bool) $request->user()?->isAdmin());

        Gate::policy(Album::class, AlbumPolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Household::class, HouseholdPolicy::class);
    }
}
