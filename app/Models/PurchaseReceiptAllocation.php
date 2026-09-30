<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(MaterialReceiptLine::class, 'material_receipt_line_id');
    }
}
