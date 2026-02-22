<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE payouts
                MODIFY status ENUM('INITIATED','PENDING','PROCESSING','SUCCESS','FAILED','REVERSED')
                NOT NULL DEFAULT 'INITIATED'
            ");
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('ALTER TABLE payouts RENAME TO payouts_old');

            DB::statement("
                CREATE TABLE payouts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    transfer_id VARCHAR(100) NOT NULL UNIQUE,
                    bene_id VARCHAR(255) NOT NULL,
                    reference_id VARCHAR(255) NULL,
                    amount NUMERIC NOT NULL,
                    currency VARCHAR(3) NOT NULL DEFAULT 'INR',
                    mode VARCHAR(255) NOT NULL DEFAULT 'IMPS' CHECK (mode IN ('IMPS','NEFT','RTGS','UPI')),
                    status VARCHAR(255) NOT NULL DEFAULT 'INITIATED' CHECK (status IN ('INITIATED','PENDING','PROCESSING','SUCCESS','FAILED','REVERSED')),
                    failure_reason VARCHAR(255) NULL,
                    raw_response TEXT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ");

            DB::statement("
                INSERT INTO payouts (id, transfer_id, bene_id, reference_id, amount, currency, mode, status, failure_reason, raw_response, created_at, updated_at)
                SELECT id, transfer_id, bene_id, reference_id, amount, currency, mode,
                       CASE WHEN status = 'PROCESSING' THEN 'PENDING' ELSE status END,
                       failure_reason, raw_response, created_at, updated_at
                FROM payouts_old
            ");

            DB::statement('DROP TABLE payouts_old');
            DB::statement('CREATE INDEX payouts_bene_id_index ON payouts (bene_id)');
            DB::statement('CREATE INDEX payouts_status_index ON payouts (status)');
            DB::statement('CREATE INDEX payouts_created_at_index ON payouts (created_at)');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
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
};
