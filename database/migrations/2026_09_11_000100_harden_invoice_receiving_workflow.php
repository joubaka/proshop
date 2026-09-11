<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoice_scan_lines', function (Blueprint $table) {
            $table->unsignedInteger('purchase_order_line_id')->nullable()->index()->after('variation_id');
            $table->string('price_change_reason')->nullable()->after('price_change_approved');
            $table->unsignedInteger('price_approved_by')->nullable()->after('price_change_reason');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_scan_lines', fn (Blueprint $table) => $table->dropColumn(['purchase_order_line_id','price_change_reason','price_approved_by']));
    }
};
