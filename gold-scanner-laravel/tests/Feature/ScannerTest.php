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
