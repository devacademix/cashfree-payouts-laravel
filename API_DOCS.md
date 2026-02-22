# Cashfree Payout API Documentation

## Base Setup

- Base URL: `http://127.0.0.1:8000/api`
- Content type: `application/json`
- Accept: `application/json`

Required `.env` for Cashfree:

```env
CASHFREE_PAYOUT_BASE_URL=https://sandbox.cashfree.com
CASHFREE_CLIENT_ID=your_client_id
CASHFREE_CLIENT_SECRET=your_client_secret
CASHFREE_API_VERSION=2024-01-01
```

## Quick Route Index

### Legacy

- `POST /payout/beneficiary`
- `POST /payout/request`

### V2 (Primary)

- `POST /payout/v2/transfer`
- `GET /payout/v2/transfer/status`
- `POST /payout/v2/batch-transfer`
- `GET /payout/v2/batch-transfer/status`
- `POST /payout/v2/beneficiary`
- `GET /payout/v2/beneficiary`
- `DELETE /payout/v2/beneficiary`

### Alias Routes (same V2 controller methods)

- `POST /payout/transfer`
- `GET /payout/transfer/status`
- `POST /payout/batch-transfer`
- `GET /payout/batch-transfer/status`

### Wallet

- `GET /payout/balance`
- `POST /payout/internal-transfer`
- `POST /payout/self-withdrawal`

## Common Response Codes

- `200 OK`: success or idempotent replay response
- `201 Created`: resource created (example: V2 beneficiary create)
- `202 Accepted`: async transfer/batch accepted
- `409 Conflict`: duplicate `transfer_id` with different payload
- `422 Unprocessable Entity`: validation error
- `500 Internal Server Error`: backend/external gateway failure

## Status Values

- Payout status: `INITIATED`, `PENDING`, `PROCESSING`, `SUCCESS`, `FAILED`, `REVERSED`
- Batch status follows same lifecycle logic.
- Terminal statuses: `SUCCESS`, `FAILED`, `REVERSED`

## 1) Legacy APIs

### 1.1 Add Beneficiary (Legacy)

- Method: `POST`
- URL: `/payout/beneficiary`

Request:

```json
{
  "bene_id": "USER_1001",
  "name": "John Doe",
  "email": "john@test.com",
  "phone": "9876543210",
  "bank_account": "00111122233",
  "ifsc": "HDFC0000001"
}
```

Validation:

- `bene_id`: required, unique
- `name`: required
- `email`: nullable, valid email
- `phone`: required
- `bank_account`: required
- `ifsc`: required

Success response (example):

```json
{
  "message": "Beneficiary added successfully",
  "data": {}
}
```

### 1.2 Request Payout (Legacy Async)

- Method: `POST`
- URL: `/payout/request`

Request:

```json
{
  "transfer_id": "TRF_ORDER_1001",
  "bene_id": "USER_1001",
  "amount": 10
}
```

Validation:

- `transfer_id`: required, max 100
- `bene_id`: required, must exist in `beneficiaries` table
- `amount`: required, numeric, minimum `1`

Behavior:

- Idempotent by `transfer_id`
- Same `transfer_id` + same payload -> returns existing payout (`200`)
- Same `transfer_id` + different payload -> `409`

Accepted response (example):

```json
{
  "message": "Payout request accepted",
  "transfer_id": "TRF_ORDER_1001",
  "status": "PENDING",
  "reference_id": "..."
}
```

## 2) Transfer APIs (V2 and Alias)

Use either:

- Primary: `/payout/v2/transfer`, `/payout/v2/transfer/status`
- Alias: `/payout/transfer`, `/payout/transfer/status`

### 2.1 Send Single Payment

- Method: `POST`
- URL: `/payout/v2/transfer` or `/payout/transfer`

Request:

```json
{
  "transfer_id": "TRF_V2_1001",
  "bene_id": "USER_1001",
  "amount": 100,
  "currency": "INR",
  "mode": "banktransfer",
  "remarks": "Vendor payout"
}
```

Validation:

- `transfer_id`: required, max 100
- `bene_id`: required, exists in beneficiaries
- `amount`: required, numeric, minimum `1`
- `currency`: optional, 3 chars (`INR`)
- `mode`: optional, max 50
- `remarks`: optional, max 255

Accepted response (example):

```json
{
  "message": "Transfer accepted",
  "transfer_id": "TRF_V2_1001",
  "status": "PENDING",
  "reference_id": "CF_TRF_..."
}
```

### 2.2 Check Particular Transaction by Transaction ID

- Method: `GET`
- URL: `/payout/v2/transfer/status?transfer_id=TRF_V2_1001`
- Alias URL: `/payout/transfer/status?transfer_id=TRF_V2_1001`

Query param:

- `transfer_id` (required)

Success response (example):

```json
{
  "data": {
    "status": "SUCCESS",
    "cf_transfer_id": "CF_TRF_..."
  }
}
```

## 3) Batch APIs (V2 and Alias)

Use either:

- Primary: `/payout/v2/batch-transfer`, `/payout/v2/batch-transfer/status`
- Alias: `/payout/batch-transfer`, `/payout/batch-transfer/status`

### 3.1 Send Bulk Payment

- Method: `POST`
- URL: `/payout/v2/batch-transfer` or `/payout/batch-transfer`

Request:

```json
{
  "batch_transfer_id": "BATCH_1001",
  "transfers": [
    {
      "transfer_id": "TRF_B1",
      "bene_id": "USER_1001",
      "amount": 10,
      "mode": "banktransfer",
      "remarks": "Payout 1"
    },
    {
      "transfer_id": "TRF_B2",
      "bene_id": "USER_1002",
      "amount": 20
    }
  ]
}
```

Validation:

- `batch_transfer_id`: required, max 100
- `transfers`: required array, at least 1 item
- `transfers.*.transfer_id`: required
- `transfers.*.bene_id`: required, exists in beneficiaries
- `transfers.*.amount`: required, numeric, minimum `1`
- `transfers.*.mode`: optional
- `transfers.*.remarks`: optional

Accepted response (example):

```json
{
  "message": "Batch transfer accepted",
  "batch_transfer_id": "BATCH_1001",
  "status": "PENDING",
  "reference_id": "CF_BATCH_..."
}
```

### 3.2 Get Batch Transfer Status

- Method: `GET`
- URL: `/payout/v2/batch-transfer/status?batch_transfer_id=BATCH_1001`
- Alias URL: `/payout/batch-transfer/status?batch_transfer_id=BATCH_1001`

Query param:

- `batch_transfer_id` (required)

Success response (example):

```json
{
  "data": {
    "status": "SUCCESS",
    "cf_batch_transfer_id": "CF_BATCH_..."
  }
}
```

## 4) Beneficiary APIs (V2)

### 4.1 Create Beneficiary

- Method: `POST`
- URL: `/payout/v2/beneficiary`

Request:

```json
{
  "bene_id": "USER_1001",
  "name": "John Doe",
  "email": "john@test.com",
  "phone": "9876543210",
  "bank_account": "00111122233",
  "ifsc": "HDFC0000001"
}
```

Success response (example):

```json
{
  "message": "Beneficiary V2 created",
  "data": {}
}
```

### 4.2 Get Beneficiary

- Method: `GET`
- URL: `/payout/v2/beneficiary?bene_id=USER_1001`

Query param:

- `bene_id` (required)

Success response:

```json
{
  "data": {}
}
```

### 4.3 Remove Beneficiary

- Method: `DELETE`
- URL: `/payout/v2/beneficiary?bene_id=USER_1001`

Query param:

- `bene_id` (required)

Success response:

```json
{
  "message": "Beneficiary V2 removed",
  "data": {}
}
```

## 5) Wallet APIs

### 5.1 Get Balance

- Method: `GET`
- URL: `/payout/balance`

Success response:

```json
{
  "data": {}
}
```

### 5.2 Internal Transfer

- Method: `POST`
- URL: `/payout/internal-transfer`

Request:

```json
{
  "operation_id": "INT_1001",
  "amount": 100,
  "from": "PRIMARY",
  "to": "PAYOUT",
  "remarks": "Fund allocation"
}
```

Validation:

- `operation_id`: optional, autogenerated if missing
- `amount`: required, numeric, minimum `1`
- `from`: required
- `to`: required
- `remarks`: optional

Success response:

```json
{
  "message": "Internal transfer completed",
  "operation_id": "INT_1001",
  "status": "SUCCESS",
  "data": {}
}
```

### 5.3 Self Withdrawal

- Method: `POST`
- URL: `/payout/self-withdrawal`

Request:

```json
{
  "operation_id": "SW_1001",
  "amount": 50,
  "remarks": "Withdraw to bank"
}
```

Validation:

- `operation_id`: optional, autogenerated if missing
- `amount`: required, numeric, minimum `1`
- `remarks`: optional

Success response:

```json
{
  "message": "Self withdrawal completed",
  "operation_id": "SW_1001",
  "status": "SUCCESS",
  "data": {}
}
```

## 6) Postman Step-by-Step Status Check Flow

1. Create beneficiary using `POST /payout/v2/beneficiary`.
2. Send single payment using `POST /payout/transfer` (or `/payout/v2/transfer`).
3. Copy the same `transfer_id`.
4. Check transaction status with `GET /payout/transfer/status?transfer_id=...`.
5. Send bulk payment using `POST /payout/batch-transfer` (or `/payout/v2/batch-transfer`).
6. Copy `batch_transfer_id`.
7. Check bulk status with `GET /payout/batch-transfer/status?batch_transfer_id=...`.
8. Check wallet APIs: `GET /payout/balance`, then internal transfer and self-withdrawal.

## 7) Error Troubleshooting

### 422 Validation Error

- Missing required fields
- `bene_id` not present in DB for transfer/batch

### 409 Conflict

- Reused `transfer_id` with different payload in legacy payout flow

### 500 Server Error

- Invalid Cashfree credentials
- Cashfree API unreachable
- Missing `.env` values
- Gateway rejection payload from Cashfree

Useful command:

```bash
php artisan route:list --path=api/payout
```
