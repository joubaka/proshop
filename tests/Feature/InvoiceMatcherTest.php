<?php

namespace Tests\Feature;

use App\InvoiceScan;
use App\InvoiceScanning\InvoiceMatcher;
use App\SupplierProductMapping;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('type');
            $table->string('name')->nullable(); $table->string('supplier_business_name')->nullable();
            $table->string('tax_number')->nullable(); $table->softDeletes(); $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('name');
            $table->boolean('is_inactive')->default(false); $table->timestamps();
        });
        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('product_id'); $table->string('name');
            $table->string('sub_sku')->nullable(); $table->decimal('default_purchase_price', 22, 4)->nullable();
            $table->decimal('sell_price_inc_tax', 22, 4)->nullable(); $table->decimal('profit_percent', 22, 4)->default(0);
            $table->softDeletes(); $table->timestamps();
        });
        (require database_path('migrations/2026_09_07_000100_create_invoice_scanning_tables.php'))->up();
    }

    public function test_it_uses_supplier_tax_number_and_learned_pack_mapping(): void
    {
        DB::table('contacts')->insert([
            'id' => 10, 'business_id' => 1, 'type' => 'supplier', 'name' => 'Ball Supply',
            'supplier_business_name' => 'Ball Supply Pty Ltd', 'tax_number' => 'VAT 412-345',
        ]);
        DB::table('products')->insert(['id' => 20, 'business_id' => 1, 'name' => 'Tennis balls']);
        DB::table('variations')->insert([
            'id' => 30, 'product_id' => 20, 'name' => 'Standard', 'sub_sku' => 'TB-001',
            'default_purchase_price' => 18, 'sell_price_inc_tax' => 30, 'profit_percent' => 20,
        ]);
        SupplierProductMapping::create([
            'business_id' => 1, 'supplier_id' => 10, 'variation_id' => 30,
            'supplier_item_code' => 'CASE24', 'description_fingerprint' => InvoiceMatcher::fingerprint('Case of tennis balls'),
            'pack_size' => 24,
        ]);
        $scan = InvoiceScan::create([
            'uuid' => 'matcher', 'business_id' => 1, 'created_by' => 1, 'status' => 'processing',
            'document_hash' => hash('sha256', 'matcher'),
        ]);

        app(InvoiceMatcher::class)->match($scan, [
            'supplier_tax_number' => '412345', 'supplier_name' => 'Ball Supply',
            'lines' => [[
                'supplier_item_code' => 'CASE24', 'description' => 'Case of tennis balls',
                'quantity' => 2, 'unit' => 'case', 'unit_price' => 480,
                'price_includes_tax' => false, 'tax_rate' => 15, 'line_total' => 960, 'confidence' => .98,
            ]],
        ]);

        $line = $scan->fresh()->lines()->firstOrFail();
        $this->assertSame(10, $scan->fresh()->supplier_id);
        $this->assertSame(30, $line->variation_id);
        $this->assertSame('supplier_mapping', $line->match_method);
        $this->assertEquals(24, $line->pack_size);
        $this->assertEqualsWithDelta(27.60, $line->proposed_sell_price, .001);
    }
}
