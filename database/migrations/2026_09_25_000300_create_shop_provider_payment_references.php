<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_provider_payment_references', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('provider_reference', 100);
            $table->string('owner_type', 30);
            $table->string('owner_id', 64);
            $table->timestamps();
            $table->unique(['gateway', 'provider_reference'], 'shop_provider_reference_unique');
            $table->unique(['owner_type', 'owner_id'], 'shop_provider_owner_unique');
        });

        if (Schema::hasTable('shop_payments') && Schema::hasColumn('shop_payments', 'gateway')) {
            DB::table('shop_payments')->whereNotNull('provider_reference')->orderBy('id')->each(function ($payment) {
                DB::table('shop_provider_payment_references')->insertOrIgnore([
                    'gateway' => $payment->gateway,
                    'provider_reference' => $payment->provider_reference,
                    'owner_type' => 'order_payment',
                    'owner_id' => (string) $payment->id,
                    'created_at' => $payment->created_at ?? now(),
                    'updated_at' => $payment->updated_at ?? now(),
                ]);
            });
        }
        if (Schema::hasTable('shop_account_payment_attempts')) {
            DB::table('shop_account_payment_attempts')->whereNotNull('provider_reference')->orderBy('id')->each(function ($attempt) {
                DB::table('shop_provider_payment_references')->insertOrIgnore([
                    'gateway' => 'payfast',
                    'provider_reference' => $attempt->provider_reference,
                    'owner_type' => 'account_payment',
                    'owner_id' => (string) $attempt->id,
                    'created_at' => $attempt->created_at ?? now(),
                    'updated_at' => $attempt->updated_at ?? now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_provider_payment_references');
    }
};
