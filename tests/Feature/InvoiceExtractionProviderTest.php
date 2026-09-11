<?php

namespace Tests\Feature;

use App\InvoiceScan;
use App\InvoiceScanDocument;
use App\InvoiceScanning\Providers\OpenAiInvoiceExtractor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceExtractionProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('invoice_scans', function (Blueprint $table) {
            $table->increments('id'); $table->string('uuid'); $table->timestamps();
        });
        Schema::create('invoice_scan_documents', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('invoice_scan_id'); $table->string('disk');
            $table->string('path'); $table->string('original_name'); $table->string('mime_type');
            $table->unsignedInteger('size_bytes'); $table->string('sha256'); $table->unsignedInteger('page_order'); $table->timestamps();
        });
        Storage::fake('invoice_scans');
        config()->set('invoice_scanning.disk', 'invoice_scans');
        config()->set('invoice_scanning.openai.api_key', 'test-key');
        config()->set('invoice_scanning.openai.model', 'test-model');
    }

    public function test_it_sends_private_image_as_non_stored_structured_request(): void
    {
        $scan = InvoiceScan::create(['uuid' => 'test-scan']);
        Storage::disk('invoice_scans')->put('one.jpg', 'image-bytes');
        InvoiceScanDocument::create([
            'invoice_scan_id' => $scan->id, 'disk' => 'invoice_scans', 'path' => 'one.jpg',
            'original_name' => 'invoice.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 11,
            'sha256' => hash('sha256', 'image-bytes'), 'page_order' => 1,
        ]);
        $payload = ['supplier_name' => 'Supplier', 'lines' => []];
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_test',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($payload)]]]],
        ])]);

        $result = (new OpenAiInvoiceExtractor)->extract($scan);

        $this->assertSame('Supplier', $result['supplier_name']);
        $this->assertSame('resp_test', $result['_provider_reference']);
        Http::assertSent(function ($request) {
            return $request['store'] === false
                && $request['model'] === 'test-model'
                && $request['text']['format']['type'] === 'json_schema'
                && str_starts_with($request['input'][0]['content'][1]['image_url'], 'data:image/jpeg;base64,');
        });
    }
}
