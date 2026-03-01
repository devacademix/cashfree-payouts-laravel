<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\CashfreePayoutService;
use App\Models\Beneficiary;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\WalletOperation;

class PayoutController extends Controller
{
    protected CashfreePayoutService $cashfree;

    public function __construct(CashfreePayoutService $cashfree)
    {
        $this->cashfree = $cashfree;
    }

    /**
     * Cashfree Payouts Webhook V2 receiver.
     */
    public function handleWebhookV2(Request $request)
    {
        $signature = (string) $request->header('x-webhook-signature', '');
        $timestamp = (string) $request->header('x-webhook-timestamp', '');
        $rawBody = (string) $request->getContent();

        if (! $this->isValidWebhookSignature($signature, $timestamp, $rawBody)) {
            return response()->json([
                'message' => 'Invalid webhook signature',
            ], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Invalid webhook payload',
            ], 400);
        }

        $eventType = strtoupper((string) ($payload['type'] ?? ''));
        $eventTime = (string) ($payload['event_time'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $this->applyWebhookEvent($eventType, $eventTime, $data, $payload);

        return response()->json([
            'received' => true,
        ]);
    }

    /**
     * STEP 7 — Add Beneficiary API
     */
    public function addBeneficiary(Request $request)
    {
        $request->validate([
            'bene_id'      => 'required|unique:beneficiaries,bene_id',
            'name'         => 'required|string',
            'email'        => 'nullable|email',
            'phone'        => 'required|string',
            'bank_account' => 'required',
            'ifsc'         => 'required',
        ]);

        // Call Cashfree via Service
        $cashfreeResponse = $this->cashfree->addBeneficiary($request->all());

        // Save in DB
        Beneficiary::create([
            'bene_id'      => $request->bene_id,
            'name'         => $request->name,
            'email'        => $request->email,
            'phone'        => $request->phone,
            'bank_account' => $request->bank_account,
            'ifsc'         => $request->ifsc,
            'status'       => 'ACTIVE',
        ]);

        return response()->json([
            'message' => 'Beneficiary added successfully',
            'data'    => $cashfreeResponse
        ]);
    }

    /**
     * STEP 8 - Request Async Payout API
     */
    public function requestPayout(Request $request)
    {
        $validated = $request->validate([
            // Client-provided idempotency key: same logical request must reuse same transfer_id
            'transfer_id' => 'required|string|max:100',
            'bene_id' => 'required|exists:beneficiaries,bene_id',
            'amount' => 'required|numeric|min:1',
        ]);

        $transferId = $validated['transfer_id'];
        $createdNow = false;

        try {
            $payout = DB::transaction(function () use ($validated, $transferId, &$createdNow) {
                $existing = Payout::where('transfer_id', $transferId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $createdNow = true;

                return Payout::create([
                    'transfer_id' => $transferId,
                    'bene_id' => $validated['bene_id'],
                    'amount' => $validated['amount'],
                    // Persist first: if process crashes now, reconciliation can safely inspect INITIATED
                    'status' => Payout::STATUS_INITIATED,
                    'raw_response' => null,
                ]);
            });
        } catch (QueryException $e) {
            // DB-level uniqueness guard for concurrent duplicate requests
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            $payout = Payout::where('transfer_id', $transferId)->firstOrFail();
        }

        // Idempotent replay: never call Cashfree again for an existing transfer_id
        if (! $createdNow) {
            if ($payout->bene_id !== $validated['bene_id'] || (float) $payout->amount !== (float) $validated['amount']) {
                return response()->json([
                    'message' => 'transfer_id already exists with different payout data',
                    'transfer_id' => $payout->transfer_id,
                    'status' => $payout->status,
                ], 409);
            }

            return response()->json([
                'message' => 'Payout already exists for this transfer_id',
                'transfer_id' => $payout->transfer_id,
                'status' => $payout->status,
                'reference_id' => $payout->reference_id,
            ], 200);
        }

        try {
            $cashfreeResponse = $this->cashfree->requestPayout(
                $payout->bene_id,
                (float) $payout->amount,
                $payout->transfer_id
            );

            $gatewayStatus = strtoupper((string) ($cashfreeResponse['data']['status']
                ?? $cashfreeResponse['status']
                ?? Payout::STATUS_PENDING));

            $mappedStatus = match ($gatewayStatus) {
                Payout::STATUS_SUCCESS => Payout::STATUS_SUCCESS,
                Payout::STATUS_FAILED => Payout::STATUS_FAILED,
                Payout::STATUS_REVERSED => Payout::STATUS_REVERSED,
                default => Payout::STATUS_PENDING,
            };

            $payout->update([
                'reference_id' => $cashfreeResponse['data']['referenceId']
                    ?? $cashfreeResponse['referenceId']
                    ?? null,
                'status' => $mappedStatus,
                'failure_reason' => null,
                'raw_response' => $cashfreeResponse,
            ]);
        } catch (\Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Request payout failed:')) {
                // Cashfree explicitly rejected the request: persist terminal failure
                $payout->update([
                    'status' => Payout::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                    'raw_response' => ['error' => $e->getMessage()],
                ]);
            } else {
                // Crash/network ambiguity: keep INITIATED for safe manual/cron reconciliation
                $payout->update([
                    'status' => Payout::STATUS_INITIATED,
                    'failure_reason' => $e->getMessage(),
                    'raw_response' => ['error' => $e->getMessage()],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Payout request accepted',
            'transfer_id' => $payout->transfer_id,
            'status' => Payout::STATUS_PENDING,
            'reference_id' => $payout->reference_id,
        ], 202);
    }

    /**
     * Standard Transfer V2 with DB-first idempotency by transfer_id.
     */
    public function requestTransferV2(Request $request)
    {
        $validated = $request->validate([
            'transfer_id' => 'required|string|max:100',
            'bene_id' => 'required|exists:beneficiaries,bene_id',
            'amount' => 'required|numeric|min:1',
            'remarks' => 'nullable|string|max:255',
            'mode' => 'nullable|string|max:50',
            'currency' => 'nullable|string|size:3',
        ]);

        $transferId = $validated['transfer_id'];
        $createdNow = false;

        try {
            $payout = DB::transaction(function () use ($validated, $transferId, &$createdNow) {
                $existing = Payout::where('transfer_id', $transferId)->lockForUpdate()->first();

                if ($existing) {
                    return $existing;
                }

                $createdNow = true;

                return Payout::create([
                    'transfer_id' => $transferId,
                    'bene_id' => $validated['bene_id'],
                    'amount' => $validated['amount'],
                    'currency' => strtoupper($validated['currency'] ?? 'INR'),
                    'status' => Payout::STATUS_INITIATED,
                    'raw_response' => null,
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            $payout = Payout::where('transfer_id', $transferId)->firstOrFail();
        }

        if (! $createdNow) {
            return response()->json([
                'message' => 'Transfer already exists for this transfer_id',
                'transfer_id' => $payout->transfer_id,
                'status' => $payout->status,
                'reference_id' => $payout->reference_id,
            ], 200);
        }

        try {
            $response = $this->cashfree->standardTransferV2([
                'transfer_id' => $payout->transfer_id,
                'bene_id' => $payout->bene_id,
                'amount' => (float) $payout->amount,
                'currency' => $payout->currency,
                'mode' => $validated['mode'] ?? 'banktransfer',
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $status = strtoupper((string) ($response['status'] ?? Payout::STATUS_PENDING));
            $mappedStatus = in_array($status, Payout::terminalStatuses(), true)
                ? $status
                : Payout::STATUS_PENDING;

            $payout->update([
                'reference_id' => $response['cf_transfer_id'] ?? $response['reference_id'] ?? null,
                'status' => $mappedStatus,
                'raw_response' => $response,
                'failure_reason' => null,
            ]);
        } catch (\Throwable $e) {
            $payout->update([
                'status' => Payout::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
                'raw_response' => ['error' => $e->getMessage()],
            ]);
            throw $e;
        }

        return response()->json([
            'message' => 'Transfer accepted',
            'transfer_id' => $payout->transfer_id,
            'status' => $payout->status,
            'reference_id' => $payout->reference_id,
        ], 202);
    }

    /**
     * Poll-safe transfer status fetch + DB reconcile.
     */
    public function getTransferStatusV2(Request $request)
    {
        $validated = $request->validate([
            'transfer_id' => 'required|string|max:100',
        ]);

        $payout = Payout::where('transfer_id', $validated['transfer_id'])->first();

        $response = $this->cashfree->getTransferStatusV2($validated['transfer_id']);

        if ($payout && $payout->isTrackable()) {
            $status = strtoupper((string) ($response['status'] ?? Payout::STATUS_PENDING));
            $mappedStatus = in_array($status, Payout::terminalStatuses(), true)
                ? $status
                : Payout::STATUS_PENDING;

            $payout->update([
                'reference_id' => $response['cf_transfer_id'] ?? $payout->reference_id,
                'status' => $mappedStatus,
                'raw_response' => $response,
                'failure_reason' => in_array($mappedStatus, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true)
                    ? ($response['reason'] ?? $response['message'] ?? null)
                    : null,
            ]);
        }

        return response()->json([
            'data' => $response,
        ]);
    }

    public function createBeneficiaryV2(Request $request)
    {
        $validated = $request->validate([
            'bene_id' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:20',
            'bank_account' => 'required|string|max:50',
            'ifsc' => 'required|string|max:20',
        ]);

        $response = $this->cashfree->createBeneficiaryV2($validated);

        Beneficiary::updateOrCreate(
            ['bene_id' => $validated['bene_id']],
            [
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'],
                'bank_account' => $validated['bank_account'],
                'ifsc' => $validated['ifsc'],
                'status' => 'ACTIVE',
            ]
        );

        return response()->json([
            'message' => 'Beneficiary V2 created',
            'data' => $response,
        ], 201);
    }

    public function getBeneficiaryV2(Request $request)
    {
        $validated = $request->validate([
            'bene_id' => 'required|string|max:255',
        ]);

        $response = $this->cashfree->getBeneficiaryV2($validated['bene_id']);

        return response()->json([
            'data' => $response,
        ]);
    }

    public function removeBeneficiaryV2(Request $request)
    {
        $validated = $request->validate([
            'bene_id' => 'required|string|max:255',
        ]);

        $response = $this->cashfree->removeBeneficiaryV2($validated['bene_id']);

        Beneficiary::where('bene_id', $validated['bene_id'])->update([
            'status' => 'REMOVED',
        ]);

        return response()->json([
            'message' => 'Beneficiary V2 removed',
            'data' => $response,
        ]);
    }

    public function batchTransferV2(Request $request)
    {
        $validated = $request->validate([
            'batch_transfer_id' => 'required|string|max:100',
            'transfers' => 'required|array|min:1',
            'transfers.*.transfer_id' => 'required|string|max:100',
            'transfers.*.bene_id' => 'required|string|exists:beneficiaries,bene_id',
            'transfers.*.amount' => 'required|numeric|min:1',
            'transfers.*.mode' => 'nullable|string|max:50',
            'transfers.*.remarks' => 'nullable|string|max:255',
        ]);

        $batchId = $validated['batch_transfer_id'];

        $batch = PayoutBatch::firstOrCreate(
            ['batch_transfer_id' => $batchId],
            ['status' => Payout::STATUS_INITIATED, 'raw_response' => null]
        );

        if ($batch->raw_response !== null || in_array($batch->status, Payout::terminalStatuses(), true)) {
            return response()->json([
                'message' => 'Batch already exists for this batch_transfer_id',
                'batch_transfer_id' => $batch->batch_transfer_id,
                'status' => $batch->status,
                'reference_id' => $batch->reference_id,
            ]);
        }

        $transfersPayload = collect($validated['transfers'])->map(function (array $transfer): array {
            return [
                'transfer_id' => $transfer['transfer_id'],
                'transfer_amount' => (float) $transfer['amount'],
                'transfer_currency' => 'INR',
                'beneficiary_details' => [
                    'beneficiary_id' => $transfer['bene_id'],
                ],
                'transfer_mode' => $transfer['mode'] ?? 'banktransfer',
                'remarks' => $transfer['remarks'] ?? null,
            ];
        })->all();

        try {
            $response = $this->cashfree->batchTransferV2($batchId, $transfersPayload);

            $status = strtoupper((string) ($response['status'] ?? Payout::STATUS_PENDING));
            $batch->update([
                'reference_id' => $response['cf_batch_transfer_id'] ?? $response['reference_id'] ?? null,
                'status' => in_array($status, Payout::terminalStatuses(), true) ? $status : Payout::STATUS_PENDING,
                'raw_response' => $response,
            ]);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => Payout::STATUS_FAILED,
                'raw_response' => ['error' => $e->getMessage()],
            ]);
            throw $e;
        }

        return response()->json([
            'message' => 'Batch transfer accepted',
            'batch_transfer_id' => $batch->batch_transfer_id,
            'status' => $batch->status,
            'reference_id' => $batch->reference_id,
        ], 202);
    }

    public function getBatchTransferStatusV2(Request $request)
    {
        $validated = $request->validate([
            'batch_transfer_id' => 'required|string|max:100',
        ]);

        $batch = PayoutBatch::where('batch_transfer_id', $validated['batch_transfer_id'])->first();
        $response = $this->cashfree->getBatchTransferStatusV2($validated['batch_transfer_id']);

        if ($batch && ! in_array($batch->status, Payout::terminalStatuses(), true)) {
            $status = strtoupper((string) ($response['status'] ?? Payout::STATUS_PENDING));
            $batch->update([
                'reference_id' => $response['cf_batch_transfer_id'] ?? $batch->reference_id,
                'status' => in_array($status, Payout::terminalStatuses(), true) ? $status : Payout::STATUS_PENDING,
                'raw_response' => $response,
            ]);
        }

        return response()->json([
            'data' => $response,
        ]);
    }

    private function applyWebhookEvent(string $eventType, string $eventTime, array $data, array $payload): void
    {
        if (in_array($eventType, [
            'TRANSFER_ACKNOWLEDGED',
            'TRANSFER_SUCCESS',
            'TRANSFER_FAILED',
            'TRANSFER_REVERSED',
            'TRANSFER_REJECTED',
        ], true)) {
            $this->reconcileTransferFromWebhook($eventType, $eventTime, $data, $payload);
            return;
        }

        if ($eventType === 'BULK_TRANSFER_REJECTED') {
            $this->reconcileBatchFromWebhook($eventTime, $data, $payload);
            return;
        }

        Log::info('Unhandled Cashfree webhook V2 event', [
            'event_type' => $eventType,
            'event_time' => $eventTime,
        ]);
    }

    private function reconcileTransferFromWebhook(string $eventType, string $eventTime, array $data, array $payload): void
    {
        $transferId = (string) ($data['transfer_id'] ?? '');

        if ($transferId === '') {
            Log::warning('Cashfree webhook V2 transfer event missing transfer_id', [
                'event_type' => $eventType,
                'event_time' => $eventTime,
            ]);
            return;
        }

        $payout = Payout::where('transfer_id', $transferId)->first();

        if (! $payout) {
            Log::warning('Cashfree webhook V2 transfer not found in local DB', [
                'transfer_id' => $transferId,
                'event_type' => $eventType,
            ]);
            return;
        }

        $mappedStatus = match ($eventType) {
            'TRANSFER_ACKNOWLEDGED', 'TRANSFER_SUCCESS' => Payout::STATUS_SUCCESS,
            'TRANSFER_FAILED', 'TRANSFER_REJECTED' => Payout::STATUS_FAILED,
            'TRANSFER_REVERSED' => Payout::STATUS_REVERSED,
            default => $payout->status,
        };

        $reason = (string) ($data['status_description'] ?? '');
        $reason = $reason !== ''
            ? $reason
            : ((string) ($data['status_code'] ?? ''));
        $isFailure = in_array($mappedStatus, [Payout::STATUS_FAILED, Payout::STATUS_REVERSED], true);

        $payout->update([
            'reference_id' => $data['cf_transfer_id'] ?? $payout->reference_id,
            'status' => $mappedStatus,
            'failure_reason' => $isFailure ? ($reason !== '' ? $reason : $payout->failure_reason) : null,
            'raw_response' => [
                'source' => 'cashfree_webhook_v2',
                'event_type' => $eventType,
                'event_time' => $eventTime,
                'payload' => $payload,
            ],
        ]);
    }

    private function reconcileBatchFromWebhook(string $eventTime, array $data, array $payload): void
    {
        $batchTransferId = (string) ($data['batch_transfer_id'] ?? '');

        if ($batchTransferId === '') {
            Log::warning('Cashfree webhook V2 batch event missing batch_transfer_id', [
                'event_time' => $eventTime,
            ]);
            return;
        }

        $batch = PayoutBatch::where('batch_transfer_id', $batchTransferId)->first();

        if (! $batch) {
            Log::warning('Cashfree webhook V2 batch transfer not found in local DB', [
                'batch_transfer_id' => $batchTransferId,
            ]);
            return;
        }

        $batch->update([
            'reference_id' => $data['cf_batch_transfer_id'] ?? $batch->reference_id,
            'status' => Payout::STATUS_FAILED,
            'raw_response' => [
                'source' => 'cashfree_webhook_v2',
                'event_type' => 'BULK_TRANSFER_REJECTED',
                'event_time' => $eventTime,
                'payload' => $payload,
            ],
        ]);
    }

    private function isValidWebhookSignature(string $signature, string $timestamp, string $rawBody): bool
    {
        if ($signature === '' || $timestamp === '' || $rawBody === '') {
            return false;
        }

        $secret = (string) config('cashfree.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $generated = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        return hash_equals($generated, $signature);
    }

    public function getBalance()
    {
        return response()->json([
            'data' => $this->cashfree->getBalance(),
        ]);
    }

    public function internalTransfer(Request $request)
    {
        $validated = $request->validate([
            'operation_id' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:1',
            'from' => 'required|string|max:100',
            'to' => 'required|string|max:100',
            'remarks' => 'nullable|string|max:255',
        ]);

        $operationId = $validated['operation_id'] ?? ('INT_' . strtoupper((string) Str::uuid()));

        $operation = WalletOperation::firstOrCreate(
            ['operation_id' => $operationId],
            [
                'type' => WalletOperation::TYPE_INTERNAL_TRANSFER,
                'amount' => $validated['amount'],
                'status' => Payout::STATUS_INITIATED,
                'metadata' => [
                    'from' => $validated['from'],
                    'to' => $validated['to'],
                    'remarks' => $validated['remarks'] ?? null,
                ],
            ]
        );

        if ($operation->raw_response !== null || in_array($operation->status, Payout::terminalStatuses(), true)) {
            return response()->json([
                'message' => 'Internal transfer already exists',
                'operation_id' => $operation->operation_id,
                'status' => $operation->status,
            ]);
        }

        $response = $this->cashfree->internalTransfer([
            'amount' => (float) $validated['amount'],
            'from' => $validated['from'],
            'to' => $validated['to'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $operation->update([
            'status' => Payout::STATUS_SUCCESS,
            'raw_response' => $response,
        ]);

        return response()->json([
            'message' => 'Internal transfer completed',
            'operation_id' => $operation->operation_id,
            'status' => $operation->status,
            'data' => $response,
        ]);
    }

    public function selfWithdrawal(Request $request)
    {
        $validated = $request->validate([
            'operation_id' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:1',
            'remarks' => 'nullable|string|max:255',
        ]);

        $operationId = $validated['operation_id'] ?? ('SW_' . strtoupper((string) Str::uuid()));

        $operation = WalletOperation::firstOrCreate(
            ['operation_id' => $operationId],
            [
                'type' => WalletOperation::TYPE_SELF_WITHDRAWAL,
                'amount' => $validated['amount'],
                'status' => Payout::STATUS_INITIATED,
                'metadata' => [
                    'remarks' => $validated['remarks'] ?? null,
                ],
            ]
        );

        if ($operation->raw_response !== null || in_array($operation->status, Payout::terminalStatuses(), true)) {
            return response()->json([
                'message' => 'Self withdrawal already exists',
                'operation_id' => $operation->operation_id,
                'status' => $operation->status,
            ]);
        }

        $response = $this->cashfree->selfWithdrawal([
            'amount' => (float) $validated['amount'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        $operation->update([
            'status' => Payout::STATUS_SUCCESS,
            'raw_response' => $response,
        ]);

        return response()->json([
            'message' => 'Self withdrawal completed',
            'operation_id' => $operation->operation_id,
            'status' => $operation->status,
            'data' => $response,
        ]);
    }
}
