# Cashfree Payouts API (Laravel)

Production-style Laravel API service for beneficiary management, single transfer, batch transfer, transfer status tracking, and wallet operations using Cashfree Payout APIs.

## Tech Stack

- PHP / Laravel
- MySQL or SQLite
- Cashfree Payout APIs (V1 + V2 flows)

## Project Structure

- Routes: `routes/api.php`
- Controller: `app/Http/Controllers/PayoutController.php`
- Cashfree service: `app/Services/CashfreePayoutService.php`
- API docs: `API_DOCS.md`

## Prerequisites

- PHP 8.2+
- Composer
- MySQL (or SQLite)
- Cashfree sandbox/production credentials

## Local Setup

1. Install dependencies:
```bash
composer install
```

2. Create env and app key:
```bash
cp .env.example .env
php artisan key:generate
```

3. Configure database in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

4. Configure Cashfree in `.env`:
```env
CASHFREE_PAYOUT_BASE_URL=https://sandbox.cashfree.com
CASHFREE_CLIENT_ID=your_client_id
CASHFREE_CLIENT_SECRET=your_client_secret
CASHFREE_WEBHOOK_SECRET=your_oldest_active_client_secret
CASHFREE_API_VERSION=2024-01-01
```

5. Run migrations:
```bash
php artisan migrate
```

6. Start server:
```bash
php artisan serve
```

Base URL:

`http://127.0.0.1:8000/api`

## API Headers

Use these headers for all API calls:

- `Content-Type: application/json`
- `Accept: application/json`

## Route List

Run:

```bash
php artisan route:list --path=api/payout
```

Current API routes:

- `POST /api/payout/beneficiary` (legacy create beneficiary)
- `POST /api/payout/request` (legacy async payout)
- `POST /api/payout/v2/transfer`
- `GET /api/payout/v2/transfer/status`
- `POST /api/payout/v2/batch-transfer`
- `GET /api/payout/v2/batch-transfer/status`
- `POST /api/payout/v2/beneficiary`
- `GET /api/payout/v2/beneficiary`
- `DELETE /api/payout/v2/beneficiary`
- `POST /api/payout/transfer` (alias of V2 transfer)
- `GET /api/payout/transfer/status` (alias of V2 status)
- `POST /api/payout/batch-transfer` (alias of V2 batch)
- `GET /api/payout/batch-transfer/status` (alias of V2 batch status)
- `POST /api/payout/webhook/v2` (Cashfree webhook callback)
- `GET /api/payout/balance`
- `POST /api/payout/internal-transfer`
- `POST /api/payout/self-withdrawal`

## Core Features

- Beneficiary creation/get/remove
- Single payout transfer
- Batch payout transfer
- Transfer and batch status checks
- Wallet balance
- Internal wallet transfer
- Self withdrawal
- Webhook V2 signature verification and event reconciliation
- Idempotency handling for transfer and batch operations

## Webhook V2

Endpoint:

- `POST /api/payout/webhook/v2`

Headers required from Cashfree:

- `x-webhook-signature`
- `x-webhook-timestamp`

Signature verification:

- Server computes `base64(HMAC_SHA256(timestamp + rawBody, CASHFREE_WEBHOOK_SECRET))`
- Uses constant-time compare with `x-webhook-signature`

Handled transfer events:

- `TRANSFER_ACKNOWLEDGED` -> `SUCCESS`
- `TRANSFER_SUCCESS` -> `SUCCESS`
- `TRANSFER_FAILED` -> `FAILED`
- `TRANSFER_REJECTED` -> `FAILED`
- `TRANSFER_REVERSED` -> `REVERSED`

Handled batch events:

- `BULK_TRANSFER_REJECTED` -> batch status `FAILED`

## API Details

Detailed endpoint request/response examples and validations are documented in:

- `API_DOCS.md`

## Postman Testing Flow (Recommended)

1. Create beneficiary:
- `POST /api/payout/v2/beneficiary`

2. Send single payment:
- `POST /api/payout/transfer`

3. Check single transaction status:
- `GET /api/payout/transfer/status?transfer_id=...`

4. Send bulk payment:
- `POST /api/payout/batch-transfer`

5. Check batch status:
- `GET /api/payout/batch-transfer/status?batch_transfer_id=...`

6. Wallet checks:
- `GET /api/payout/balance`
- `POST /api/payout/internal-transfer`
- `POST /api/payout/self-withdrawal`

## Idempotency Notes

- Single transfer APIs are idempotent by `transfer_id`.
- Batch transfer APIs are idempotent by `batch_transfer_id`.
- Reusing same IDs with same payload returns existing operation.
- Reusing transfer ID with different legacy payout payload can return `409`.

## Status Lifecycle

Payout statuses:

- `INITIATED`
- `PENDING`
- `PROCESSING`
- `SUCCESS`
- `FAILED`
- `REVERSED`

Terminal statuses:

- `SUCCESS`
- `FAILED`
- `REVERSED`

## Error Handling Guide

- `422`: validation failure (missing fields, invalid format, unknown `bene_id`)
- `409`: duplicate ID conflict with mismatched payload
- `500`: Cashfree credentials/config/network/external API issue

If you get `500`, verify:

- `CASHFREE_PAYOUT_BASE_URL`
- `CASHFREE_CLIENT_ID`
- `CASHFREE_CLIENT_SECRET`
- internet connectivity to Cashfree host

## Useful Commands

Run tests:

```bash
php artisan test
```

Clear caches:

```bash
php artisan optimize:clear
```

View logs:

```bash
tail -f storage/logs/laravel.log
```

## Deployment Notes

- Set `APP_ENV=production`
- Set `APP_DEBUG=false`
- Configure production Cashfree credentials and base URL
- Run `php artisan config:cache` and `php artisan route:cache`

## License

Project-specific license as defined by repository owner.
