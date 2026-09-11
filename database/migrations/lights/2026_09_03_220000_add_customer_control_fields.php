<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';
    public function up(): void
    {
        Schema::connection('lights')->table('lights_control_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('court_id')->nullable()->after('user_id');
            $table->foreign('court_id')->references('id')->on('lights_courts');
            $table->index(['court_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::connection('lights')->table('lights_control_sessions', function (Blueprint $table) {
            $table->dropForeign(['court_id']);
            $table->dropIndex(['court_id', 'created_at']);
            $table->dropColumn('court_id');
        });
    }
};
