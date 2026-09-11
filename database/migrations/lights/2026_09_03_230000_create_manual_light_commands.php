<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';
    public function up(): void
    {
        Schema::connection('lights')->create('lights_manual_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->uuid('request_key');
            $table->unsignedTinyInteger('channel');
            $table->string('action', 8);
            $table->string('state', 24);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('command_at')->nullable();
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->unique(['actor_id', 'request_key']);
            $table->index(['channel', 'created_at']);
            $table->foreign('actor_id')->references('id')->on('lights_users');
        });
    }
    public function down(): void { Schema::connection('lights')->dropIfExists('lights_manual_commands'); }
};
