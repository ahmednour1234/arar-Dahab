<?php

namespace App\Providers;

use App\Services\InventoryStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryStore::class, fn () => new InventoryStore(config('goldscanner')));
        $this->app->singleton(\App\Services\LiveFeed::class, fn () => new \App\Services\LiveFeed);
        $this->app->bind(\App\Services\InventoryCount::class, fn ($app) => new \App\Services\InventoryCount($app->make(InventoryStore::class)));
    }

    public function boot(): void
    {
        //
    }
}
