<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Services\CashfreePayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PayoutOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_and_transfer_status_operations_work(): void
    {
        Beneficiary::create([
            'bene_id' => 'USER_1001',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '9876543210',
            'bank_account' => '00111122233',
            'ifsc' => 'HDFC0000001',
            'status' => 'ACTIVE',
        ]);

        $this->mock(CashfreePayoutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('standardTransferV2')
                ->once()
                ->andReturn([
                    'status' => 'PENDING',
                    'cf_transfer_id' => 'CF_TRF_1001',
                ]);

            $mock->shouldReceive('getTransferStatusV2')
                ->once()
                ->andReturn([
                    'status' => 'SUCCESS',
                    'cf_transfer_id' => 'CF_TRF_1001',
                ]);
        });

        $transferResponse = $this->postJson('/api/payout/transfer', [
            'transfer_id' => 'TRF_1001',
            'bene_id' => 'USER_1001',
            'amount' => 10,
            'mode' => 'banktransfer',
            'currency' => 'INR',
            'remarks' => 'transfer test',
        ]);

        $transferResponse
            ->assertStatus(202)
            ->assertJsonPath('transfer_id', 'TRF_1001');

        $statusResponse = $this->getJson('/api/payout/transfer/status?transfer_id=TRF_1001');

        $statusResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'SUCCESS');
    }

    public function test_batch_transfer_and_batch_status_operations_work(): void
    {
        Beneficiary::create([
            'bene_id' => 'USER_1001',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '9876543210',
            'bank_account' => '00111122233',
            'ifsc' => 'HDFC0000001',
            'status' => 'ACTIVE',
        ]);

        $this->mock(CashfreePayoutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('batchTransferV2')
                ->once()
                ->andReturn([
                    'status' => 'PENDING',
                    'cf_batch_transfer_id' => 'CF_BATCH_1001',
                ]);

            $mock->shouldReceive('getBatchTransferStatusV2')
                ->once()
                ->andReturn([
                    'status' => 'SUCCESS',
                    'cf_batch_transfer_id' => 'CF_BATCH_1001',
                ]);
        });

        $batchResponse = $this->postJson('/api/payout/batch-transfer', [
            'batch_transfer_id' => 'BATCH_1001',
            'transfers' => [
                [
                    'transfer_id' => 'TRF_B1',
                    'bene_id' => 'USER_1001',
                    'amount' => 10,
                    'mode' => 'banktransfer',
                    'remarks' => 'Payout 1',
                ],
            ],
        ]);

        $batchResponse
            ->assertStatus(202)
            ->assertJsonPath('batch_transfer_id', 'BATCH_1001');

        $batchStatusResponse = $this->getJson('/api/payout/batch-transfer/status?batch_transfer_id=BATCH_1001');

        $batchStatusResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'SUCCESS');
    }

    public function test_beneficiary_create_get_remove_operations_work(): void
    {
        $this->mock(CashfreePayoutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createBeneficiaryV2')
                ->once()
                ->andReturn(['status' => 'SUCCESS']);

            $mock->shouldReceive('getBeneficiaryV2')
                ->once()
                ->andReturn(['beneficiary_id' => 'USER_1001', 'status' => 'ACTIVE']);

            $mock->shouldReceive('removeBeneficiaryV2')
                ->once()
                ->andReturn(['status' => 'SUCCESS']);
        });

        $createResponse = $this->postJson('/api/payout/v2/beneficiary', [
            'bene_id' => 'USER_1001',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '9876543210',
            'bank_account' => '00111122233',
            'ifsc' => 'HDFC0000001',
        ]);

        $createResponse->assertStatus(201);

        $getResponse = $this->getJson('/api/payout/v2/beneficiary?bene_id=USER_1001');
        $getResponse
            ->assertOk()
            ->assertJsonPath('data.beneficiary_id', 'USER_1001');

        $removeResponse = $this->deleteJson('/api/payout/v2/beneficiary?bene_id=USER_1001');
        $removeResponse->assertOk();

        $this->assertDatabaseHas('beneficiaries', [
            'bene_id' => 'USER_1001',
            'status' => 'REMOVED',
        ]);
    }

    public function test_balance_internal_transfer_and_self_withdrawal_work(): void
    {
        $this->mock(CashfreePayoutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getBalance')
                ->once()
                ->andReturn(['available_balance' => 1234.56]);

            $mock->shouldReceive('internalTransfer')
                ->once()
                ->andReturn(['status' => 'SUCCESS']);

            $mock->shouldReceive('selfWithdrawal')
                ->once()
                ->andReturn(['status' => 'SUCCESS']);
        });

        $balanceResponse = $this->getJson('/api/payout/balance');
        $balanceResponse
            ->assertOk()
            ->assertJsonPath('data.available_balance', 1234.56);

        $internalTransferResponse = $this->postJson('/api/payout/internal-transfer', [
            'operation_id' => 'INT_1001',
            'amount' => 100,
            'from' => 'PRIMARY',
            'to' => 'PAYOUT',
            'remarks' => 'fund allocation',
        ]);

        $internalTransferResponse
            ->assertOk()
            ->assertJsonPath('status', 'SUCCESS');

        $selfWithdrawalResponse = $this->postJson('/api/payout/self-withdrawal', [
            'operation_id' => 'SW_1001',
            'amount' => 50,
            'remarks' => 'withdrawal test',
        ]);

        $selfWithdrawalResponse
            ->assertOk()
            ->assertJsonPath('status', 'SUCCESS');
    }
}
