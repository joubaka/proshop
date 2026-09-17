<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_channel_id')->constrained('shop_channels')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['shop_channel_id', 'status', 'expires_at']);
        });

        Schema::create('shop_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_cart_id')->constrained('shop_carts')->cascadeOnDelete();
            $table->foreignId('shop_variation_id')->constrained('shop_variations')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['shop_cart_id', 'shop_variation_id']);
        });

        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number', 40)->unique();
            $table->foreignId('shop_channel_id')->constrained('shop_channels')->restrictOnDelete();
            $table->foreignId('shop_cart_id')->nullable()->constrained('shop_carts')->nullOnDelete();
            $table->unsignedInteger('contact_id')->nullable()->index();
            $table->unsignedInteger('transaction_id')->nullable()->unique();
            $table->char('currency', 3);
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_mobile', 40);
            $table->json('billing_address');
            $table->json('delivery_address')->nullable();
            $table->string('fulfilment_method', 40);
            $table->string('fulfilment_label');
            $table->json('delivery_quote')->nullable();
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('delivery_cents');
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->string('payment_status', 30)->default('pending');
            $table->string('order_status', 30)->default('awaiting_payment');
            $table->string('fulfilment_status', 30)->default('not_ready');
            $table->timestamp('reservation_expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['shop_channel_id', 'payment_status', 'created_at']);
            $table->index(['shop_channel_id', 'fulfilment_status', 'created_at']);
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->string('product_name');
            $table->string('variation_name')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_inc_tax_cents');
            $table->unsignedBigInteger('unit_tax_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
            $table->index(['product_id', 'variation_id']);
        });

        Schema::create('shop_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_order_id', 'variation_id']);
            $table->index(['location_id', 'variation_id', 'status', 'expires_at'], 'shop_reservations_availability_idx');
        });

        Schema::create('shop_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->string('actor_type', 30)->default('system');
            $table->unsignedInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['shop_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_events');
        Schema::dropIfExists('shop_stock_reservations');
        Schema::dropIfExists('shop_order_items');
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('shop_cart_items');
        Schema::dropIfExists('shop_carts');
    }
};
