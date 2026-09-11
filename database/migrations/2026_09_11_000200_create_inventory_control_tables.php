<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_policies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->index();
            $table->unsignedInteger('variation_id')->index();
            $table->unsignedInteger('supplier_id')->nullable()->index();
            $table->unsignedSmallInteger('lead_time_days')->default(7);
            $table->unsignedSmallInteger('safety_stock_days')->default(7);
            $table->decimal('minimum_order_quantity', 22, 4)->default(1);
            $table->decimal('order_multiple', 22, 4)->default(1);
            $table->timestamps();
            $table->unique(['business_id', 'location_id', 'variation_id'], 'inventory_policy_unique');
        });

        Schema::create('replenishment_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->index();
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('confirmed_by')->nullable();
            $table->string('status', 20)->default('preview')->index();
            $table->unsignedSmallInteger('sales_window_days')->default(30);
            $table->string('proposal_fingerprint', 64);
            $table->text('purchase_order_ids')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('replenishment_batch_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('replenishment_batch_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('supplier_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_cost', 22, 4);
            $table->decimal('stock_snapshot', 22, 4);
            $table->decimal('open_po_snapshot', 22, 4);
            $table->decimal('daily_sales_snapshot', 22, 6);
            $table->timestamps();
            $table->foreign('replenishment_batch_id')->references('id')->on('replenishment_batches')->cascadeOnDelete();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->index();
            $table->unsignedInteger('created_by')->index();
            $table->unsignedInteger('posted_by')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('stock_count_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('system_quantity', 22, 4);
            $table->decimal('counted_quantity', 22, 4)->nullable();
            $table->unsignedInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();
            $table->foreign('stock_count_id')->references('id')->on('stock_counts')->cascadeOnDelete();
            $table->unique(['stock_count_id', 'variation_id']);
        });

        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('variation_id')->index();
            $table->unsignedInteger('changed_by')->nullable();
            $table->string('source', 40);
            $table->string('source_reference')->nullable();
            $table->decimal('old_cost', 22, 4)->nullable();
            $table->decimal('new_cost', 22, 4)->nullable();
            $table->decimal('old_sell_price', 22, 4)->nullable();
            $table->decimal('new_sell_price', 22, 4)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_changes');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('replenishment_batch_lines');
        Schema::dropIfExists('replenishment_batches');
        Schema::dropIfExists('inventory_policies');
    }
};
