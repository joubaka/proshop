<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';
    public function up(): void
    {
        Schema::connection('lights')->create('lights_control_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->unsignedTinyInteger('channel');
            $table->unsignedTinyInteger('active_channel')->nullable()->unique();
            $table->uuid('request_key');
            $table->unique(['user_id', 'request_key']);
            $table->string('driver', 16);
            $table->string('state', 24);
            $table->unsignedInteger('rate_cents');
            $table->unsignedBigInteger('budget_cents');
            $table->unsignedInteger('duration_seconds');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('sent_at')->nullable();
            $table->unsignedBigInteger('started_at')->nullable();
            $table->unsignedBigInteger('deadline_at')->nullable();
            $table->unsignedBigInteger('stop_requested_at')->nullable();
            $table->unsignedBigInteger('stopped_at')->nullable();
            $table->unsignedBigInteger('charged_cents')->default(0);
            $table->unsignedBigInteger('command_at')->nullable();
            $table->boolean('uncertain')->default(false);
            $table->string('note', 255)->nullable();
            $table->foreign('user_id')->references('id')->on('lights_users');
        });
    }
    public function down(): void { Schema::connection('lights')->dropIfExists('lights_control_sessions'); }
};
