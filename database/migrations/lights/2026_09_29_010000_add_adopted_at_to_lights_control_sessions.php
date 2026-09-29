<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection('lights')->table('lights_control_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('adopted_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('lights')->table('lights_control_sessions', function (Blueprint $table) {
            $table->dropColumn('adopted_at');
        });
    }
};
