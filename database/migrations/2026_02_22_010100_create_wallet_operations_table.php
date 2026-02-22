<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 100)->unique();
            $table->string('type', 50);
            $table->decimal('amount', 12, 2);
            $table->string('status', 50)->default('INITIATED');
            $table->json('metadata')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_operations');
    }
};
