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

    public function item(string $barcode): JsonResponse
    {
        if (! InventoryParser::validBarcode($barcode)) {
            return response()->json(['detail' => 'صيغة الباركود غير صالحة'], 400);
        }
        $snap = $this->store->snapshot();
        if ($snap === null) {
            return response()->json(['detail' => 'لم يتم تحميل ملف المخزون بعد'], 503);
        }
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
        $snap = $this->store->snapshot();
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
            $snap = $this->store->replaceWithUpload($tmpExt, $ext);
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
            'excel' => $this->store->state(),
        ]);
    }
}
