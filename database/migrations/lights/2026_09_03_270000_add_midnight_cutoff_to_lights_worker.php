<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'lights';

    public function up(): void
    {
        Schema::connection('lights')->table('lights_worker', function (Blueprint $table) {
            $table->string('midnight_cutoff_date', 10)->nullable();
            $table->unsignedBigInteger('midnight_cutoff_at')->nullable();
        });
        DB::connection('lights')->table('lights_worker')->update([
            'midnight_cutoff_date' => CarbonImmutable::now('Africa/Johannesburg')->format('Y-m-d'),
        ]);
    }

    public function down(): void
    {
        Schema::connection('lights')->table('lights_worker', function (Blueprint $table) {
            $table->dropColumn(['midnight_cutoff_date', 'midnight_cutoff_at']);
        });
    }
};
