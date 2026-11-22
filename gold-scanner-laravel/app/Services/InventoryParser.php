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

    /** Remove spaces and invisible characters (NBSP, zero-width, BOM, RTL/LTR marks) from a barcode. */
    public static function cleanBarcode(string $s): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{0640}]+/u', '', $s);
    }

    public static function normalizeKey(string $barcode, bool $caseInsensitive = true): string
    {
        $k = self::cleanBarcode($barcode);

        return $caseInsensitive ? mb_strtoupper($k) : $k;
    }

    public static function validBarcode(string $s): bool
    {
        $s = self::cleanBarcode($s);

        return $s !== '' && (bool) preg_match('/^[0-9A-Za-z\-_.\/]{1,64}$/', $s);
    }

    /** Barcode as Excel displays it: numeric cells with a format like 000000 keep their leading zeros. */
    public static function barcodeText(?Cell $cell): string
    {
        if ($cell === null) {
            return '';
        }
        $v = $cell->getValue();
        if ((is_int($v) || is_float($v)) && floor((float) $v) == $v) {
            $fmt = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if (preg_match('/^0+$/', (string) $fmt)) {            // e.g. "000000"
                return str_pad((string) (int) $v, strlen($fmt), '0', STR_PAD_LEFT);
            }
        }

        return self::cellText($cell);
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
            $reader->setReadDataOnly(false);   // keep number formats (barcode 1090 shown as 001090)
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
            $target = $names;                 // all sheets; ones without a barcode column are skipped
        }

        $records = [];
        $fingerprint = [];
        $warnings = [];
        $skipped = [];
        $sheetsUsed = [];
        $sheetInfo = [];
        $total = 0;
        $hdr = max((int) ($this->cfg['header_row'] ?? 1), 1);
        $labels = $this->cfg['fields'] ?? [];
        $ci = (bool) ($this->cfg['case_insensitive_barcodes'] ?? true);

        foreach ($target as $sname) {
            /** @var Worksheet $sh */
            $sh = $book->getSheetByName($sname);
            $maxRow = $sh->getHighestDataRow();
            $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sh->getHighestDataColumn());
            // header row: the configured one, else the first of rows 1..15 that has a barcode column
            $found = null;
            foreach (array_unique(array_merge([$hdr], range(1, min(15, max(1, $maxRow))))) as $tryRow) {
                $headers = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $headers[$c] = self::cellText($sh->getCell([$c, $tryRow]));
                }
                [$mapped, $extras] = $this->mapHeaders($headers);
                if (isset($mapped['barcode'])) {
                    $found = $tryRow;
                    break;
                }
            }
            if ($found === null) {
                if ($maxRow > 1) {
                    $skipped[] = $sname;
                }
                continue;
            }
            $sheetsUsed[] = $sname;
            $sheetInfo[$sname] = ['header_row' => $found, 'columns' => array_map(fn ($c) => $headers[$c], $mapped)];
            for ($r = $found + 1; $r <= $maxRow; $r++) {
                $barcode = self::cleanBarcode(self::barcodeText($sh->getCell([$mapped['barcode'], $r])));
                if ($barcode === '') {
                    continue;
                }
                $fields = [];
                foreach ($mapped as $key => $col) {
                    $fields[$key] = self::cellText($sh->getCell([$col, $r]));
                }
                $fields['barcode'] = $barcode;
                if (isset($fields['karat']) && ($k = self::karatFromFineness($fields['karat'])) !== null) {
                    $fields['karat'] = $k.' ('.$fields['karat'].')';   // 875 -> "21 (875)"
                }
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
        if (! $sheetsUsed) {
            throw new InventoryReadException('لم يتم العثور على عمود الباركود في أي ورقة عمل. تأكد أن الملف فيه عمود اسمه "الباركود" أو "Barcode"');
        }
        if ($skipped && count($target) > 1) {
            $warnings[] = 'أوراق عمل بدون عمود باركود تم تجاهلها: '.implode('، ', $skipped);
        }
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
            'sheets' => $sheetsUsed,
            'sheet_info' => $sheetInfo,
            'warnings' => $warnings,
            'fingerprint' => $fingerprint,
        ];
    }

    /** Normalize a header for matching: case, spaces, tatweel, diacritics, alef/ya/ta-marbuta forms, punctuation. */
    public static function normHeader(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $h);      // diacritics + tatweel
        $h = strtr($h, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $h = preg_replace('/[\s\p{P}\p{S}]+/u', '', $h);                            // spaces & punctuation

        return $h;
    }

    /** Gold fineness (parts per thousand) -> karat, e.g. 875 -> 21. */
    public static function karatFromFineness(string $v): ?string
    {
        $map = ['999' => '24', '995' => '24', '916' => '22', '917' => '22', '875' => '21', '750' => '18', '585' => '14', '583' => '14', '417' => '10', '375' => '9'];

        return $map[trim($v)] ?? null;
    }

    private function mapHeaders(array $headers): array
    {
        $norm = [];
        foreach ($headers as $col => $h) {
            if ($h !== '') {
                $norm[$col] = self::normHeader($h);
            }
        }
        $mapped = [];
        $taken = [];
        $columns = $this->cfg['columns'] ?? [];
        // pass 1: exact (normalized) match, pass 2: header contains the alias (e.g. "الوزن جرام")
        foreach ([false, true] as $contains) {
            foreach ($columns as $key => $aliases) {
                if (isset($mapped[$key])) {
                    continue;
                }
                foreach ((array) $aliases as $alias) {
                    $a = self::normHeader($alias);
                    if ($a === '' || ($contains && mb_strlen($a) < 3)) {
                        continue;
                    }
                    foreach ($norm as $col => $h) {
                        if (isset($taken[$col])) {
                            continue;
                        }
                        if ($h === $a || ($contains && str_contains($h, $a))) {
                            $mapped[$key] = $col;
                            $taken[$col] = true;
                            continue 3;
                        }
                    }
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
