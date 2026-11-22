<?php

use App\Services\InventoryStore;
use Illuminate\Support\Facades\Artisan;

// php artisan gold:demo  -> installs the fictional demo inventory (only if no file exists, unless --force)
Artisan::command('gold:demo {--force}', function (InventoryStore $store) {
    if ($store->sourcePath() && ! $this->option('force')) {
        $this->warn('An inventory file already exists. Use --force to replace it with demo data (a backup is kept).');

        return 1;
    }
    $snap = $store->replaceWithUpload(database_path('demo/inventory.xls'), 'xls');
    $this->info("Demo inventory installed: {$snap['total_rows']} rows (fictional data).");

    return 0;
})->purpose('Install the demo inventory Excel file');

// php artisan gold:reload  -> re-parse the current Excel file now
Artisan::command('gold:reload', function (InventoryStore $store) {
    $ok = $store->reload('manual');
    $st = $store->state();
    $ok ? $this->info("Loaded {$st['records']} rows.") : $this->error('Failed: '.$st['last_error']);

    return $ok ? 0 : 1;
})->purpose('Reload the inventory Excel file');

// php artisan gold:status
Artisan::command('gold:status', function (InventoryStore $store) {
    $this->line(json_encode($store->state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Show inventory sync status');
