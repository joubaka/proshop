<?php

namespace Tests\Feature;

use App\InvoiceScan;
use App\InvoiceScanDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RegressionTestCase;

class InvoiceScanAccessTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        (require database_path('migrations/2026_09_07_000100_create_invoice_scanning_tables.php'))->up();
        Storage::fake('invoice_scans');
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class,
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\CheckUserLogin::class,
        ]);
    }

    public function test_purchase_permission_is_required_to_download_a_scan(): void
    {
        $this->signInWithPermissions();
        [$scan, $document] = $this->makeScan(1);

        $this->get(route('invoice-scans.document', [$scan->uuid, $document->id]))->assertForbidden();
    }

    public function test_scan_document_is_private_to_its_business(): void
    {
        $this->signInWithPermissions(['purchase.create'], 1);
        [$scan, $document] = $this->makeScan(2);

        $this->get(route('invoice-scans.document', [$scan->uuid, $document->id]))->assertNotFound();
    }

    public function test_authorised_business_user_can_download_private_scan(): void
    {
        $this->signInWithPermissions(['purchase.create'], 1);
        [$scan, $document] = $this->makeScan(1);

        $this->get(route('invoice-scans.document', [$scan->uuid, $document->id]))
            ->assertOk()->assertHeader('cache-control', 'no-store, private');
    }

    private function makeScan(int $businessId): array
    {
        Storage::disk('invoice_scans')->put('invoice.pdf', 'private-invoice');
        $scan = InvoiceScan::create([
            'uuid' => 'scan-'.$businessId, 'business_id' => $businessId, 'created_by' => 1,
            'status' => 'uploaded', 'document_hash' => hash('sha256', 'private-invoice'),
        ]);
        $document = InvoiceScanDocument::create([
            'invoice_scan_id' => $scan->id, 'disk' => 'invoice_scans', 'path' => 'invoice.pdf',
            'original_name' => 'supplier-invoice.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 15, 'sha256' => hash('sha256', 'private-invoice'), 'page_order' => 1,
        ]);
        return [$scan, $document];
    }
}
