<?php

use App\Services\InventoryStore;
use Illuminate\Support\Facades\Artisan;

// php artisan gold:demo  -> installs the fictional demo inventory (only if no file exists, unless --force)
Artisan::command('gold:demo {--force}', function (InventoryStore $store) {
    if ($store->sourcePath() && ! $this->option('force')) {
        $this->warn('An inventory file already exists. Use --force to replace it with demo data (a backup is kept).');

        return 1;
    }
    $snap = $store->addUpload(database_path('demo/inventory.xls'), 'xls', 'inventory-demo.xls');
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

// php artisan gold:find 001090  -> explains exactly why a barcode is / isn't found
Artisan::command('gold:find {barcode}', function (InventoryStore $store, string $barcode) {
    $cfg = config('goldscanner');
    $key = \App\Services\InventoryParser::normalizeKey($barcode, (bool) ($cfg['case_insensitive_barcodes'] ?? true));
    $this->line('Searching for: "'.$barcode.'"  (normalized key: "'.$key.'")');
    $files = $store->files();
    if (! $files) {
        $this->error('No Excel files are loaded on the server. Upload the file from the UI first.');

        return 1;
    }
    $snaps = $store->snapshots();
    foreach ($files as $f) {
        $this->newLine();
        $this->info("FILE: {$f['name']}  state={$f['state']}  items={$f['records']}");
        if ($f['last_error']) {
            $this->error('  last error: '.$f['last_error']);
        }
        $s = $snaps[$f['id']] ?? null;
        if (! $s) {
            $this->error('  this file has NO loaded data (it failed to parse).');
            continue;
        }
        foreach ($s['sheet_info'] ?? [] as $sheet => $info) {
            $cols = [];
            foreach ($info['columns'] as $field => $header) {
                $cols[] = "{$field}=\"{$header}\"";
            }
            $this->line("  sheet \"{$sheet}\": header row {$info['header_row']}, columns: ".implode(', ', $cols));
        }
        $this->line('  first barcodes: '.implode(', ', array_slice(array_keys($s['records']), 0, 8)));
        if (isset($s['records'][$key])) {
            foreach ($s['records'][$key] as $r) {
                $this->info("  FOUND (exact) at sheet \"{$r['location']['sheet']}\" row {$r['location']['row']}: ".($r['fields']['item_name'] ?? ''));
            }
            continue;
        }
        $alt = array_filter(array_keys($s['records']), fn ($k) => ctype_digit((string) $k) && ctype_digit($key) && ltrim((string) $k, '0') === ltrim($key, '0'));
        if ($alt) {
            $this->info('  FOUND without leading zeros as: '.implode(', ', $alt));
            continue;
        }
        $near = array_slice(array_filter(array_keys($s['records']), fn ($k) => str_contains((string) $k, ltrim($key, '0'))), 0, 5);
        $this->warn('  NOT in this file.'.($near ? ' Similar barcodes: '.implode(', ', $near) : ''));
        foreach ($near as $k) {
            $this->line('    "'.$k.'" bytes: '.bin2hex((string) $k).'  (parser v'.($s['parser'] ?? 'old').') -> run: php artisan gold:reload');
        }
    }

    return 0;
})->purpose('Explain why a barcode is or is not found');

// php artisan gold:status
Artisan::command('gold:status', function (InventoryStore $store) {
    $this->line(json_encode($store->state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Show inventory sync status');
