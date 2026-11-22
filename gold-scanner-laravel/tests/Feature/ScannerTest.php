<?php

namespace Tests\Feature;

use App\Services\InventoryParser;
use App\Services\InventoryStore;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ScannerTest extends TestCase
{
    private string $dir;

    private array $h = ['X-Access-Key' => 'k'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/gs-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config([
            'goldscanner.access_key' => 'k',
            'goldscanner.admin_key' => 'adm',
            'goldscanner.demo_mode' => true,
            'goldscanner.upload_enabled' => true,
            'goldscanner.allowed_networks' => [],
        ]);
        mkdir($this->dir.'/files');
        copy(database_path('demo/inventory.xls'), $this->dir.'/files/inventory.xls');
        $this->app->singleton(InventoryStore::class, fn () => new InventoryStore(config('goldscanner'), $this->dir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Write an .xls with PhpSpreadsheet. Barcodes are written as TEXT cells unless int. */
    private function makeXls(string $path, array $rows, string $ext = 'xls'): void
    {
        $book = new Spreadsheet;
        $sh = $book->getActiveSheet();
        $sh->fromArray(['Barcode', 'Item Name', 'Karat', 'Weight', 'Price', 'رقم المورد'], null, 'A1');
        foreach ($rows as $i => $r) {
            foreach ($r as $c => $v) {
                $coord = [$c + 1, $i + 2];
                if ($c === 0 && is_string($v)) {
                    $sh->setCellValueExplicit($coord, $v, DataType::TYPE_STRING);
                } else {
                    $sh->setCellValue($coord, $v);
                }
            }
        }
        IOFactory::createWriter($book, ucfirst($ext))->save($path);
    }

    // ---------- parsing ----------
    public function test_parses_demo_file_with_leading_zeros_numeric_and_duplicates(): void
    {
        $p = new InventoryParser(config('goldscanner'));
        $s = $p->parseFile($this->dir.'/files/inventory.xls');
        $this->assertGreaterThanOrEqual(30, $s['total_rows']);
        $this->assertSame('00123456', $s['records']['00123456'][0]['fields']['barcode']);
        $this->assertArrayNotHasKey('123456', $s['records']);
        $this->assertSame('123456789', $s['records']['123456789'][0]['fields']['barcode']); // numeric cell
        $this->assertArrayHasKey('GR-2024-A7', $s['records']);
        $this->assertSame(['DUP-5555'], array_keys($s['duplicates']));
        $this->assertSame('4.35', $s['records']['00123456'][0]['fields']['weight']);
    }

    public function test_unmapped_columns_and_xlsx_support(): void
    {
        $f = $this->dir.'/x.xlsx';
        $this->makeXls($f, [['0007', 'خاتم', 21, 3.5, 1200, 'S-9']], 'xlsx');
        $s = (new InventoryParser(config('goldscanner')))->parseFile($f);
        $r = $s['records']['0007'][0];
        $this->assertSame('خاتم', $r['fields']['item_name']);
        $this->assertSame([['label' => 'رقم المورد', 'value' => 'S-9']], $r['extra']);
    }

    public function test_missing_barcode_column_rejected(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Foo');
        IOFactory::createWriter($book, 'Xls')->save($f = $this->dir.'/bad.xls');
        $this->expectException(\App\Services\InventoryReadException::class);
        (new InventoryParser(config('goldscanner')))->parseFile($f);
    }

    // ---------- API ----------
    public function test_requires_access_key(): void
    {
        $this->getJson('/api/item/00123456')->assertStatus(401);
        $this->getJson('/api/health')->assertStatus(401);
        $this->getJson('/api/config/status')->assertOk()->assertJson(['auth_required' => true]);
    }

    public function test_lookup_found_notfound_duplicate_invalid(): void
    {
        $this->getJson('/api/item/00123456', $this->h)->assertOk()->assertJsonPath('item.fields.barcode', '00123456');
        $this->getJson('/api/item/gr-2024-a7', $this->h)->assertOk();
        $this->getJson('/api/item/123456789', $this->h)->assertOk();
        $this->getJson('/api/item/999999', $this->h)->assertStatus(404)->assertJsonPath('detail', 'القطعة غير موجودة في ملف المخزون');
        $this->getJson('/api/item/DUP-5555', $this->h)->assertStatus(409)->assertJsonPath('count', 2);
        $this->getJson('/api/item/'.rawurlencode('<x>'), $this->h)->assertStatus(400);
    }

    public function test_health_and_demo_and_no_paths(): void
    {
        $h = $this->getJson('/api/health', $this->h)->assertOk()->json();
        $this->assertSame('ok', $h['excel']['state']);
        $this->assertSame(36, $h['excel']['records']);
        $this->assertStringNotContainsString($this->dir, json_encode($h));
        $d = $this->getJson('/api/demo/barcodes', $this->h)->assertOk()->json('barcodes');
        $this->assertContains('00123456', array_column($d, 'barcode'));
        config(['goldscanner.demo_mode' => false]);
        $this->getJson('/api/demo/barcodes', $this->h)->assertStatus(404);
    }

    public function test_503_when_never_loaded(): void
    {
        unlink($this->dir.'/files/inventory.xls');
        $this->getJson('/api/item/123', $this->h)->assertStatus(503);
        $this->assertSame('loading', $this->getJson('/api/health', $this->h)->json('excel.state'));
    }

    public function test_file_replaced_on_disk_is_picked_up_and_broken_file_keeps_snapshot(): void
    {
        $this->getJson('/api/item/00123456', $this->h)->assertOk();
        // replace via SFTP/FTP: new content
        $this->makeXls($this->dir.'/files/inventory.xls', [['NEW-1', 'جديد', 21, 1, 99]]);
        touch($this->dir.'/files/inventory.xls', time() + 5);
        $this->app->forgetInstance(InventoryStore::class);
        $this->getJson('/api/item/NEW-1', $this->h)->assertOk()->assertJsonPath('item.fields.price', '99');
        $this->getJson('/api/item/00123456', $this->h)->assertStatus(404);
        // broken file -> stale, previous snapshot still served
        file_put_contents($this->dir.'/files/inventory.xls', 'garbage');
        touch($this->dir.'/files/inventory.xls', time() + 10);
        $this->app->forgetInstance(InventoryStore::class);
        $r = $this->getJson('/api/item/NEW-1', $this->h)->assertOk();
        $this->assertSame('stale', $r->json('data_state'));
        $this->assertSame('stale', $this->getJson('/api/health', $this->h)->json('excel.state'));
    }

    // ---------- upload ----------
    public function test_upload_replaces_data_and_keeps_backup(): void
    {
        $this->getJson('/api/health', $this->h);      // initial load
        $this->makeXls($f = $this->dir.'/up.xls', [['UP-1', 'مرفوع', 21, 2, 500], ['0099', 'آخر', 18, 1, 100]]);
        $r = $this->call('POST', '/api/admin/upload', [], [], [], $this->server(['X-Admin-Key' => 'adm']), file_get_contents($f));
        $r->assertOk()->assertJsonPath('records', 2);
        $this->getJson('/api/item/UP-1', $this->h)->assertOk();
        $this->getJson('/api/item/0099', $this->h)->assertOk();
        $this->getJson('/api/item/00123456', $this->h)->assertStatus(404);
        $this->assertCount(1, glob($this->dir.'/backups/*.xls'));
    }

    public function test_multiple_files_filter_list_and_delete(): void
    {
        $this->makeXls($f = $this->dir.'/b.xlsx', [['BR-1', 'فرع ٢', 21, 2, 500], ['DUP-5555', 'نسخة فرع', 21, 3, 1]], 'xlsx');
        $r = $this->call('POST', '/api/admin/upload', [], [], [],
            $this->server(['X-Admin-Key' => 'adm', 'X-Filename' => rawurlencode('فرع المساعدية.xlsx')]), file_get_contents($f));
        $r->assertOk()->assertJsonPath('file.name', 'فرع المساعدية.xlsx')->assertJsonPath('file.replaced', false);

        $files = $this->getJson('/api/files', $this->h)->assertOk()->json('files');
        $this->assertCount(2, $files);
        $branch = collect($files)->firstWhere('name', 'فرع المساعدية.xlsx');
        $main = collect($files)->firstWhere('name', 'inventory.xls');

        // search across all files
        $this->getJson('/api/item/BR-1', $this->h)->assertOk()->assertJsonPath('item.location.file', 'فرع المساعدية.xlsx');
        $this->getJson('/api/item/00123456', $this->h)->assertOk();
        // DUP-5555 is duplicated in both files (and inside the main file) -> 409 with file names
        $dup = $this->getJson('/api/item/DUP-5555', $this->h)->assertStatus(409);
        $this->assertSame(3, $dup->json('count'));
        $this->assertContains('فرع المساعدية.xlsx', array_column($dup->json('locations'), 'file'));
        // filter: only the branch file
        $this->getJson('/api/item/DUP-5555?file='.$branch['id'], $this->h)->assertOk();
        $this->getJson('/api/item/00123456?file='.$branch['id'], $this->h)->assertStatus(404);

        // list all items, search and filter
        $all = $this->getJson('/api/items', $this->h)->assertOk()->json();
        $this->assertSame(38, $all['total']);
        $this->assertSame(2, $this->getJson('/api/items?file='.$branch['id'], $this->h)->json('total'));
        $this->assertSame(1, $this->getJson('/api/items?q=br-1', $this->h)->json('total'));
        $page = $this->getJson('/api/items?per_page=10&page=2', $this->h)->json();
        $this->assertCount(10, $page['items']);
        $this->assertSame(4, $page['pages']);

        // health counts both files
        $this->assertSame(2, $this->getJson('/api/health', $this->h)->json('excel.files'));

        // delete (admin only)
        $this->deleteJson('/api/admin/files/'.$branch['id'], [], $this->h + ['X-Admin-Key' => 'x'])->assertStatus(401);
        $this->deleteJson('/api/admin/files/'.$branch['id'], [], $this->h + ['X-Admin-Key' => 'adm'])->assertOk();
        $this->getJson('/api/item/BR-1', $this->h)->assertStatus(404);
        $this->assertCount(1, $this->getJson('/api/files', $this->h)->json('files'));
        $this->assertNotEmpty(glob($this->dir.'/backups/*.xlsx'));   // deleted file kept as backup
        $this->assertSame($main['id'], $this->getJson('/api/files', $this->h)->json('files.0.id'));
    }

    public function test_csv_utf8_bom_and_windows_1256(): void
    {
        $p = new InventoryParser(config('goldscanner'));
        // UTF-8 with BOM, comma separated, leading zeros kept
        file_put_contents($f1 = $this->dir.'/a.csv', "\xEF\xBB\xBF"."Barcode,Item Name,Karat,Weight,Price\n00777,خاتم ذهب,21,3.5,1200\n6280009999,سلسلة,18,8,2650\n");
        $s = $p->parseFile($f1);
        $this->assertSame('خاتم ذهب', $s['records']['00777'][0]['fields']['item_name']);
        $this->assertSame('6280009999', $s['records']['6280009999'][0]['fields']['barcode']);
        // Arabic Excel "CSV" saved as Windows-1256 with ; separator and Arabic headers
        $csv = iconv('UTF-8', 'CP1256', "الباركود;اسم القطعة;العيار\n0042;حلق ذهب;21\n");
        file_put_contents($f2 = $this->dir.'/b.csv', $csv);
        $s2 = $p->parseFile($f2);
        $this->assertSame('حلق ذهب', $s2['records']['0042'][0]['fields']['item_name']);
    }

    public function test_empty_admin_key_allows_upload_with_access_key_only(): void
    {
        config(['goldscanner.admin_key' => '']);
        $this->getJson('/api/config/status')->assertJsonPath('admin_required', false);
        $csv = "Barcode,Item Name\nNOADMIN-1,x\n";
        $this->call('POST', '/api/admin/upload', [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_ACCESS_KEY' => 'k', 'HTTP_X_FILENAME' => 'n.csv'], $csv)->assertOk();
        // the access key is still required
        $this->call('POST', '/api/admin/upload', [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_FILENAME' => 'n.csv'], $csv)->assertStatus(401);
    }

    public function test_live_display_feed(): void
    {
        $this->app->singleton(\App\Services\LiveFeed::class, fn () => new \App\Services\LiveFeed($this->dir.'/live'));
        $this->getJson('/api/live?channel=counter1', $this->h)->assertOk()->assertJsonPath('last_id', 0);
        // scanner lookups published to channel counter1
        $this->getJson('/api/item/00123456?live=counter1&device='.rawurlencode('ماسح 1'), $this->h)->assertOk();
        $this->getJson('/api/item/999999?live=counter1', $this->h)->assertStatus(404);
        $this->getJson('/api/item/00123456', $this->h)->assertOk();                 // no live param -> not published
        $this->getJson('/api/item/'.rawurlencode('<x>').'?live=counter1', $this->h)->assertStatus(400); // invalid -> not published
        $feed = $this->getJson('/api/live?channel=counter1', $this->h)->assertOk()->json();
        $this->assertSame(2, $feed['last_id']);
        $this->assertSame('ماسح 1', $feed['events'][0]['device']);
        $this->assertSame('00123456', $feed['events'][0]['body']['item']['fields']['barcode']);
        $this->assertSame(404, $feed['events'][1]['status']);
        // only newer events, and channels are separate
        $this->assertCount(1, $this->getJson('/api/live?channel=counter1&after=1', $this->h)->json('events'));
        $this->assertSame(0, $this->getJson('/api/live?channel=other', $this->h)->json('last_id'));
        $this->getJson('/api/live?channel=counter1')->assertStatus(401);           // access key required
    }

    public function test_real_client_layout_is_understood(): void
    {
        $book = new Spreadsheet;
        // sheet 1: empty-ish notes sheet (no barcode column) -> skipped, not an error
        $book->getActiveSheet()->setTitle('ورقة1')->setCellValue('A1', 'ملاحظات عامة')->setCellValue('A2', 'نص');
        // sheet 2: title row, then header with "البا ركود" (space inside), fineness karat, blank rows
        $sh = $book->createSheet()->setTitle('تقرير جرد الملصقات');
        $sh->setCellValue('A1', 'تقرير جرد الملصقات - فرع عرعر');
        $sh->fromArray(['البا ركود', 'الصنف', 'البيان', 'العيار', 'الوزن', 'المقاس'], null, 'A3');
        $rows = [['000001', 'SH1M1211', 'اطقم مصنع محلي', 875, 14.9, ''], [464, 'HAM1211', 'خاتم ذهب مصنع محلي', 875, 0.95, '17'],
                 null, null, ['000460', 'HAM1181', 'خاتم ذهب مصنع محلي', 750, 1.03, '']];
        $r = 4;
        foreach ($rows as $row) {
            if ($row) {
                $sh->setCellValueExplicit([1, $r], (string) $row[0], is_int($row[0]) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                for ($c = 1; $c < 6; $c++) {
                    $sh->setCellValue([$c + 1, $r], $row[$c]);
                }
            }
            $r++;
        }
        IOFactory::createWriter($book, 'Xlsx')->save($f = $this->dir.'/client.xlsx');

        $s = (new InventoryParser(config('goldscanner')))->parseFile($f);
        $this->assertSame(['تقرير جرد الملصقات'], $s['sheets']);
        $this->assertSame(3, $s['total_rows']);
        $rec = $s['records']['000460'][0];
        $this->assertSame('خاتم ذهب مصنع محلي', $rec['fields']['item_name']);   // البيان -> item name
        $this->assertSame('18 (750)', $rec['fields']['karat']);                 // fineness -> karat
        $this->assertSame('1.03', $rec['fields']['weight']);
        $this->assertContains(['label' => 'الصنف', 'value' => 'HAM1181'], $rec['extra']);
        $this->assertSame(8, $rec['location']['row']);   // header found on row 3, two blank rows skipped

        // upload it, then scan the printed label "000464" (stored as the number 464)
        $this->call('POST', '/api/admin/upload', [], [], [],
            $this->server(['X-Admin-Key' => 'adm', 'X-Filename' => 'client.xlsx']), file_get_contents($f))->assertOk();
        $hit = $this->getJson('/api/item/000464', $this->h)->assertOk();
        $this->assertSame('464', $hit->json('matched_as'));
        $this->assertSame('21 (875)', $hit->json('item.fields.karat'));
        $this->getJson('/api/item/000001', $this->h)->assertOk();
    }

    public function test_large_client_file_hidden_chars_and_number_format(): void
    {
        $book = new Spreadsheet;
        $sh = $book->getActiveSheet()->setTitle('تقرير جرد الملصقات');
        $sh->fromArray(['البا ركود', 'الصنف', 'البيان', 'العيار', 'الوزن', 'المقاس'], null, 'A1');
        for ($i = 1; $i <= 1100; $i++) {
            $code = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if ($i % 7 === 0) {
                $code .= "\u{00A0}";             // trailing non-breaking space (common in exported sheets)
            }
            $sh->setCellValueExplicit([1, $i + 1], $code, DataType::TYPE_STRING);
            $sh->fromArray(['M1212', 'تعليقة صغيرة مصنع محلي', 875, 0.22], null, 'B'.($i + 1));
        }
        // a barcode stored as a NUMBER with display format 000000
        $sh->setCellValue('A1102', 5000.0)->getStyle('A1102')->getNumberFormat()->setFormatCode('000000');
        $sh->setCellValue('C1102', 'قطعة رقمية');
        IOFactory::createWriter($book, 'Xlsx')->save($f = $this->dir.'/big.xlsx');

        $this->call('POST', '/api/admin/upload', [], [], [],
            $this->server(['X-Admin-Key' => 'adm', 'X-Filename' => rawurlencode('نسخة من الخليج اخر نسخة1.xlsx')]), file_get_contents($f))
            ->assertOk();

        $this->getJson('/api/item/001090', $this->h)->assertOk()->assertJsonPath('item.fields.item_name', 'تعليقة صغيرة مصنع محلي');
        $this->getJson('/api/item/001092', $this->h)->assertOk();                  // 1092 % 7 == 0 -> had NBSP
        $this->getJson('/api/item/'.rawurlencode("001092\u{200F}"), $this->h)->assertOk(); // scanner sent an RTL mark
        $this->getJson('/api/item/005000', $this->h)->assertOk()->assertJsonPath('item.fields.barcode', '005000');
        $this->getJson('/api/item/000336', $this->h)->assertOk();
        $this->getJson('/api/item/777660', $this->h)->assertStatus(404);

        $this->artisan('gold:find', ['barcode' => '001090'])->expectsOutputToContain('FOUND (exact)')->assertExitCode(0);
    }

    public function test_header_normalization(): void
    {
        $n = fn ($s) => InventoryParser::normHeader($s);
        $this->assertSame($n('الباركود'), $n('البا ركود'));
        $this->assertSame($n('الباركود'), $n(' الـبـاركـود '));
        $this->assertSame($n('الأجرة'), $n('الاجره'));
        $this->assertSame($n('Bar Code'), $n('bar-code'));
    }

    public function test_export_scans_to_excel(): void
    {
        $this->app->singleton(\App\Services\LiveFeed::class, fn () => new \App\Services\LiveFeed($this->dir.'/live'));
        $this->getJson('/api/item/00123456?live=c1&device=d1', $this->h)->assertOk();
        $this->getJson('/api/item/6280001001?live=c1&device=d1', $this->h)->assertOk();
        $this->getJson('/api/item/999999?live=c1', $this->h)->assertStatus(404);

        $res = $this->postJson('/api/live/export', ['channel' => 'c1', 'ids' => [1, 2, 3]], $this->h);
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $res->headers->get('Content-Disposition'));
        file_put_contents($f = $this->dir.'/out.xlsx', $res->streamedContent());

        $sh = IOFactory::load($f)->getActiveSheet();
        $this->assertSame('الباركود', $sh->getCell('E1')->getValue());
        $this->assertSame('00123456', $sh->getCell('E2')->getValue());            // text, zeros kept
        $this->assertSame(DataType::TYPE_STRING, $sh->getCell('E2')->getDataType());
        $this->assertSame(4.35, $sh->getCell('I2')->getValue());                  // weight as a number
        $this->assertSame('غير موجودة', $sh->getCell('P4')->getValue());
        $this->assertSame('الإجمالي', $sh->getCell('A5')->getValue());
        $this->assertStringContainsString('2 من 3', (string) $sh->getCell('E5')->getValue());

        // only selected ids, and nothing -> 404
        file_put_contents($f2 = $this->dir.'/one.xlsx', $this->postJson('/api/live/export', ['channel' => 'c1', 'ids' => [2]], $this->h)->streamedContent());
        $this->assertSame('6280001001', IOFactory::load($f2)->getActiveSheet()->getCell('E2')->getValue());
        $this->postJson('/api/live/export', ['channel' => 'c1', 'ids' => []], $this->h)->assertStatus(404);
        $this->postJson('/api/live/export', ['channel' => 'c1', 'ids' => [1]])->assertStatus(401);
    }

    public function test_csv_upload(): void
    {
        $csv = "Barcode,Item Name,Price\nCSV-1,قطعة من CSV,99\n";
        $r = $this->call('POST', '/api/admin/upload', [], [], [],
            $this->server(['X-Admin-Key' => 'adm', 'X-Filename' => rawurlencode('فرع.csv')]), $csv);
        $r->assertOk()->assertJsonPath('file.name', 'فرع.csv');
        $this->getJson('/api/item/CSV-1', $this->h)->assertOk()->assertJsonPath('item.fields.price', '99');
        // a text file without .csv name is still rejected
        $this->call('POST', '/api/admin/upload', [], [], [], $this->server(['X-Admin-Key' => 'adm', 'X-Filename' => 'x.txt']), $csv)
            ->assertStatus(400);
    }

    public function test_upload_rejections_keep_current_file(): void
    {
        $before = file_get_contents($this->dir.'/files/inventory.xls');
        $post = fn ($body, $admin = 'adm') => $this->call('POST', '/api/admin/upload', [], [], [], $this->server(['X-Admin-Key' => $admin]), $body);
        $post($before, 'wrong')->assertStatus(401);
        $post('not excel')->assertStatus(400);
        $post(substr($before, 0, intdiv(strlen($before), 2)))->assertStatus(400);
        $this->assertSame($before, file_get_contents($this->dir.'/files/inventory.xls'));
        config(['goldscanner.upload_enabled' => false]);
        $post($before)->assertStatus(404);
    }

    public function test_frontend_served(): void
    {
        $this->get('/')->assertOk()->assertSee('dir="rtl"', false)->assertSee('/static/app.js', false);
    }

    private function server(array $headers): array
    {
        $s = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_ACCESS_KEY' => 'k'];
        foreach ($headers as $k => $v) {
            $s['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $s;
    }
}
