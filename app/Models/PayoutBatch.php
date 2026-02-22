<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutBatch extends Model
{
    protected $fillable = [
        'batch_transfer_id',
        'reference_id',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
    ];
}
