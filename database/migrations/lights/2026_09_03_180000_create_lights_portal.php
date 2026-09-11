<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        $schema = Schema::connection('lights');
        $schema->create('lights_users', function (Blueprint $t) {
            $t->id(); $t->string('name', 100); $t->string('email')->unique(); $t->string('password');
            $t->boolean('is_admin')->default(false); $t->boolean('active')->default(true);
            $t->unsignedBigInteger('balance_cents')->default(0); $t->rememberToken(); $t->timestamps();
        });
        $schema->create('lights_locks', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); });
        DB::connection('lights')->table('lights_locks')->insert(['id' => 1]);
        $schema->create('lights_courts', function (Blueprint $t) {
            $t->id(); $t->string('name', 100); $t->unsignedInteger('rate_cents');
            $t->string('device_label', 80); $t->unsignedTinyInteger('channel');
            $t->boolean('active')->default(true); $t->boolean('relay_on')->default(false);
            $t->unsignedBigInteger('relay_until')->nullable(); $t->unique(['device_label', 'channel']);
        });
        $schema->create('lights_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('user_id')->constrained('lights_users');
            $t->foreignId('court_id')->constrained('lights_courts');
            $t->unsignedBigInteger('active_user_id')->nullable()->unique();
            $t->unsignedBigInteger('active_court_id')->nullable()->unique();
            $t->unsignedInteger('rate_cents'); $t->unsignedBigInteger('budget_cents');
            $t->unsignedBigInteger('started_at'); $t->unsignedBigInteger('deadline_at');
            $t->unsignedBigInteger('stopped_at')->nullable(); $t->unsignedBigInteger('charged_cents')->default(0);
            $t->string('stop_reason')->nullable();
        });
        $schema->create('lights_topups', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('user_id')->constrained('lights_users');
            $t->string('request_key', 36); $t->unsignedInteger('amount_cents');
            $t->string('status')->default('pending'); $t->string('gateway')->default('payfast_simulator');
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('confirmed_at')->nullable();
            $t->unique(['user_id', 'request_key']);
        });
        $schema->create('lights_ledger', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('lights_users');
            $t->bigInteger('amount_cents'); $t->unsignedBigInteger('balance_after');
            $t->string('kind'); $t->string('reference', 120)->unique(); $t->unsignedBigInteger('created_at');
        });
        $schema->create('lights_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('actor_id')->nullable(); $t->string('kind');
            $t->text('details'); $t->unsignedBigInteger('created_at');
        });
        $schema->create('lights_worker', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedBigInteger('seen_at'); });
    }

    public function down(): void
    {
        foreach (['lights_worker', 'lights_events', 'lights_ledger', 'lights_topups', 'lights_sessions', 'lights_courts', 'lights_locks', 'lights_users'] as $table) {
            Schema::connection('lights')->dropIfExists($table);
        }
    }
};
