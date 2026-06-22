<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePreApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'employee_id',
        'title',
        'description',
        'justification',
        'estimated_amount',
        'planned_date',
        'payment_method',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'planned_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
