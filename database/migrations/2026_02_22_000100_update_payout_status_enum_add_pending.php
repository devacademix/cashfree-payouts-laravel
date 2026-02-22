<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE payouts
                MODIFY status ENUM('INITIATED','PENDING','SUCCESS','FAILED','REVERSED')
                NOT NULL DEFAULT 'INITIATED'
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE payouts
                MODIFY status ENUM('INITIATED','PROCESSING','SUCCESS','FAILED','REVERSED')
                NOT NULL DEFAULT 'INITIATED'
            ");
        }
    }
};
