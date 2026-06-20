<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_registration_id',
        'document_type',
        'title',
        'status',
        'issued_on',
        'expires_on',
        'file_path',
        'extracted_data',
        'review_notes',
        'verified_at',
        'verified_by_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
            'extracted_data' => 'array',
        ];
    }

    public function memberRegistration(): BelongsTo
    {
        return $this->belongsTo(MemberRegistration::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
