<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';
    public function up(): void {
        Schema::connection('lights')->table('lights_sessions', function (Blueprint $t) {
            $t->uuid('request_key')->nullable();
            $t->unique(['user_id', 'request_key']);
        });
    }
    public function down(): void {
        Schema::connection('lights')->table('lights_sessions', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'request_key']); $t->dropColumn('request_key');
        });
    }
};
