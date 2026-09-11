<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_scans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->unsignedInteger('supplier_id')->nullable()->index();
            $table->unsignedInteger('created_by')->index();
            $table->unsignedInteger('reviewed_by')->nullable()->index();
            $table->unsignedInteger('purchase_transaction_id')->nullable()->index();
            $table->string('status', 32)->default('uploaded')->index();
            $table->string('provider', 32)->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('document_hash', 64)->index();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_tax_number')->nullable();
            $table->string('invoice_number', 120)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('subtotal', 22, 4)->nullable();
            $table->decimal('discount_total', 22, 4)->default(0);
            $table->decimal('tax_total', 22, 4)->nullable();
            $table->decimal('freight_total', 22, 4)->default(0);
            $table->decimal('invoice_total', 22, 4)->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->json('extracted_payload')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'document_hash'], 'invoice_scan_document_unique');
            $table->unique(['business_id', 'supplier_id', 'invoice_number'], 'invoice_scan_supplier_invoice_unique');
        });

        Schema::create('invoice_scan_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_scan_id');
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->unsignedSmallInteger('page_order')->default(1);
            $table->timestamps();
            $table->foreign('invoice_scan_id')->references('id')->on('invoice_scans')->cascadeOnDelete();
        });

        Schema::create('invoice_scan_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_scan_id');
            $table->unsignedInteger('variation_id')->nullable()->index();
            $table->string('supplier_item_code')->nullable();
            $table->text('description');
            $table->decimal('quantity', 22, 4)->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('pack_size', 22, 4)->default(1);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->boolean('price_includes_tax')->default(false);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('line_total', 22, 4)->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->string('match_method', 32)->nullable();
            $table->decimal('match_confidence', 6, 5)->nullable();
            $table->decimal('proposed_sell_price', 22, 4)->nullable();
            $table->boolean('price_change_approved')->default(false);
            $table->unsignedSmallInteger('line_order')->default(1);
            $table->timestamps();
            $table->foreign('invoice_scan_id')->references('id')->on('invoice_scans')->cascadeOnDelete();
        });

        Schema::create('supplier_product_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('supplier_id')->index();
            $table->unsignedInteger('variation_id')->index();
            $table->string('supplier_item_code')->nullable();
            $table->string('description_fingerprint', 64);
            $table->text('supplier_description')->nullable();
            $table->decimal('pack_size', 22, 4)->default(1);
            $table->unsignedInteger('confirmed_by')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'supplier_id', 'description_fingerprint'], 'supplier_mapping_description_unique');
            $table->index(['business_id', 'supplier_id', 'supplier_item_code'], 'supplier_mapping_code_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_mappings');
        Schema::dropIfExists('invoice_scan_lines');
        Schema::dropIfExists('invoice_scan_documents');
        Schema::dropIfExists('invoice_scans');
    }
};
