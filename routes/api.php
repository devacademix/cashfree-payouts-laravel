<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PayoutController;

Route::post('/payout/beneficiary', [PayoutController::class, 'addBeneficiary']);
Route::post('/payout/request', [PayoutController::class, 'requestPayout']);

// Cashfree Payout V2
Route::post('/payout/v2/transfer', [PayoutController::class, 'requestTransferV2']);
Route::get('/payout/v2/transfer/status', [PayoutController::class, 'getTransferStatusV2']);
Route::post('/payout/v2/batch-transfer', [PayoutController::class, 'batchTransferV2']);
Route::get('/payout/v2/batch-transfer/status', [PayoutController::class, 'getBatchTransferStatusV2']);
Route::post('/payout/v2/beneficiary', [PayoutController::class, 'createBeneficiaryV2']);
Route::get('/payout/v2/beneficiary', [PayoutController::class, 'getBeneficiaryV2']);
Route::delete('/payout/v2/beneficiary', [PayoutController::class, 'removeBeneficiaryV2']);

// Convenience aliases for V2 transfer endpoints used by some clients.
Route::post('/payout/transfer', [PayoutController::class, 'requestTransferV2']);
Route::get('/payout/transfer/status', [PayoutController::class, 'getTransferStatusV2']);
Route::post('/payout/batch-transfer', [PayoutController::class, 'batchTransferV2']);
Route::get('/payout/batch-transfer/status', [PayoutController::class, 'getBatchTransferStatusV2']);

// Wallet operations
Route::get('/payout/balance', [PayoutController::class, 'getBalance']);
Route::post('/payout/internal-transfer', [PayoutController::class, 'internalTransfer']);
Route::post('/payout/self-withdrawal', [PayoutController::class, 'selfWithdrawal']);
