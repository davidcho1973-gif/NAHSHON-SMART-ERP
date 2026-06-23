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
        'payment_type',
        'category',
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
