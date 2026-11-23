<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Stock counts (جرد): saved on the server, exported as a standalone Excel file,
 * or appended as new sheets ("جرد ...") to the inventory Excel file itself.
 * Sheets whose name starts with "جرد " are ignored when reading inventory.
 */
class InventoryCount
{
    public const SHEET_PREFIX = 'جرد ';

    private string $dir;

    public function __construct(private InventoryStore $store, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/counts');
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    // ---------------------------------------------------------------- storage

    /** Create a count from live-feed events. $fileId limits the comparison ("missing") to one file. */
    public function create(string $name, array $events, ?string $fileId = null): array
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'جرد '.now()->format('Y-m-d H:i');
        $rows = [];
        foreach ($events as $ev) {
            $b = $ev['body'] ?? [];
            $rows[] = [
                'ts' => $ev['ts'] ?? now()->toIso8601String(),
                'device' => $ev['device'] ?? '',
                'barcode' => (string) ($b['item']['fields']['barcode'] ?? $ev['barcode'] ?? ''),
                'scanned' => (string) ($ev['barcode'] ?? ''),
                'status' => (int) ($ev['status'] ?? 0),
                'fields' => $b['item']['fields'] ?? [],
                'extra' => $b['item']['extra'] ?? [],
                'location' => $b['item']['location'] ?? null,
                'locations' => $b['locations'] ?? [],
            ];
        }
        $count = [
            'id' => now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)),
            'name' => $name,
            'created_at' => now()->toIso8601String(),
            'file_id' => $fileId,
            'rows' => $rows,
        ];
        $count['summary'] = $this->summary($count);
        file_put_contents($this->dir.'/'.$count['id'].'.json', json_encode($count, JSON_UNESCAPED_UNICODE));

        return $count;
    }

    public function get(string $id): ?array
    {
        if (! preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
            return null;
        }
        $p = $this->dir.'/'.$id.'.json';
        $d = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;

        return is_array($d) ? $d : null;
    }

    public function all(bool $trash = false): array
    {
        $out = [];
        foreach (glob($this->dir.'/*.json') ?: [] as $p) {
            $d = json_decode((string) file_get_contents($p), true);
            if (is_array($d) && empty($d['deleted_at']) === ! $trash) {
                $out[] = ['deleted_at' => $d['deleted_at'] ?? null, 'merged_from' => $d['merged_from'] ?? [], 'id' => $d['id'], 'name' => $d['name'], 'created_at' => $d['created_at'],
                    'file_id' => $d['file_id'] ?? null, 'summary' => $d['summary'] ?? [], 'appended' => $d['appended'] ?? []];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    /** Soft delete: the count is kept and can be restored. */
    public function delete(string $id): bool
    {
        return $this->mark($id, now()->toIso8601String());
    }

    public function restore(string $id): bool
    {
        return $this->mark($id, null);
    }

    private function mark(string $id, ?string $deletedAt): bool
    {
        $c = $this->get($id);
        if (! $c || ($deletedAt === null) === empty($c['deleted_at'])) {
            return false;
        }
        $c['deleted_at'] = $deletedAt;

        return (bool) file_put_contents($this->dir.'/'.$id.'.json', json_encode($c, JSON_UNESCAPED_UNICODE));
    }

    /** Merge several counts into a new one (all their scans; the originals stay as they are). */
    public function merge(string $name, array $ids): ?array
    {
        $parts = array_values(array_filter(array_map(fn ($i) => $this->get((string) $i), array_unique($ids)),
            fn ($c) => $c && empty($c['deleted_at'])));
        if (count($parts) < 2) {
            return null;
        }
        usort($parts, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        $files = array_values(array_unique(array_map(fn ($c) => (string) ($c['file_id'] ?? ''), $parts)));
        $count = [
            'id' => now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)),
            'name' => trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'جرد مدمج '.now()->format('Y-m-d'),
            'created_at' => now()->toIso8601String(),
            'file_id' => count($files) === 1 && $files[0] !== '' ? $files[0] : null,
            'merged_from' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name']], $parts),
            'rows' => array_merge(...array_map(fn ($c) => $c['rows'], $parts)),
        ];
        $count['summary'] = $this->summary($count);
        file_put_contents($this->dir.'/'.$count['id'].'.json', json_encode($count, JSON_UNESCAPED_UNICODE));

        return $count;
    }

    // ---------------------------------------------------------------- analysis

    /** One row per distinct barcode (first scan), with how many times it was scanned. */
    private function uniqueRows(array $count): array
    {
        $by = [];
        foreach ($count['rows'] as $r) {
            $k = InventoryParser::normalizeKey($r['barcode'] !== '' ? $r['barcode'] : $r['scanned']);
            if (! isset($by[$k])) {
                $by[$k] = $r + ['times' => 0];
            }
            $by[$k]['times']++;
        }

        return array_values($by);
    }

    /** Inventory items (in the compared file(s)) that were not scanned. */
    public function missing(array $count): array
    {
        $scanned = [];
        foreach ($count['rows'] as $r) {
            // found (200) or ambiguous duplicate (409): scanned, so not "missing"
            if ($r['status'] === 200 || $r['status'] === 409) {
                $scanned[InventoryParser::normalizeKey($r['barcode'] !== '' ? $r['barcode'] : $r['scanned'])] = true;
            }
        }
        $comb = $this->store->combined($count['file_id'] ?? null);
        $out = [];
        foreach ($comb['records'] as $key => $list) {
            if (isset($scanned[$key])) {
                continue;
            }
            foreach ($list as $rec) {
                $out[] = $rec;
            }
        }
        usort($out, fn ($a, $b) => [$a['location']['file'] ?? '', $a['location']['row']] <=> [$b['location']['file'] ?? '', $b['location']['row']]);

        return $out;
    }

    public function summary(array $count): array
    {
        $u = $this->uniqueRows($count);
        $found = array_filter($u, fn ($r) => $r['status'] === 200);
        $w = 0.0;
        foreach ($found as $r) {
            $w += is_numeric($r['fields']['weight'] ?? null) ? (float) $r['fields']['weight'] : 0;
        }
        $comb = $this->store->combined($count['file_id'] ?? null);

        return [
            'scans' => count($count['rows']),
            'unique' => count($u),
            'found' => count($found),
            'not_found' => count(array_filter($u, fn ($r) => $r['status'] === 404)),
            'duplicates' => count(array_filter($u, fn ($r) => $r['status'] === 409)),
            'scanned_twice' => count(array_filter($u, fn ($r) => $r['times'] > 1)),
            'in_file' => $comb['total_rows'],
            'missing' => count($this->missing($count)),
            'weight_found' => round($w, 3),
        ];
    }

    // ---------------------------------------------------------------- Excel

    public static function sheetBase(string $name): string
    {
        $n = preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $name);
        $n = trim(preg_replace('/\s+/u', ' ', $n));
        if (str_starts_with($n, trim(self::SHEET_PREFIX))) {
            $n = trim(mb_substr($n, mb_strlen(trim(self::SHEET_PREFIX))));
        }

        return mb_substr($n !== '' ? $n : now()->format('Y-m-d'), 0, 20);
    }

    private static function uniqueTitle(Spreadsheet $book, string $title): string
    {
        $t = mb_substr($title, 0, 31);
        for ($i = 2; $book->sheetNameExists($t); $i++) {
            $suffix = " ($i)";
            $t = mb_substr($title, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        return $t;
    }

    /** Add the count sheet + the "missing" sheet to a workbook. Returns the sheet titles. */
    public function addSheets(Spreadsheet $book, array $count): array
    {
        $base = self::sheetBase($count['name']);
        $t1 = self::uniqueTitle($book, self::SHEET_PREFIX.$base);
        $sh = new Worksheet($book, $t1);
        $book->addSheet($sh);
        $this->fillCountSheet($sh, $count);

        $t2 = self::uniqueTitle($book, self::SHEET_PREFIX.'ناقص '.$base);
        $sh2 = new Worksheet($book, $t2);
        $book->addSheet($sh2);
        $this->fillMissingSheet($sh2, $count);

        $t3 = self::uniqueTitle($book, self::SHEET_PREFIX.'ملخص '.$base);
        $sh3 = new Worksheet($book, $t3);
        $book->addSheet($sh3);
        $this->fillSummarySheet($sh3, $count);

        return [$t1, $t2, $t3];
    }

    private function fillCountSheet(Worksheet $sh, array $count): void
    {
        $sh->setRightToLeft(true);
        $head = ['#', 'التاريخ', 'الوقت', 'الجهاز', 'الباركود', 'اسم القطعة', 'الفئة', 'العيار', 'الوزن (جم)', 'المصنعية', 'السعر',
            'الحالة', 'الفرع', 'تفاصيل إضافية', 'الملف', 'الصف', 'النتيجة', 'مرات المسح'];
        $sh->fromArray($head, null, 'A1');
        $r = 2;
        foreach ($this->uniqueRows($count) as $i => $row) {
            $f = $row['fields'];
            $ts = \Illuminate\Support\Carbon::parse($row['ts'])->setTimezone(config('app.timezone'));
            $result = match ($row['status']) {
                200 => 'موجودة', 404 => 'غير موجودة في الملف', 409 => 'باركود مكرر في الملف', default => 'خطأ',
            };
            $extra = implode(' | ', array_map(fn ($x) => $x['label'].': '.$x['value'], $row['extra']));
            $sh->fromArray([$i + 1, $ts->format('Y-m-d'), $ts->format('H:i:s'), $row['device'], null,
                $f['item_name'] ?? '', $f['category'] ?? '', $f['karat'] ?? '', null, null, null,
                $f['status'] ?? '', $f['branch'] ?? '', $extra, $row['location']['file'] ?? '', $row['location']['row'] ?? '',
                $result, $row['times']], null, 'A'.$r);
            $sh->setCellValueExplicit('E'.$r, $row['barcode'] !== '' ? $row['barcode'] : $row['scanned'], DataType::TYPE_STRING);
            foreach (['I' => 'weight', 'J' => 'making_charge', 'K' => 'price'] as $col => $k) {
                $v = $f[$k] ?? '';
                is_numeric($v) ? $sh->setCellValue($col.$r, (float) $v) : $sh->setCellValueExplicit($col.$r, (string) $v, DataType::TYPE_STRING);
            }
            if ($row['status'] !== 200) {
                $sh->getStyle("A$r:R$r")->getFont()->getColor()->setRGB('C62828');
            } elseif ($row['times'] > 1) {
                $sh->getStyle("A$r:R$r")->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF4D6');
            }
            $r++;
        }
        $last = $r - 1;
        $this->styleHeader($sh, 'R', $last);
    }

    private function fillSummarySheet(Worksheet $sh, array $count): void
    {
        $sh->setRightToLeft(true);
        $s = $count['summary'] ?? $this->summary($count);
        $lines = [
            ['البيان', 'القيمة'],
            ['اسم الجرد', $count['name']],
            ['تاريخ الجرد', \Illuminate\Support\Carbon::parse($count['created_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i')],
            ['عدد عمليات المسح', $s['scans']],
            ['قطع مختلفة ممسوحة', $s['unique']],
            ['موجودة في الملف', $s['found']],
            ['غير موجودة في الملف', $s['not_found']],
            ['باركود مكرر في الملف', $s['duplicates']],
            ['اتمسحت أكتر من مرة', $s['scanned_twice']],
            ['عدد القطع في الملف', $s['in_file']],
            ['ناقصة (ما اتمسحتش)', $s['missing']],
            ['إجمالي وزن الموجود (جم)', $s['weight_found']],
        ];
        $sh->fromArray($lines, null, 'A1');
        $sh->getStyle('A2:A'.count($lines))->getFont()->setBold(true);
        $sh->getStyle('B1:B'.count($lines))->getAlignment()->setHorizontal('center');
        $sh->getStyle('A1:B1')->getFont()->setBold(true);
        $sh->getStyle('A1:B1')->getFill()->setFillType('solid')->getStartColor()->setRGB('D4AF37');
        $sh->getColumnDimension('A')->setWidth(30);
        $sh->getColumnDimension('B')->setWidth(22);
    }

    private function fillMissingSheet(Worksheet $sh, array $count): void
    {
        $sh->setRightToLeft(true);
        $sh->fromArray(['#', 'الباركود', 'اسم القطعة', 'الفئة', 'العيار', 'الوزن (جم)', 'السعر', 'الحالة', 'الفرع', 'الملف', 'الورقة', 'الصف'], null, 'A1');
        $r = 2;
        $w = 0.0;
        foreach ($this->missing($count) as $i => $rec) {
            $f = $rec['fields'];
            $sh->fromArray([$i + 1, null, $f['item_name'] ?? '', $f['category'] ?? '', $f['karat'] ?? '', null,
                null, $f['status'] ?? '', $f['branch'] ?? '', $rec['location']['file'] ?? '', $rec['location']['sheet'] ?? '', $rec['location']['row'] ?? ''], null, 'A'.$r);
            $sh->setCellValueExplicit('B'.$r, (string) $f['barcode'], DataType::TYPE_STRING);
            foreach (['F' => 'weight', 'G' => 'price'] as $col => $k) {
                $v = $f[$k] ?? '';
                is_numeric($v) ? $sh->setCellValue($col.$r, (float) $v) : $sh->setCellValueExplicit($col.$r, (string) $v, DataType::TYPE_STRING);
            }
            $w += is_numeric($f['weight'] ?? null) ? (float) $f['weight'] : 0;
            $r++;
        }
        $last = $r - 1;
        $sh->setCellValue('A'.$r, 'الإجمالي');
        $sh->setCellValue('B'.$r, 'عدد القطع الناقصة: '.($r - 2));
        $sh->setCellValue('F'.$r, round($w, 3));
        $sh->getStyle("A$r:L$r")->getFont()->setBold(true);
        $this->styleHeader($sh, 'L', $last);
    }

    private function styleHeader(Worksheet $sh, string $lastCol, int $lastRow): void
    {
        $sh->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sh->getStyle("A1:{$lastCol}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('D4AF37');
        foreach (range('A', $lastCol) as $c) {
            $sh->getColumnDimension($c)->setAutoSize(true);
        }
        $sh->freezePane('A2');
        if ($lastRow >= 2) {
            $sh->setAutoFilter("A1:{$lastCol}{$lastRow}");
        }
    }

    /** Standalone workbook for a count. */
    public function standaloneWorkbook(array $count): Spreadsheet
    {
        $book = new Spreadsheet;
        $this->addSheets($book, $count);
        $book->removeSheetByIndex(0);   // the default empty sheet
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * A COPY of an inventory file with the count sheets added after its sheets.
     * The stored file is never touched. Returns [Spreadsheet, writerType, downloadName, sheetTitles].
     *
     * @throws InventoryReadException
     */
    public function fileCopyWithCount(array $count, string $fileId): array
    {
        $path = $this->store->sourceFiles()[$fileId] ?? null;
        if (! $path) {
            throw new InventoryReadException('الملف غير موجود');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            throw new InventoryReadException('ملفات CSV ما ينفعش يتضاف لها صفحات. اختار "ملف Excel لوحده"، أو ارفع الملف بصيغة xlsx.');
        }
        $book = IOFactory::createReaderForFile($path)->load($path);
        $titles = $this->addSheets($book, $count);
        $book->setActiveSheetIndex(0);

        return [$book, $ext === 'xls' ? 'Xls' : 'Xlsx', basename($path), $titles];
    }

    /**
     * Append the count sheets to an inventory Excel file on the server (backup first).
     * The file is re-read afterwards; the new "جرد ..." sheets are ignored as inventory.
     *
     * @throws InventoryReadException
     */
    public function appendToFile(array $count, string $fileId): array
    {
        $path = $this->store->sourceFiles()[$fileId] ?? null;
        if (! $path) {
            throw new InventoryReadException('الملف غير موجود');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            throw new InventoryReadException('ملفات CSV ما ينفعش يتضاف لها صفحات. اختار "ملف Excel لوحده"، أو ارفع الملف بصيغة xlsx.');
        }
        $this->store->backupFile($path);
        $reader = IOFactory::createReaderForFile($path);
        $book = $reader->load($path);
        $titles = $this->addSheets($book, $count);
        $tmp = dirname($path).'/.count-'.bin2hex(random_bytes(4)).'.'.$ext;
        IOFactory::createWriter($book, $ext === 'xls' ? 'Xls' : 'Xlsx')->save($tmp);
        $book->disconnectWorksheets();
        rename($tmp, $path);
        $this->store->reloadFile($fileId, $path, 'count');

        $count['appended'][] = ['file' => basename($path), 'sheets' => $titles, 'at' => now()->toIso8601String()];
        file_put_contents($this->dir.'/'.$count['id'].'.json', json_encode($count, JSON_UNESCAPED_UNICODE));

        return ['file' => basename($path), 'file_id' => $fileId, 'sheets' => $titles];
    }
}
