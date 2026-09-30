<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequestLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseReceiptAllocation::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(PurchaseRequestOrderLine::class);
    }
}
