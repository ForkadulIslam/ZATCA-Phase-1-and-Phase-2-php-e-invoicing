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
            $table->string('vat_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('branch_industry')->nullable();
            $table->json('location')->nullable();
            $table->text('private_key');
            $table->text('certificate');
            $table->string('secret');
            $table->string('request_id')->nullable();
            $table->string('last_invoice_hash')->default('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==');
            $table->unsignedBigInteger('last_icv')->default(0);
            $table->boolean('production')->default(false);
            $table->string('status')->default('onboarded');
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
