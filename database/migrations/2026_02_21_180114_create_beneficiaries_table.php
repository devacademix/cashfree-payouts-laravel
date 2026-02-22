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
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();

            // External beneficiary ID (from payment gateway / bank)
            $table->string('bene_id', 255)->unique();

            // Beneficiary name
            $table->string('name', 255);

            // Bank account number
            $table->string('bank_account', 50);

            // IFSC code
            $table->string('ifsc', 20);

            // Status (active, inactive, verified, etc.)
            $table->string('status', 50)->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};