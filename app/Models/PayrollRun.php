<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isDirty('status') && in_array($run->status, ['approved', 'paid'], true)
                && Employee::whereIn('id', $run->payslips()->select('employee_id'))
                    ->where('payload->self_registered_pending_hr', true)->exists()) {
                throw new \DomainException('인사 확인이 필요한 신규 직원이 있습니다. 직원 관리에서 확인 후 급여를 확정하세요.');
            }
        });
    }

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
