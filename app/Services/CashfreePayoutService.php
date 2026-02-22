<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class CashfreePayoutService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('cashfree.base_url');
    }

    private function authHeaders(): array
    {
        return [
            'X-Client-Id' => config('cashfree.client_id'),
            'X-Client-Secret' => config('cashfree.client_secret'),
        ];
    }

    private function v2Headers(?string $requestId = null): array
    {
        return [
            'x-api-version' => config('cashfree.api_version', '2024-01-01'),
            'x-request-id' => $requestId ?: (string) Str::uuid(),
        ];
    }

    /**
     * STEP 6.3 - Authentication (Token Generation)
     */
    public function getToken(): string
    {
        $clientId = (string) config('cashfree.client_id', '');
        $clientSecret = (string) config('cashfree.client_secret', '');
        $baseUrl = (string) config('cashfree.base_url', '');

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new \Exception('Cashfree config missing: check CASHFREE_PAYOUT_BASE_URL, CASHFREE_CLIENT_ID, CASHFREE_CLIENT_SECRET');
        }

        $response = Http::withHeaders(array_merge($this->authHeaders(), [
                'Accept' => 'application/json',
            ]))
            ->post($this->baseUrl . '/payout/v1/authorize');

        if (! $response->successful()) {
            throw new \Exception('Cashfree auth failed: ' . $response->body());
        }

        $json = $response->json();

        $token = data_get($json, 'data.token')
            ?? data_get($json, 'token')
            ?? data_get($json, 'data.access_token')
            ?? data_get($json, 'access_token');

        if (! is_string($token) || $token === '') {
            $message = data_get($json, 'message')
                ?? data_get($json, 'subCode')
                ?? 'Cashfree auth token missing in response.';

            throw new \Exception('Cashfree auth failed: ' . $message . ' | body: ' . $response->body());
        }

        return $token;
    }

    /**
     * STEP 7 - Add Beneficiary
     */
    public function addBeneficiary(array $data): array
    {
        $response = Http::withToken($this->getToken())
            ->post($this->baseUrl . '/payout/v1/addBeneficiary', [
                'beneId' => $data['bene_id'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'bankAccount' => $data['bank_account'],
                'ifsc' => $data['ifsc'],
                'address1' => $data['address1'] ?? 'NA',
                'city' => $data['city'] ?? 'NA',
                'state' => $data['state'] ?? 'NA',
                'pincode' => $data['pincode'] ?? '000000',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Add beneficiary failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * STEP 8 - Request Async Payout Transfer
     */
    public function requestPayout(string $beneId, float $amount, string $transferId): array
    {
        $response = Http::withToken($this->getToken())
            ->post($this->baseUrl . '/payout/v1/requestAsyncTransfer', [
                'beneId' => $beneId,
                'amount' => $amount,
                'transferId' => $transferId,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Request payout failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * STEP 10 - Get transfer status for async payout reconciliation.
     */
    public function getTransferStatus(string $referenceId, string $transferId): array
    {
        $response = Http::withToken($this->getToken())
            ->get($this->baseUrl . '/payout/v1/getTransferStatus', [
                'referenceId' => $referenceId,
                'transferId' => $transferId,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Get transfer status failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Create beneficiary.
     */
    public function createBeneficiaryV2(array $data): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($data['bene_id'] ?? null)
        ))->post($this->baseUrl . '/payout/beneficiary', [
            'beneficiary_id' => $data['bene_id'],
            'beneficiary_name' => $data['name'],
            'beneficiary_instrument_details' => [
                'bank_account_number' => $data['bank_account'],
                'bank_ifsc' => $data['ifsc'],
            ],
            'beneficiary_contact_details' => [
                'beneficiary_email' => $data['email'] ?? null,
                'beneficiary_phone' => $data['phone'],
            ],
        ]);

        if (! $response->successful()) {
            throw new \Exception('Create beneficiary V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Get beneficiary by beneficiary_id.
     */
    public function getBeneficiaryV2(string $beneId): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($beneId)
        ))->get($this->baseUrl . '/payout/beneficiary', [
            'beneficiary_id' => $beneId,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Get beneficiary V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Remove beneficiary by beneficiary_id.
     */
    public function removeBeneficiaryV2(string $beneId): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($beneId)
        ))->delete($this->baseUrl . '/payout/beneficiary', [
            'beneficiary_id' => $beneId,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Remove beneficiary V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Standard transfer (async by nature, tracked by transfer_id).
     */
    public function standardTransferV2(array $payload): array
    {
        $transferId = (string) ($payload['transfer_id'] ?? '');
        $requestId = $transferId !== '' ? $transferId : null;

        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($requestId)
        ))->post($this->baseUrl . '/payout/transfers', [
            'transfer_id' => $transferId,
            'transfer_amount' => (float) $payload['amount'],
            'transfer_currency' => $payload['currency'] ?? 'INR',
            'beneficiary_details' => [
                'beneficiary_id' => $payload['bene_id'],
            ],
            'transfer_mode' => $payload['mode'] ?? 'banktransfer',
            'remarks' => $payload['remarks'] ?? null,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Standard transfer V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Get transfer status.
     */
    public function getTransferStatusV2(string $transferId): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($transferId)
        ))->get($this->baseUrl . '/payout/transfers', [
            'transfer_id' => $transferId,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Get transfer status V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Batch transfer.
     */
    public function batchTransferV2(string $batchTransferId, array $transfers): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($batchTransferId)
        ))->post($this->baseUrl . '/payout/transfers/batch', [
            'batch_transfer_id' => $batchTransferId,
            'transfers' => $transfers,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Batch transfer V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Cashfree V2: Get batch transfer status.
     */
    public function getBatchTransferStatusV2(string $batchTransferId): array
    {
        $response = Http::withHeaders(array_merge(
            $this->authHeaders(),
            $this->v2Headers($batchTransferId)
        ))->get($this->baseUrl . '/payout/transfers/batch', [
            'batch_transfer_id' => $batchTransferId,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Get batch transfer status V2 failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get wallet balance.
     */
    public function getBalance(): array
    {
        $response = Http::withToken($this->getToken())
            ->get($this->baseUrl . '/payout/v1/getBalance');

        if (! $response->successful()) {
            throw new \Exception('Get balance failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Internal transfer between wallets.
     */
    public function internalTransfer(array $payload): array
    {
        $response = Http::withToken($this->getToken())
            ->post($this->baseUrl . '/payout/v1/internalTransfer', $payload);

        if (! $response->successful()) {
            throw new \Exception('Internal transfer failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Self withdrawal to registered account.
     */
    public function selfWithdrawal(array $payload): array
    {
        $response = Http::withToken($this->getToken())
            ->post($this->baseUrl . '/payout/v1/selfWithdrawal', $payload);

        if (! $response->successful()) {
            throw new \Exception('Self withdrawal failed: ' . $response->body());
        }

        return $response->json();
    }
}
