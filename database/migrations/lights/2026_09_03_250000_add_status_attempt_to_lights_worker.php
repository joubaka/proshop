<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection('lights')->table('lights_worker', function (Blueprint $table) {
            $table->unsignedBigInteger('status_attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('lights')->table('lights_worker', function (Blueprint $table) {
            $table->dropColumn('status_attempted_at');
        });
    }
};
