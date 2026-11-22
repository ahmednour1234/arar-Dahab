<?php

namespace App\Http\Controllers;

use App\Services\InventoryParser;
use App\Services\InventoryReadException;
use App\Services\InventoryStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScannerController extends Controller
{
    public function __construct(private InventoryStore $store)
    {
    }

    private function cfg(string $k, $default = null)
    {
        return config('goldscanner.'.$k, $default);
    }

    public function page()
    {
        return response()->view('scanner')->header('Cache-Control', 'no-store');
    }

    public function configStatus(): JsonResponse
    {
        $fields = [];
        foreach ($this->cfg('fields', []) as $k => $l) {
            $fields[] = ['key' => $k, 'label' => $l];
        }
        $suffix = $this->cfg('scanner_suffix');

        return response()->json([
            'app_name' => 'ماسح الذهب',
            'version' => '1.0.0-laravel',
            'auth_required' => (string) $this->cfg('access_key') !== '',
            'demo_mode' => (bool) $this->cfg('demo_mode'),
            'scanner_suffix' => in_array($suffix, ['enter', 'tab', 'none'], true) ? $suffix : 'enter',
            'scanner_idle_submit_ms' => $this->cfg('scanner_idle_submit_ms', 250),
            'poll_interval_seconds' => $this->cfg('poll_interval_seconds', 5),
            'fields' => $fields,
            'upload_enabled' => (bool) $this->cfg('upload_enabled'),
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'server' => 'ok',
            'server_time' => now()->toIso8601String(),
            'excel' => $this->store->state(),
        ]);
    }

    public function item(Request $request, string $barcode): JsonResponse
    {
        if (! InventoryParser::validBarcode($barcode)) {
            return response()->json(['detail' => 'صيغة الباركود غير صالحة'], 400);
        }
        if (! $this->store->snapshots()) {
            return response()->json(['detail' => 'لم يتم تحميل ملف المخزون بعد'], 503);
        }
        $snap = $this->store->combined($this->fileFilter($request));
        $st = $this->store->state();
        $meta = ['data_state' => $st['state'], 'last_success' => $st['last_success'], 'last_error' => $st['last_error']];
        $key = InventoryParser::normalizeKey($barcode, (bool) $this->cfg('case_insensitive_barcodes', true));
        $recs = $snap['records'][$key] ?? [];

        if (! $recs) {
            return response()->json(['detail' => 'القطعة غير موجودة في ملف المخزون', 'barcode' => trim($barcode)] + $meta, 404);
        }
        if (count($recs) > 1) {
            return response()->json([
                'detail' => 'باركود مكرر في ملف المخزون — لا يمكن تحديد القطعة بدقة',
                'barcode' => trim($barcode),
                'count' => count($recs),
                'locations' => array_map(fn ($r) => $r['location'], $recs),
            ] + $meta, 409);
        }

        return response()->json(['item' => $recs[0]] + $meta);
    }

    public function demoBarcodes(): JsonResponse
    {
        if (! $this->cfg('demo_mode')) {
            return response()->json(['detail' => 'غير متاح'], 404);
        }
        $snap = $this->store->snapshots() ? $this->store->combined() : null;
        $out = [];
        if ($snap) {
            $singles = [];
            $dup = null;
            foreach ($snap['records'] as $list) {
                if (count($list) === 1) {
                    $singles[] = $list[0]['fields'];
                } elseif ($dup === null) {
                    $dup = $list[0]['fields']['barcode'];
                }
            }
            $picks = array_slice($singles, 0, 6);
            foreach ($singles as $f) {
                if (str_starts_with($f['barcode'], '0')) {
                    $picks[] = $f;
                    break;
                }
            }
            foreach ($singles as $f) {
                if (! ctype_digit($f['barcode'])) {
                    $picks[] = $f;
                    break;
                }
            }
            $seen = [];
            foreach ($picks as $f) {
                if (! isset($seen[$f['barcode']])) {
                    $seen[$f['barcode']] = true;
                    $out[] = ['barcode' => $f['barcode'], 'name' => $f['item_name'] ?? ''];
                }
            }
            if ($dup !== null) {
                $out[] = ['barcode' => $dup, 'name' => 'باركود مكرر (تجربة)'];
            }
        }
        $out[] = ['barcode' => '999999999', 'name' => 'غير موجود (تجربة)'];

        return response()->json(['barcodes' => $out]);
    }

    /** Raw-body upload (application/octet-stream) protected by X-Admin-Key. */
    public function upload(Request $request): JsonResponse
    {
        if (! $this->cfg('upload_enabled')) {
            return response()->json(['detail' => 'رفع الملفات غير مفعّل'], 404);
        }
        $admin = (string) $this->cfg('admin_key');
        if ($admin === '' || ! hash_equals($admin, (string) $request->header('X-Admin-Key', ''))) {
            return response()->json(['detail' => 'مفتاح المسؤول غير صحيح'], 401);
        }
        $limit = (int) ($this->cfg('max_upload_mb', 20) * 1024 * 1024);
        $data = $request->getContent();
        if (strlen($data) > $limit) {
            return response()->json(['detail' => 'الملف أكبر من '.$this->cfg('max_upload_mb').' ميجابايت'], 413);
        }
        if (str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            $ext = 'xls';
        } elseif (str_starts_with($data, "PK\x03\x04")) {
            $ext = 'xlsx';
        } else {
            return response()->json(['detail' => 'الملف ليس ملف Excel صالحاً (.xls أو .xlsx)'], 400);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gs');
        $tmpExt = $tmp.'.'.$ext;
        rename($tmp, $tmpExt);
        file_put_contents($tmpExt, $data);
        try {
            $name = rawurldecode((string) $request->header('X-Filename', 'inventory.'.$ext));
            $snap = $this->store->addUpload($tmpExt, $ext, $name);
        } catch (InventoryReadException $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        } finally {
            @unlink($tmpExt);
        }
        Log::info('Inventory uploaded: '.$snap['total_rows'].' rows');

        return response()->json([
            'ok' => true,
            'records' => $snap['total_rows'],
            'duplicate_barcodes' => count($snap['duplicates']),
            'warnings' => $snap['warnings'],
            'file' => $snap['file'],
            'files' => $this->store->files(),
            'excel' => $this->store->state(),
        ]);
    }

    private function fileFilter(Request $request): ?string
    {
        $f = (string) $request->query('file', '');

        return preg_match('/^[0-9a-f]{12}$/', $f) ? $f : null;
    }

    public function files(): JsonResponse
    {
        return response()->json(['files' => $this->store->files()]);
    }

    /** All items (optionally one file), searchable, paginated. */
    public function items(Request $request): JsonResponse
    {
        $snap = $this->store->combined($this->fileFilter($request));
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $perPage = max(10, min(1000, (int) $request->query('per_page', 100)));
        $page = max(1, (int) $request->query('page', 1));
        $rows = [];
        foreach ($snap['records'] as $key => $list) {
            foreach ($list as $rec) {
                $f = $rec['fields'];
                if ($q !== '') {
                    $hay = mb_strtolower(implode(' ', $f).' '.$rec['location']['file']);
                    if (! str_contains($hay, $q)) {
                        continue;
                    }
                }
                $rows[] = [
                    'barcode' => $f['barcode'],
                    'item_name' => $f['item_name'] ?? '',
                    'category' => $f['category'] ?? '',
                    'karat' => $f['karat'] ?? '',
                    'weight' => $f['weight'] ?? '',
                    'price' => $f['price'] ?? '',
                    'status' => $f['status'] ?? '',
                    'file' => $rec['location']['file'],
                    'file_id' => $rec['location']['file_id'],
                    'row' => $rec['location']['row'],
                    'duplicate' => isset($snap['duplicates'][$key]),
                ];
            }
        }
        usort($rows, fn ($a, $b) => [$a['file'], $a['row']] <=> [$b['file'], $b['row']]);
        $total = count($rows);

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
        ]);
    }

    public function deleteFile(Request $request, string $id): JsonResponse
    {
        $admin = (string) $this->cfg('admin_key');
        if (! $this->cfg('upload_enabled')) {
            return response()->json(['detail' => 'رفع الملفات غير مفعّل'], 404);
        }
        if ($admin === '' || ! hash_equals($admin, (string) $request->header('X-Admin-Key', ''))) {
            return response()->json(['detail' => 'مفتاح المسؤول غير صحيح'], 401);
        }
        if (! $this->store->deleteFile($id)) {
            return response()->json(['detail' => 'الملف غير موجود'], 404);
        }

        return response()->json(['ok' => true, 'files' => $this->store->files(), 'excel' => $this->store->state()]);
    }
}
