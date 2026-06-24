<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'payslip_id',
        'project_id',
        'site_id',
        'cost_code',
        'hour_type',
        'hours',
        'rate_applied',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'rate_applied' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
