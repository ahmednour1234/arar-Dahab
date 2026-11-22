<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Multi-file inventory store (storage/app/private/inventory):
 *   files/<name>.xls|xlsx  - uploaded Excel files (or dropped in via FTP/SFTP)
 *   index/<id>.json        - last successfully parsed snapshot of each file
 *   status.json            - per-file sync status + global last success/change
 *   backups/               - previous versions of replaced/deleted files
 *
 * Lookups search all files (or one file, when filtered). A file that changes on
 * disk is re-parsed on the next request; a failed parse keeps its previous snapshot.
 */
class InventoryStore
{
    private ?array $snapshots = null;   // id => snapshot (with meta)

    private ?array $status = null;

    private const EXTS = ['xls', 'xlsx', 'csv'];

    public function __construct(private array $cfg, private ?string $dir = null)
    {
        $this->dir ??= storage_path('app/private/'.($cfg['storage_dir'] ?? 'inventory'));
        foreach (['', '/files', '/index', '/backups'] as $sub) {
            if (! is_dir($this->dir.$sub)) {
                @mkdir($this->dir.$sub, 0775, true);
            }
        }
        $this->migrateLegacy();
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** v1 kept a single inventory.xls in the root folder: move it into files/. */
    private function migrateLegacy(): void
    {
        foreach (self::EXTS as $e) {
            $old = $this->dir.'/inventory.'.$e;
            if (is_file($old)) {
                @rename($old, $this->dir.'/files/inventory.'.$e);
                @unlink($this->dir.'/index.json');
            }
        }
    }

    public static function fileId(string $storedName): string
    {
        return substr(sha1(mb_strtolower($storedName)), 0, 12);
    }

    /** Safe stored file name from an uploaded name (keeps Arabic letters). */
    public static function safeName(string $original, string $ext): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N} _\-().]+/u', '_', $base);
        $base = trim(preg_replace('/\s+/u', ' ', $base), " ._");
        $base = mb_substr($base !== '' ? $base : 'inventory', 0, 80);

        return $base.'.'.$ext;
    }

    /** id => absolute path of every Excel file currently on disk. */
    public function sourceFiles(): array
    {
        $out = [];
        foreach (glob($this->dir.'/files/*') ?: [] as $p) {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            if (is_file($p) && in_array($ext, self::EXTS, true) && ! str_starts_with(basename($p), '.')) {
                $out[self::fileId(basename($p))] = $p;
            }
        }
        ksort($out);

        return $out;
    }

    /** Backwards-compatible helper: true when at least one file exists. */
    public function sourcePath(): ?string
    {
        return array_values($this->sourceFiles())[0] ?? null;
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($path), true);

        return is_array($d) ? $d : null;
    }

    private function writeJson(string $path, array $data): void
    {
        $tmp = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);   // atomic replace
    }

    public function status(): array
    {
        return $this->status ??= ($this->readJson($this->dir.'/status.json') ?? [
            'last_success' => null, 'last_attempt' => null, 'last_change' => null,
            'load_count' => 0, 'files' => [],
        ]);
    }

    private function saveStatus(array $s): void
    {
        $this->status = $s;
        $this->writeJson($this->dir.'/status.json', $s);
    }

    private function signature(string $path): array
    {
        clearstatcache(true, $path);

        return ['name' => basename($path), 'size' => filesize($path), 'mtime' => filemtime($path)];
    }

    /** Load all snapshots, re-parsing changed files and dropping removed ones. */
    public function snapshots(): array
    {
        $sources = $this->sourceFiles();
        if ($this->snapshots === null) {
            $snaps = [];
            foreach (glob($this->dir.'/index/*.json') ?: [] as $p) {
                $id = basename($p, '.json');
                if (! isset($sources[$id])) {      // file deleted -> remove its index
                    @unlink($p);
                    continue;
                }
                if ($s = $this->readJson($p)) {
                    $snaps[$id] = $s;
                }
            }
            $this->snapshots = $snaps;
        } else {
            // long-lived instance: drop files deleted since, and pick up index files written by other workers
            $this->snapshots = array_intersect_key($this->snapshots, $sources);
            $this->status = null;
        }
        $snaps = $this->snapshots;
        $st = $this->status();
        foreach ($sources as $id => $path) {
            $sig = $this->signature($path);
            $known = $snaps[$id]['source'] ?? null;
            $failed = $st['files'][$id]['failed_source'] ?? null;
            if ($known != $sig && $failed != $sig) {
                $this->reloadFile($id, $path, 'change');
            }
        }
        // forget status of files that no longer exist
        $st = $this->status();
        $st['files'] = array_intersect_key($st['files'] ?? [], $sources);
        $this->status = $st;

        return $this->snapshots;
    }

    /** Back-compat: true if any data is loaded. */
    public function snapshot(): ?array
    {
        return $this->snapshots() ? $this->combined() : null;
    }

    /** Parse one file and atomically swap its snapshot. Keeps the old one on failure. */
    public function reloadFile(string $id, string $path, string $reason = 'manual'): bool
    {
        $lock = fopen($this->dir.'/.reload.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $st = $this->status();
            $st['last_attempt'] = $this->now();
            $idxPath = $this->dir.'/index/'.$id.'.json';
            $old = $this->readJson($idxPath);
            $sig = is_file($path) ? $this->signature($path) : null;
            if ($sig && ($old['source'] ?? null) == $sig && $reason === 'change') {
                $this->snapshots[$id] = $old;      // another request already reloaded it

                return true;
            }
            try {
                if (! $sig) {
                    throw new InventoryReadException('الملف غير موجود على الخادم');
                }
                $snap = (new InventoryParser($this->cfg))->parseFile($path);
                if ($this->signature($path) != $sig) {
                    throw new InventoryReadException('الملف تغيّر أثناء القراءة');
                }
                $snap['source'] = $sig;
                $snap['id'] = $id;
                $snap['name'] = basename($path);
                $snap['loaded_at'] = $this->now();
                $change = InventoryParser::diff($old, $snap);
                $this->writeJson($idxPath, $snap);
                $this->snapshots[$id] = $snap;
                $st['files'][$id] = ['last_error' => null, 'failed_source' => null, 'last_success' => $this->now()];
                $st['last_success'] = $this->now();
                if (array_sum($change) > 0 || $st['last_change'] === null) {
                    $st['last_change'] = $change + ['file' => basename($path)];
                }
                $st['load_count'] = ($st['load_count'] ?? 0) + 1;
                $this->saveStatus($st);
                Log::info(sprintf('Inventory file loaded (%s): %d rows, +%d ~%d -%d',
                    $reason, $snap['total_rows'], $change['added'], $change['modified'], $change['deleted']));

                return true;
            } catch (InventoryReadException $e) {
                $st['files'][$id] = array_merge($st['files'][$id] ?? [], [
                    'last_error' => $e->getMessage(), 'failed_source' => $sig,
                ]);
                $this->saveStatus($st);
                if ($old) {
                    $this->snapshots[$id] = $old;
                }
                Log::warning('Inventory file reload failed; keeping previous snapshot: '.$e->getMessage());

                return false;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Re-parse every file now. */
    public function reload(string $reason = 'manual'): bool
    {
        $this->snapshots ??= [];
        $ok = true;
        foreach ($this->sourceFiles() as $id => $path) {
            $ok = $this->reloadFile($id, $path, $reason) && $ok;
        }
        $this->snapshots = null;

        return $ok && $this->sourceFiles() !== [];
    }

    /**
     * Combined barcode index across files (or one file).
     * Each record gets location.file / location.file_id.
     */
    public function combined(?string $fileId = null): array
    {
        $records = [];
        $total = 0;
        $warnings = [];
        foreach ($this->snapshots() as $id => $snap) {
            if ($fileId !== null && $fileId !== '' && $id !== $fileId) {
                continue;
            }
            foreach ($snap['records'] as $key => $list) {
                foreach ($list as $rec) {
                    $rec['location']['file'] = $snap['name'];
                    $rec['location']['file_id'] = $id;
                    $records[$key][] = $rec;
                    $total++;
                }
            }
        }
        $duplicates = [];
        foreach ($records as $k => $list) {
            if (count($list) > 1) {
                $duplicates[$k] = array_map(fn ($r) => $r['location'], $list);
            }
        }
        if ($duplicates) {
            $warnings[] = 'يوجد '.count($duplicates).' باركود مكرر';
        }

        return ['records' => $records, 'duplicates' => $duplicates, 'total_rows' => $total, 'warnings' => $warnings];
    }

    public function files(): array
    {
        $snaps = $this->snapshots();
        $st = $this->status();
        $out = [];
        foreach ($this->sourceFiles() as $id => $path) {
            $s = $snaps[$id] ?? null;
            $err = $st['files'][$id]['last_error'] ?? null;
            $out[] = [
                'id' => $id,
                'name' => basename($path),
                'records' => $s['total_rows'] ?? 0,
                'duplicate_barcodes' => $s ? count($s['duplicates']) : 0,
                'loaded_at' => $s['loaded_at'] ?? null,
                'state' => $s === null ? 'error' : ($err ? 'stale' : 'ok'),
                'last_error' => $err,
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['loaded_at'], (string) $a['loaded_at']));

        return $out;
    }

    public function state(): array
    {
        $snaps = $this->snapshots();
        $files = $this->files();
        $comb = $this->combined();
        $st = $this->status();
        $errors = array_values(array_filter(array_map(
            fn ($f) => $f['last_error'] ? $f['name'].': '.$f['last_error'] : null, $files)));
        if (! $snaps) {
            $state = $errors ? 'error' : 'loading';
        } else {
            $state = $errors ? 'stale' : 'ok';
        }

        return [
            'state' => $state,
            'files' => count($files),
            'records' => $comb['total_rows'],
            'unique_barcodes' => count($comb['records']),
            'duplicate_barcodes' => count($comb['duplicates']),
            'warnings' => $comb['warnings'],
            'last_success' => $st['last_success'],
            'last_attempt' => $st['last_attempt'],
            'last_error' => $errors ? implode(' | ', $errors) : null,
            'last_change' => $st['last_change'],
            'load_count' => $st['load_count'] ?? 0,
        ];
    }

    private function backup(string $path): void
    {
        $info = pathinfo($path);
        copy($path, $this->dir.'/backups/'.$info['filename'].'-'.date('Ymd-His').'.'.$info['extension']);
        $all = glob($this->dir.'/backups/*.*') ?: [];
        usort($all, fn ($a, $b) => filemtime($a) <=> filemtime($b));
        $keep = max(1, (int) ($this->cfg['backups_keep'] ?? 10)) * 5;
        foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
            @unlink($old);
        }
    }

    /**
     * Validate an uploaded file and store it next to the others.
     * A file with the same name replaces the old one (old version backed up).
     *
     * @throws InventoryReadException
     */
    public function addUpload(string $tmpPath, string $ext, string $originalName = 'inventory'): array
    {
        $snap = (new InventoryParser($this->cfg))->parseFile($tmpPath);   // validate first
        $name = self::safeName($originalName, $ext);
        $id = self::fileId($name);
        $target = $this->dir.'/files/'.$name;
        $replaced = false;
        foreach (self::EXTS as $e) {                 // same base name, other extension -> replace too
            $other = $this->dir.'/files/'.pathinfo($name, PATHINFO_FILENAME).'.'.$e;
            if (is_file($other)) {
                $this->backup($other);
                $replaced = true;
                if ($other !== $target) {
                    @unlink($other);
                    @unlink($this->dir.'/index/'.self::fileId(basename($other)).'.json');
                }
            }
        }
        $staging = $this->dir.'/files/.upload-'.bin2hex(random_bytes(4));
        copy($tmpPath, $staging);
        rename($staging, $target);
        $this->snapshots = null;
        $this->snapshots();
        $this->reloadFile($id, $target, 'upload');

        return $snap + ['file' => ['id' => $id, 'name' => $name, 'replaced' => $replaced]];
    }

    /** Back-compat for the demo command. */
    public function replaceWithUpload(string $tmpPath, string $ext, string $name = 'inventory'): array
    {
        return $this->addUpload($tmpPath, $ext, $name);
    }

    public function deleteFile(string $id): bool
    {
        $path = $this->sourceFiles()[$id] ?? null;
        if (! $path) {
            return false;
        }
        $this->backup($path);
        @unlink($path);
        @unlink($this->dir.'/index/'.$id.'.json');
        $st = $this->status();
        unset($st['files'][$id]);
        $this->saveStatus($st);
        $this->snapshots = null;
        Log::info('Inventory file deleted');

        return true;
    }
}
