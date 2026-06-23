<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'employee_id',
        'expense_pre_approval_id',
        'payment_type',
        'category',
        'accounting_account',
        'class',
        'description',
        'amount',
        'expense_date',
        'receipt_path',
        'receipt_mime_type',
        'receipt_original_name',
        'receipt_file',
        'ocr_data',
        'status',
        'reviewed_at',
        'reviewed_by_user_id',
        'rejection_reason',
        'paid_at',
        'paid_by_user_id',
        'payment_reference',
    ];

    protected $hidden = [
        'receipt_file',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'ocr_data' => 'array',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function preApproval(): BelongsTo
    {
        return $this->belongsTo(ExpensePreApproval::class, 'expense_pre_approval_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
