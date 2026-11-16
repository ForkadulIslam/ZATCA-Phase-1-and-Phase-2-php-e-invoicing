<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_type', 10)->default('388')->after('invoice_number');
            $table->string('reference_invoice_number')->nullable()->after('invoice_type')->index();
            $table->string('reason')->nullable()->after('reference_invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['reference_invoice_number']);
            $table->dropColumn(['invoice_type', 'reference_invoice_number', 'reason']);
        });
    }
};
