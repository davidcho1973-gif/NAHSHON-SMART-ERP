<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'company_id',
        'snap_pay_type',
        'snap_base_rate',
        'snap_trade',
        'snap_division',
        'regular_hours',
        'overtime_hours',
        'doubletime_hours',
        'applied_rate',
        'gross_pay',
        'fringe_pay',
        'per_diem',
        'fed_tax',
        'state_tax',
        'fica',
        'medicare',
        'retirement_401k',
        'other_deduction',
        'net_pay',
        'currency',
        'open_days',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'snap_base_rate' => 'decimal:4',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'doubletime_hours' => 'decimal:2',
            'applied_rate' => 'decimal:4',
            'gross_pay' => 'decimal:2',
            'fringe_pay' => 'decimal:2',
            'per_diem' => 'decimal:2',
            'fed_tax' => 'decimal:2',
            'state_tax' => 'decimal:2',
            'fica' => 'decimal:2',
            'medicare' => 'decimal:2',
            'retirement_401k' => 'decimal:2',
            'other_deduction' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }
}
