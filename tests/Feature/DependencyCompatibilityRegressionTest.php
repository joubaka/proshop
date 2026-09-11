<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\RegressionTestCase;

class DependencyCompatibilityRegressionTest extends RegressionTestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = storage_path('framework/testing/dependency-'.bin2hex(random_bytes(12)));
        File::makeDirectory($this->fixtureDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        // Only the unique directory created by this test, never application uploads/cache.
        if (isset($this->fixtureDirectory)) {
            File::deleteDirectory($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    public function test_excel_adapter_reads_csv_import_rows(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "SKU,Quantity,Price\nRACKET-1,2,150.25\n");
        $sheets = Excel::toArray([], $file);
        $this->assertSame('SKU', $sheets[0][0][0]);
        $this->assertSame('RACKET-1', $sheets[0][1][0]);
        $this->assertEquals(2, $sheets[0][1][1]);
        $this->assertEqualsWithDelta(150.25, $sheets[0][1][2], 0.00001);
    }

    public function test_spreadsheet_round_trip_preserves_numbers_and_formulas(): void
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Quantity', 'Price', 'Total'], [2, 150.25, '=A2*B2']]);
        $path = $this->fixtureDirectory.'/roundtrip.xlsx';
        (new Xlsx($sheet))->save($path);
        $loaded = IOFactory::load($path);
        $this->assertEqualsWithDelta(300.5, $loaded->getActiveSheet()->getCell('C2')->getCalculatedValue(), 0.00001);
        $this->assertSame('=A2*B2', $loaded->getActiveSheet()->getCell('C2')->getValue());
        $loaded->disconnectWorksheets();
        $sheet->disconnectWorksheets();
    }

    public function test_dompdf_adapter_renders_without_remote_or_embedded_php_execution(): void
    {
        $pdf = app('dompdf.wrapper');
        $pdf->setOptions(['tempDir' => $this->fixtureDirectory]);
        $options = $pdf->getDomPDF()->getOptions();
        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $bytes = $pdf->loadHTML('<h1>Invoice test</h1><p>Total: R300.50</p>')->output();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(500, strlen($bytes));
    }

    public function test_mpdf_and_fpdi_render_and_import_a_page(): void
    {
        $pdf = new \Mpdf\Mpdf(['tempDir' => $this->fixtureDirectory]);
        $pdf->WriteHTML('<h1>Invoice</h1><p>R300.50</p>');
        $bytes = $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $source = $this->fixtureDirectory.'/invoice.pdf';
        File::put($source, $bytes);
        $imported = new \Mpdf\Mpdf(['tempDir' => $this->fixtureDirectory]);
        $this->assertSame(1, $imported->setSourceFile($source));
        $imported->useTemplate($imported->importPage(1));
        $this->assertStringStartsWith('%PDF-', $imported->Output('', \Mpdf\Output\Destination::STRING_RETURN));
    }

    public function test_composer_security_blocking_is_enabled_without_ignored_advisories(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($composer['config']['audit']['block-insecure']);
        $this->assertSame([], $composer['config']['audit']['ignore']);
    }

    public function test_legacy_form_facade_preserves_csrf_method_and_escaping(): void
    {
        session()->start();
        $form = (string) \Form::open(['url' => '/backup/delete/example.zip', 'method' => 'DELETE']);
        $this->assertStringContainsString('name="_method"', $form);
        $this->assertStringContainsString('value="DELETE"', $form);
        $this->assertStringContainsString('name="_token"', $form);
        $this->assertStringContainsString(e(session()->token()), $form);
        $this->assertStringNotContainsString('<script>', (string) \Form::text('name', '<script>alert(1)</script>'));
        $select = (string) \Form::select('product', [1 => 'Racket'], 1);
        $this->assertStringContainsString('selected', $select);
        $this->assertSame('</form>', (string) \Form::close());
    }

    public function test_datatables_adapter_preserves_json_and_escapes_untrusted_cells(): void
    {
        $response = \Yajra\DataTables\Facades\DataTables::of(collect([
            ['id' => 1, 'name' => '<script>alert(1)</script>'],
        ]))->make(true);
        $data = $response->getData(true);
        $this->assertSame(1, $data['recordsTotal']);
        $this->assertStringNotContainsString('<script>', $data['data'][0]['name']);
        $this->assertStringContainsString('&lt;script&gt;', $data['data'][0]['name']);
    }

    public function test_barcode_adapter_generates_printable_svg(): void
    {
        $svg = \Milon\Barcode\Facades\DNS1DFacade::getBarcodeSVG('RACKET123', 'C128');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_business_date_formatting_keeps_local_midnight_and_time(): void
    {
        date_default_timezone_set('Africa/Johannesburg');
        session()->put('business.date_format', 'd/m/Y');
        session()->put('business.time_format', 24);
        $date = '2026-09-03 00:30:00';
        $util = new \App\Utils\Util;
        $this->assertSame('03/09/2026 00:30', $util->format_date($date, true));
        $this->assertSame('03/09/2026', $util->format_date($date));
        $directives = app('blade.compiler')->getCustomDirectives();
        foreach (['format_date' => '03/09/2026', 'format_time' => '00:30', 'format_datetime' => '03/09/2026 00:30'] as $name => $expected) {
            // These legacy directives return a PHP expression, used inside Blade echo statements.
            $expression = $directives[$name](var_export($date, true));
            $this->assertSame($expected, eval('return '.$expression.';'));
        }
    }
}
