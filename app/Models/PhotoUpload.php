<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PhotoUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'team_id',
        'uploaded_by_id',
        'capture_type',
        'captured_at',
        'uploaded_at',
        'storage_disk',
        'storage_path',
        'public_url',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function ocrResult(): HasOne
    {
        return $this->hasOne(OcrResult::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
