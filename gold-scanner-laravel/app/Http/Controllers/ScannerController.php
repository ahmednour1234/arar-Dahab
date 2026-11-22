<?php

namespace App\Http\Controllers;

use App\Services\InventoryParser;
use App\Services\InventoryReadException;
use App\Services\InventoryStore;
use App\Services\LiveFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScannerController extends Controller
{
    public function __construct(private InventoryStore $store, private LiveFeed $live)
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

    public function help()
    {
        return response()->view('help');
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
            'admin_required' => (string) $this->cfg('admin_key') !== '',
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
        $response = $this->lookup($request, $barcode);
        $channel = (string) $request->query('live', '');
        if ($channel !== '' && $response->getStatusCode() !== 400) {
            $this->live->push(LiveFeed::cleanChannel($channel), [
                'device' => mb_substr(trim((string) $request->query('device', '')), 0, 40),
                'barcode' => trim($barcode),
                'status' => $response->getStatusCode(),
                'body' => $response->getData(true),
            ]);
        }

        return $response;
    }

    /** Long-poll-free live feed: events newer than ?after=<id> for ?channel=. */
    public function live(Request $request): JsonResponse
    {
        $channel = LiveFeed::cleanChannel((string) $request->query('channel', 'main'));

        return response()->json(['channel' => $channel] + $this->live->since($channel, max(0, (int) $request->query('after', 0))));
    }

    /** Build an .xlsx of selected scans from a live channel. Body: {"channel": "...", "ids": [1,2,...]} */
    public function exportLive(Request $request)
    {
        $channel = LiveFeed::cleanChannel((string) $request->input('channel', 'main'));
        $ids = array_slice(array_filter(array_map('intval', (array) $request->input('ids', []))), 0, 2000);
        $events = $this->live->byIds($channel, $ids);
        if (! $events) {
            return response()->json(['detail' => 'لا توجد عمليات مسح للحفظ'], 404);
        }

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sh = $book->getActiveSheet();
        $sh->setTitle('المسح');
        $sh->setRightToLeft(true);
        $head = ['#', 'التاريخ', 'الوقت', 'الجهاز', 'الباركود', 'اسم القطعة', 'الفئة', 'العيار', 'الوزن (جم)', 'المصنعية', 'السعر', 'الحالة', 'الفرع', 'تفاصيل إضافية', 'الملف', 'النتيجة'];
        $sh->fromArray($head, null, 'A1');
        $r = 2;
        $found = 0;
        $weight = 0.0;
        $price = 0.0;
        foreach ($events as $i => $ev) {
            $b = $ev['body'] ?? [];
            $f = $b['item']['fields'] ?? [];
            $ts = \Illuminate\Support\Carbon::parse($ev['ts'])->setTimezone(config('app.timezone'));
            $result = match ((int) $ev['status']) {
                200 => 'موجودة',
                404 => 'غير موجودة',
                409 => 'باركود مكرر',
                default => 'خطأ',
            };
            $extra = implode(' | ', array_map(fn ($x) => $x['label'].': '.$x['value'], $b['item']['extra'] ?? []));
            $row = [$i + 1, $ts->format('Y-m-d'), $ts->format('H:i:s'), $ev['device'] ?? '', null,
                $f['item_name'] ?? '', $f['category'] ?? '', $f['karat'] ?? '', null, null, null,
                $f['status'] ?? '', $f['branch'] ?? '', $extra, $b['item']['location']['file'] ?? '', $result];
            $sh->fromArray($row, null, 'A'.$r);
            // barcode always as text (keeps leading zeros); numbers as real numbers
            $sh->setCellValueExplicit('E'.$r, (string) ($f['barcode'] ?? $ev['barcode']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            foreach (['I' => 'weight', 'J' => 'making_charge', 'K' => 'price'] as $col => $k) {
                $v = $f[$k] ?? '';
                if ($v !== '' && is_numeric($v)) {
                    $sh->setCellValue($col.$r, (float) $v);
                } else {
                    $sh->setCellValueExplicit($col.$r, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            if ((int) $ev['status'] === 200) {
                $found++;
                $weight += is_numeric($f['weight'] ?? null) ? (float) $f['weight'] : 0;
                $price += is_numeric($f['price'] ?? null) ? (float) $f['price'] : 0;
            } else {
                $sh->getStyle('A'.$r.':P'.$r)->getFont()->getColor()->setRGB('C62828');
            }
            $r++;
        }
        // totals
        $sh->setCellValue('A'.$r, 'الإجمالي');
        $sh->setCellValue('E'.$r, 'عدد القطع الموجودة: '.$found.' من '.count($events));
        $sh->setCellValue('I'.$r, round($weight, 3));
        $sh->setCellValue('K'.$r, round($price, 2));
        $sh->getStyle('A'.$r.':P'.$r)->getFont()->setBold(true);
        $sh->getStyle('A'.$r.':P'.$r)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF4D6');
        $sh->getStyle('A1:P1')->getFont()->setBold(true);
        $sh->getStyle('A1:P1')->getFill()->setFillType('solid')->getStartColor()->setRGB('D4AF37');
        $sh->getStyle('I2:K'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'P') as $col) {
            $sh->getColumnDimension($col)->setAutoSize(true);
        }
        $sh->freezePane('A2');
        $sh->setAutoFilter('A1:P'.($r - 1));

        $name = 'مسح-'.$channel.'-'.now()->format('Y-m-d-His').'.xlsx';
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx');

        return response()->streamDownload(function () use ($writer, $book) {
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function lookup(Request $request, string $barcode): JsonResponse
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
        $matchedAs = null;
        if (! $recs && preg_match('/^\d+$/', $key)) {
            $want = ltrim($key, '0');
            foreach ($snap['records'] as $k => $list) {
                if (preg_match('/^\d+$/', (string) $k) && ltrim((string) $k, '0') === $want) {
                    $recs = array_merge($recs, $list);
                    $matchedAs = (string) $k;
                }
            }
        }
        if ($matchedAs !== null) {
            $meta['matched_as'] = $matchedAs;   // e.g. scanned 000464, stored as 464
        }

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
        if ($admin !== '' && ! hash_equals($admin, (string) $request->header('X-Admin-Key', ''))) {
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
        } elseif (preg_match('/\.csv$/i', rawurldecode((string) $request->header('X-Filename', ''))) && ! str_contains($data, "\0")) {
            $ext = 'csv';
        } else {
            return response()->json(['detail' => 'الملف ليس ملف Excel أو CSV صالحاً (.xls أو .xlsx أو .csv)'], 400);
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
        if ($admin !== '' && ! hash_equals($admin, (string) $request->header('X-Admin-Key', ''))) {
            return response()->json(['detail' => 'مفتاح المسؤول غير صحيح'], 401);
        }
        if (! $this->store->deleteFile($id)) {
            return response()->json(['detail' => 'الملف غير موجود'], 404);
        }

        return response()->json(['ok' => true, 'files' => $this->store->files(), 'excel' => $this->store->state()]);
    }
}
