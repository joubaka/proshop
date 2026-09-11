<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection($this->connection)->create('lights_payfast_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->boolean('sandbox')->default(true);
            $table->text('merchant_id')->nullable();
            $table->text('merchant_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('updated_at');
            $table->foreign('updated_by')->references('id')->on('lights_users');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('lights_payfast_settings');
    }
};
