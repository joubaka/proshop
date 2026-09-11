<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection('lights')->table('lights_manual_commands', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::connection('lights')->table('lights_manual_commands', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });
    }
};
