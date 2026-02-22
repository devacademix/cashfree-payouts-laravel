<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            // Identifiers
            $table->string('transfer_id', 100)->unique();     // Your internal payout ID
            $table->string('bene_id', 255);                   // Beneficiary ID
            $table->string('reference_id', 255)->nullable();  // Cashfree reference

            // Financials
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');

            // Payout mode
            $table->enum('mode', [
                'IMPS',
                'NEFT',
                'RTGS',
                'UPI'
            ])->default('IMPS');

            // Status lifecycle
            $table->enum('status', [
                'INITIATED',
                'PROCESSING',
                'SUCCESS',
                'FAILED',
                'REVERSED'
            ])->default('INITIATED');

            // Failure diagnostics
            $table->string('failure_reason', 255)->nullable();

            // Raw gateway payload (audit / reconciliation)
            $table->json('raw_response')->nullable();

            $table->timestamps();

            // Performance & reconciliation indexes
            $table->index('bene_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};