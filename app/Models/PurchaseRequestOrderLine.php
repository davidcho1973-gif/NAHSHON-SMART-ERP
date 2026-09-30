<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestOrderLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
