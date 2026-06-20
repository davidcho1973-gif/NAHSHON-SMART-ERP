<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmartRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'module',
        'record_key',
        'name',
        'category',
        'site',
        'status',
        'amount',
        'occurred_on',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
            'payload' => 'array',
        ];
    }
}
