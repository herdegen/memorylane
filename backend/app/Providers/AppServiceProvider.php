<?php

namespace App\Providers;

use App\Contracts\VisionServiceInterface;
use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Policies\AlbumPolicy;
use App\Policies\HouseholdPolicy;
use App\Policies\MediaPolicy;
use App\Services\Vision\GoogleVisionService;
use App\Services\Vision\NullVisionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VisionServiceInterface::class, function () {
            if (! config('vision.enabled')) {
                return new NullVisionService();
            }

            return match (config('vision.provider')) {
                'google' => new GoogleVisionService(),
                default => new NullVisionService(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Album::class, AlbumPolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Household::class, HouseholdPolicy::class);
    }
}
