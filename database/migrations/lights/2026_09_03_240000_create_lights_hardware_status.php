<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection($this->connection)->create('lights_hardware_status', function (Blueprint $table) {
            $table->unsignedTinyInteger('channel')->primary();
            $table->boolean('online')->default(false);
            $table->boolean('output')->nullable();
            $table->decimal('watts', 10, 2)->nullable();
            $table->decimal('volts', 8, 2)->nullable();
            $table->boolean('has_errors')->default(false);
            $table->unsignedBigInteger('checked_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('lights_hardware_status');
    }
};
