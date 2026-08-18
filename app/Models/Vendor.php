<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'trade',
        'status',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 이 거래처로 나간 발주들. */
    public function procurementItems()
    {
        return $this->hasMany(ProcurementItem::class, 'vendor_id');
    }

    /** 이 거래처와 맺은 발주(payable) 계약들. */
    public function contracts()
    {
        return $this->hasMany(ProjectContract::class, 'counterparty_vendor_id');
    }
}
