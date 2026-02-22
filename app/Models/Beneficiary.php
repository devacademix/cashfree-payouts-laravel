<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Beneficiary extends Model
{
    protected $fillable = [
        'bene_id',
        'name',
        'email',
        'phone',
        'bank_account',
        'ifsc',
        'status',
    ];
}