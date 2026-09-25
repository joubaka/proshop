<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('mobile', 40)->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('shop_customer_contact_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_customer_id')->constrained('shop_customers')->cascadeOnDelete();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('contact_id')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('verification_method', 30)->default('staff_review');
            $table->unsignedInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_customer_id', 'business_id', 'contact_id'], 'shop_customer_contact_unique');
            $table->unique(['business_id', 'contact_id'], 'shop_contact_owner_unique');
        });

        Schema::table('shop_orders', function (Blueprint $table) {
            $table->foreignId('shop_customer_id')->nullable()->after('shop_cart_id')
                ->constrained('shop_customers')->nullOnDelete();
            $table->index(['shop_customer_id', 'created_at']);
        });

        Schema::create('shop_account_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('shop_customer_id')->constrained('shop_customers')->restrictOnDelete();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('contact_id')->index();
            $table->unsignedInteger('transaction_id')->index();
            $table->unsignedInteger('active_transaction_id')->nullable()->unique();
            $table->string('merchant_payment_id', 64)->unique();
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->unsignedBigInteger('expected_amount_cents');
            $table->unsignedBigInteger('reported_amount_cents')->nullable();
            $table->string('status', 30)->default('provider_pending')->index();
            $table->char('notification_hash', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_account_payment_attempts');
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropForeign(['shop_customer_id']);
            $table->dropIndex(['shop_customer_id', 'created_at']);
            $table->dropColumn('shop_customer_id');
        });
        Schema::dropIfExists('shop_customer_contact_links');
        Schema::dropIfExists('shop_customer_password_reset_tokens');
        Schema::dropIfExists('shop_customers');
    }
};
