<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'period_start',
        'period_end',
        'pay_date',
        'site_scope',
        'status',
        'fx_rate_krw',
        'total_gross',
        'total_net',
        'headcount',
        'created_by_id',
        'calculated_at',
        'approved_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'fx_rate_krw' => 'decimal:4',
            'total_gross' => 'decimal:2',
            'total_net' => 'decimal:2',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
