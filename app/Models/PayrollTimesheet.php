<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollTimesheet extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'company_id',
        'site_id',
        'team_id',
        'work_date',
        'check_in_at',
        'check_out_at',
        'regular_minutes',
        'overtime_minutes',
        'payable_minutes',
        'status',
        'source',
        'approved_by_id',
        'approved_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
