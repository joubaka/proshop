<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->restrictOnDelete();
            $table->string('gateway', 30)->default('payfast');
            $table->unsignedInteger('attempt')->default(1);
            $table->string('merchant_payment_id', 64)->unique();
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->unsignedBigInteger('expected_amount_cents');
            $table->unsignedBigInteger('reported_amount_cents')->nullable();
            $table->string('status', 30)->default('pending');
            $table->boolean('signature_verified')->default(false);
            $table->boolean('server_verified')->default(false);
            $table->char('notification_hash', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_order_id', 'attempt']);
            $table->index(['shop_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_payments');
    }
};
