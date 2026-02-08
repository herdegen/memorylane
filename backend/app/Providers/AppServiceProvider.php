<?php

namespace App\Providers;

use App\Contracts\VisionServiceInterface;
use App\Services\Vision\GoogleVisionService;
use App\Services\Vision\NullVisionService;
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
        //
    }
}
