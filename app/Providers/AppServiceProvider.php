<?php

namespace App\Providers;

use App\Support\HelpCenter;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HelpCenter::class, fn () => new HelpCenter(resource_path('help')));
    }

    public function boot(): void
    {
        Paginator::useBootstrapFour();
    }
}
