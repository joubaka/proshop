<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('slug')->unique();
            $table->string('name');
            $table->char('currency', 3)->default('ZAR');
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->foreign('business_id')->references('id')->on('business')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('business_locations')->cascadeOnDelete();
        });

        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_channel_id')->constrained('shop_channels')->cascadeOnDelete();
            $table->unsignedInteger('product_id');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->text('web_description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamps();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['shop_channel_id', 'product_id']);
            $table->unique(['shop_channel_id', 'slug']);
            $table->index(['shop_channel_id', 'published_at', 'unpublished_at'], 'shop_products_publication_idx');
        });

        Schema::create('shop_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->unsignedInteger('variation_id');
            $table->string('display_name')->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('safety_stock', 22, 4)->default(0);
            $table->decimal('maximum_order_quantity', 22, 4)->nullable();
            $table->timestamps();
            $table->foreign('variation_id')->references('id')->on('variations')->cascadeOnDelete();
            $table->unique(['shop_product_id', 'variation_id']);
        });

        Schema::create('shop_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['shop_product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_images');
        Schema::dropIfExists('shop_variations');
        Schema::dropIfExists('shop_products');
        Schema::dropIfExists('shop_channels');
    }
};
