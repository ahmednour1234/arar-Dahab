<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Holds the active inventory snapshot on disk (storage/app/private/inventory):
 *   inventory.xls|.xlsx  - the current Excel file (uploaded, or replaced via FTP/SFTP)
 *   index.json           - last successfully parsed snapshot (used for lookups)
 *   status.json          - sync status (last success / error / change)
 *   backups/             - previous Excel files
 *
 * If the Excel file changes on disk (e.g. replaced over SFTP), the next request
 * re-parses it automatically. A failed parse keeps the previous snapshot.
 */
class InventoryStore
{
    private ?array $snapshot = null;

    private ?array $status = null;

    public function __construct(private array $cfg, private ?string $dir = null)
    {
        $this->dir ??= storage_path('app/private/'.($cfg['storage_dir'] ?? 'inventory'));
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Path of the current Excel source file, if any. */
    public function sourcePath(): ?string
    {
        foreach (['inventory.xls', 'inventory.xlsx'] as $n) {
            if (is_file($this->dir.'/'.$n)) {
                return $this->dir.'/'.$n;
            }
        }

        return null;
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }

    private function readJson(string $name): ?array
    {
        $p = $this->dir.'/'.$name;
        if (! is_file($p)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($p), true);

        return is_array($d) ? $d : null;
    }

    private function writeJson(string $name, array $data): void
    {
        $tmp = $this->dir.'/.'.$name.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        rename($tmp, $this->dir.'/'.$name);   // atomic replace
    }

    public function status(): array
    {
        return $this->status ??= ($this->readJson('status.json') ?? [
            'last_success' => null, 'last_attempt' => null, 'last_error' => null,
            'last_change' => null, 'load_count' => 0,
        ]);
    }

    private function saveStatus(array $s): void
    {
        $this->status = $s;
        $this->writeJson('status.json', $s);
    }

    private function signature(?string $path): ?array
    {
        if (! $path) {
            return null;
        }
        clearstatcache(true, $path);

        return ['name' => basename($path), 'size' => filesize($path), 'mtime' => filemtime($path)];
    }

    /** Return the active snapshot, re-parsing first if the source file changed. */
    public function snapshot(): ?array
    {
        if ($this->snapshot === null) {
            $this->snapshot = $this->readJson('index.json');
        }
        $src = $this->sourcePath();
        $sig = $this->signature($src);
        $st = $this->status();

        if ($sig === null) {
            if ($this->snapshot !== null && $st['last_error'] === null) {
                $st['last_error'] = 'ملف Excel غير موجود على الخادم';
                $this->saveStatus($st);
            }

            return $this->snapshot;
        }
        $known = $this->snapshot['source'] ?? null;
        $failedSig = $st['failed_source'] ?? null;
        if ($known != $sig && $failedSig != $sig) {
            $this->reload('change');
        }

        return $this->snapshot;
    }

    /** Parse the source file and atomically swap the snapshot. Keeps the old one on failure. */
    public function reload(string $reason = 'manual'): bool
    {
        $lock = fopen($this->dir.'/.reload.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $src = $this->sourcePath();
            $st = $this->status();
            $st['last_attempt'] = $this->now();
            $old = $this->readJson('index.json');
            $sig = $this->signature($src);
            if ($sig && ($old['source'] ?? null) == $sig && $reason === 'change') {
                $this->snapshot = $old;            // another request already reloaded

                return true;
            }
            try {
                if (! $src) {
                    throw new InventoryReadException('ملف Excel غير موجود على الخادم');
                }
                $snap = (new InventoryParser($this->cfg))->parseFile($src);
                if ($this->signature($src) != $sig) {
                    throw new InventoryReadException('الملف تغيّر أثناء القراءة');
                }
                $snap['source'] = $sig;
                $snap['loaded_at'] = $this->now();
                $change = InventoryParser::diff($old, $snap);
                $this->writeJson('index.json', $snap);
                $this->snapshot = $snap;
                $st['last_success'] = $this->now();
                $st['last_error'] = null;
                $st['failed_source'] = null;
                if (array_sum($change) > 0 || $st['last_change'] === null) {
                    $st['last_change'] = $change;
                }
                $st['load_count'] = ($st['load_count'] ?? 0) + 1;
                $this->saveStatus($st);
                Log::info(sprintf('Inventory loaded (%s): %d rows, +%d ~%d -%d, %d duplicates',
                    $reason, $snap['total_rows'], $change['added'], $change['modified'], $change['deleted'], count($snap['duplicates'])));

                return true;
            } catch (InventoryReadException $e) {
                $st['last_error'] = $e->getMessage();
                $st['failed_source'] = $sig;       // don't re-parse the same broken file every request
                $this->saveStatus($st);
                $this->snapshot = $old;
                Log::warning('Inventory reload failed; keeping previous snapshot: '.$e->getMessage());

                return false;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function state(): array
    {
        $snap = $this->snapshot();
        $st = $this->status();
        $state = $snap === null ? ($st['last_error'] ? 'error' : 'loading') : ($st['last_error'] ? 'stale' : 'ok');

        return [
            'state' => $state,
            'records' => $snap['total_rows'] ?? 0,
            'unique_barcodes' => $snap ? count($snap['records']) : 0,
            'duplicate_barcodes' => $snap ? count($snap['duplicates']) : 0,
            'warnings' => $snap['warnings'] ?? [],
            'last_success' => $st['last_success'],
            'last_attempt' => $st['last_attempt'],
            'last_error' => $st['last_error'],
            'last_change' => $st['last_change'],
            'load_count' => $st['load_count'] ?? 0,
        ];
    }

    /**
     * Validate an uploaded file, back up the current one, then replace it atomically.
     *
     * @return array parsed snapshot
     *
     * @throws InventoryReadException
     */
    public function replaceWithUpload(string $tmpPath, string $ext): array
    {
        $snap = (new InventoryParser($this->cfg))->parseFile($tmpPath);   // validate first

        $current = $this->sourcePath();
        if ($current) {
            $bdir = $this->dir.'/backups';
            if (! is_dir($bdir)) {
                mkdir($bdir, 0775, true);
            }
            $info = pathinfo($current);
            copy($current, $bdir.'/'.$info['filename'].'-'.date('Ymd-His').'.'.$info['extension']);
            $all = glob($bdir.'/inventory-*.*') ?: [];
            sort($all);
            foreach (array_slice($all, 0, max(0, count($all) - (int) ($this->cfg['backups_keep'] ?? 10))) as $old) {
                @unlink($old);
            }
        }
        $target = $this->dir.'/inventory.'.$ext;
        $staging = $this->dir.'/.upload-'.bin2hex(random_bytes(4)).'.'.$ext;
        copy($tmpPath, $staging);
        rename($staging, $target);
        foreach (['xls', 'xlsx'] as $e) {             // only one active source
            if ($e !== $ext && is_file($this->dir.'/inventory.'.$e)) {
                @unlink($this->dir.'/inventory.'.$e);
            }
        }
        $this->reload('upload');

        return $snap;
    }
}
