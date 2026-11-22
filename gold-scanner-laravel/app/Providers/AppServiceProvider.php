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
    }

    public function boot(): void
    {
        //
    }
}
