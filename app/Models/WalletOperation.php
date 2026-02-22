<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletOperation extends Model
{
    public const TYPE_INTERNAL_TRANSFER = 'INTERNAL_TRANSFER';
    public const TYPE_SELF_WITHDRAWAL = 'SELF_WITHDRAWAL';

    protected $fillable = [
        'operation_id',
        'type',
        'amount',
        'status',
        'metadata',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'raw_response' => 'array',
    ];
}
