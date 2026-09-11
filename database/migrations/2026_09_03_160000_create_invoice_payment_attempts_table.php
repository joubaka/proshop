<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('transaction_id')->index();
            // NULL on completion; at most one unresolved attempt across all gateways.
            $table->unsignedInteger('active_transaction_id')->nullable()->unique();
            $table->string('gateway', 20);
            $table->decimal('amount', 22, 4);
            $table->string('status', 30)->default('processing')->index();
            $table->string('provider_payment_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'gateway', 'provider_payment_id'], 'invoice_attempt_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_attempts');
    }
};
