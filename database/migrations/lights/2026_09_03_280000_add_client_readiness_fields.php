<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection('lights')->table('lights_users', function (Blueprint $table) {
            $table->unsignedBigInteger('email_verified_at')->nullable();
            $table->unsignedBigInteger('terms_accepted_at')->nullable();
            $table->unsignedBigInteger('last_login_at')->nullable();
        });
        // Accounts that predate verification are trusted migrations, not new public registrations.
        DB::connection('lights')->table('lights_users')->whereNull('email_verified_at')
            ->update(['email_verified_at' => time(), 'terms_accepted_at' => time()]);

        Schema::connection('lights')->create('lights_account_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('purpose', 16);
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('used_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->foreign('user_id')->references('id')->on('lights_users')->cascadeOnDelete();
            $table->index(['user_id', 'purpose', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('lights')->dropIfExists('lights_account_tokens');
        Schema::connection('lights')->table('lights_users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'terms_accepted_at', 'last_login_at']);
        });
    }
};
