<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Parses an Excel inventory file (.xls or .xlsx) into a barcode index.
 * Read-only: the file is never written.
 */
class InventoryParser
{
    public function __construct(private array $cfg)
    {
    }

    public static function normalizeKey(string $barcode, bool $caseInsensitive = true): string
    {
        $k = trim($barcode);

        return $caseInsensitive ? mb_strtoupper($k) : $k;
    }

    public static function validBarcode(string $s): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z\-_.\/ ]{1,64}$/', trim($s)) && trim($s) !== '';
    }

    /** Convert a cell to a display string, keeping stored values (and leading zeros of text cells). */
    public static function cellText(?Cell $cell): string
    {
        if ($cell === null) {
            return '';
        }
        $v = $cell->getValue();
        if (is_string($v) && str_starts_with($v, '=')) {
            $v = $cell->getOldCalculatedValue();       // cached value saved by Excel
            if ($v === null) {
                try {
                    $v = $cell->getCalculatedValue();
                } catch (Throwable) {
                    $v = '';
                }
            }
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (floor($v) == $v && abs($v) < 1e15) {
                return number_format($v, 0, '.', '');
            }

            return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        }

        return trim((string) $v);
    }

    public function parseFile(string $path): array
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new InventoryReadException('ملف Excel غير موجود أو فارغ');
        }
        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
                // CSV: guess delimiter; encoding UTF-8 (with/without BOM) or Arabic Windows-1256
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv;
                $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
                $reader->setFallbackEncoding('CP1256');
            } else {
                $reader = IOFactory::createReaderForFile($path);
            }
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new InventoryReadException('تعذر قراءة ملف Excel: '.class_basename($e).': '.$e->getMessage());
        }

        $names = $book->getSheetNames();
        $wanted = $this->cfg['sheets'] ?? [];
        if ($wanted) {
            $missing = array_diff($wanted, $names);
            if ($missing) {
                throw new InventoryReadException('أوراق العمل غير موجودة: '.implode(', ', $missing));
            }
            $target = $wanted;
        } else {
            $target = [$names[0]];
        }

        $records = [];
        $fingerprint = [];
        $warnings = [];
        $total = 0;
        $hdr = max((int) ($this->cfg['header_row'] ?? 1), 1);
        $labels = $this->cfg['fields'] ?? [];
        $ci = (bool) ($this->cfg['case_insensitive_barcodes'] ?? true);

        foreach ($target as $sname) {
            /** @var Worksheet $sh */
            $sh = $book->getSheetByName($sname);
            $maxRow = $sh->getHighestDataRow();
            $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sh->getHighestDataColumn());
            $headers = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $headers[$c] = self::cellText($sh->getCell([$c, $hdr]));
            }
            [$mapped, $extras] = $this->mapHeaders($headers);
            if (! isset($mapped['barcode'])) {
                throw new InventoryReadException("عمود الباركود غير موجود في ورقة '{$sname}'. راجع إعداد columns.barcode");
            }
            if ($maxRow <= $hdr) {
                $warnings[] = "ورقة العمل '{$sname}' لا تحتوي على بيانات";
                continue;
            }
            for ($r = $hdr + 1; $r <= $maxRow; $r++) {
                $barcode = self::cellText($sh->getCell([$mapped['barcode'], $r]));
                if ($barcode === '') {
                    continue;
                }
                $fields = [];
                foreach ($mapped as $key => $col) {
                    $fields[$key] = self::cellText($sh->getCell([$col, $r]));
                }
                $fields['barcode'] = $barcode;
                $extra = [];
                if ($this->cfg['include_unmapped_columns'] ?? true) {
                    foreach ($extras as $col => $h) {
                        $v = self::cellText($sh->getCell([$col, $r]));
                        if ($v !== '') {
                            $extra[] = ['label' => $h, 'value' => $v];
                        }
                    }
                }
                $rec = [
                    'fields' => $fields,
                    'labels' => array_map(fn ($k) => $labels[$k] ?? $k, array_combine(array_keys($fields), array_keys($fields))),
                    'extra' => $extra,
                    'location' => ['sheet' => $sname, 'row' => $r],
                ];
                $key = self::normalizeKey($barcode, $ci);
                $records[$key][] = $rec;
                $fingerprint[$key] = ($fingerprint[$key] ?? '').md5(json_encode([$fields, $extra]));
                $total++;
            }
        }
        $book->disconnectWorksheets();
        if ($total === 0) {
            // Protects against wiping the inventory with an empty/wrong file.
            throw new InventoryReadException('الملف لا يحتوي على أي قطعة بباركود');
        }

        $duplicates = [];
        foreach ($records as $k => $list) {
            if (count($list) > 1) {
                $duplicates[$k] = array_map(fn ($x) => $x['location'], $list);
            }
        }
        if ($duplicates) {
            $warnings[] = 'يوجد '.count($duplicates).' باركود مكرر في الملف';
        }

        return [
            'records' => $records,
            'duplicates' => $duplicates,
            'total_rows' => $total,
            'sheets' => array_values($target),
            'warnings' => $warnings,
            'fingerprint' => $fingerprint,
        ];
    }

    private function mapHeaders(array $headers): array
    {
        $norm = [];
        foreach ($headers as $col => $h) {
            if ($h !== '') {
                $norm[mb_strtolower(trim($h))] ??= $col;
            }
        }
        $mapped = [];
        foreach ($this->cfg['columns'] ?? [] as $key => $aliases) {
            foreach ((array) $aliases as $alias) {
                $col = $norm[mb_strtolower(trim($alias))] ?? null;
                if ($col !== null) {
                    $mapped[$key] = $col;
                    break;
                }
            }
        }
        $used = array_flip($mapped);
        $extras = [];
        foreach ($headers as $col => $h) {
            if ($h !== '' && ! isset($used[$col])) {
                $extras[$col] = trim($h);
            }
        }

        return [$mapped, $extras];
    }

    public static function diff(?array $old, array $new): array
    {
        if ($old === null) {
            return ['added' => count($new['records']), 'modified' => 0, 'deleted' => 0];
        }
        $o = $old['fingerprint'] ?? [];
        $n = $new['fingerprint'];

        return [
            'added' => count(array_diff_key($n, $o)),
            'deleted' => count(array_diff_key($o, $n)),
            'modified' => count(array_filter(array_keys(array_intersect_key($n, $o)), fn ($k) => $o[$k] !== $n[$k])),
        ];
    }
}
