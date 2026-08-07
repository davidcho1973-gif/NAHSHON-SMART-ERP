<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'company_id',
        'site_id',
        'pay_type',
        'base_rate',
        'overtime_multiplier',
        'trade',
        'worker_division',
        'is_exempt',
        'is_dispatched',
        'visa_type',
        'pay_currency',
        'per_diem_rate',
        'fed_filing_status',
        'withholding_state',
        'retirement_pct',
        'effective_from',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'base_rate' => 'decimal:4',
            'overtime_multiplier' => 'decimal:2',
            'is_exempt' => 'boolean',
            'is_dispatched' => 'boolean',
            'per_diem_rate' => 'decimal:2',
            'retirement_pct' => 'decimal:2',
            'effective_from' => 'date',
            'payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
