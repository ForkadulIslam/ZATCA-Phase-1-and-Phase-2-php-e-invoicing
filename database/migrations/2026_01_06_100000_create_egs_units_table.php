<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('egs_units', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('custom_id')->nullable();
            $table->string('model')->nullable();
            $table->string('crn_number')->nullable();
            $table->string('vat_name');
            $table->string('vat_number', 15);
            $table->string('branch_name')->nullable();
            $table->string('branch_industry')->nullable();
            $table->json('location')->nullable();
            $table->text('private_key');
            $table->text('compliance_certificate')->nullable();
            $table->text('compliance_secret')->nullable();
            $table->text('certificate');
            $table->text('secret');
            $table->string('request_id')->nullable();
            $table->unsignedBigInteger('current_counter')->default(0);
            $table->text('last_invoice_hash')->nullable();
            $table->string('status')->default('onboarded');
            $table->boolean('production')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egs_units');
    }
};
