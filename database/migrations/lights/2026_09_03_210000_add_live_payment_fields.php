<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';
    public function up(): void
    {
        Schema::connection('lights')->table('lights_topups', function (Blueprint $table) {
            $table->string('gateway_reference', 100)->nullable()->unique();
            $table->unsignedBigInteger('notified_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::connection('lights')->table('lights_topups', function (Blueprint $table) {
            $table->dropUnique(['gateway_reference']);
            $table->dropColumn(['gateway_reference', 'notified_at']);
        });
    }
};
