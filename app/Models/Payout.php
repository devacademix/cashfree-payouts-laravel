<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    public const STATUS_INITIATED = 'INITIATED';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REVERSED = 'REVERSED';

    protected $fillable = [
        'transfer_id',
        'bene_id',
        'reference_id',
        'amount',
        'currency',
        'mode',
        'status',
        'failure_reason',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_response' => 'array',
    ];

    /**
     * Non-terminal statuses that require reconciliation polling.
     */
    public static function trackableStatuses(): array
    {
        return [
            self::STATUS_INITIATED,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ];
    }

    /**
     * Terminal statuses that must never be mutated by polling.
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_REVERSED,
        ];
    }

    public function isTrackable(): bool
    {
        return in_array($this->status, self::trackableStatuses(), true);
    }
}
