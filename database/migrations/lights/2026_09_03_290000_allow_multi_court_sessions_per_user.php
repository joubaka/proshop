<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        foreach (['lights_sessions', 'lights_control_sessions'] as $name) {
            Schema::connection($this->connection)->table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique($name.'_active_user_id_unique');
                $table->index('active_user_id', $name.'_active_user_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['lights_sessions', 'lights_control_sessions'] as $name) {
            Schema::connection($this->connection)->table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_active_user_id_index');
                $table->unique('active_user_id', $name.'_active_user_id_unique');
            });
        }
    }
};
