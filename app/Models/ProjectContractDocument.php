<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProjectContractDocument extends Model
{
    use HasFactory;

    public const TYPE_OPTIONS = [
        'executed_contract' => 'Executed Contract / 서명 계약서',
        'amendment' => 'Amendment / 변경계약',
        'change_order' => 'Change Order / 변경지시',
        'scope_of_work' => 'Scope of Work / 공사범위',
        'purchase_order' => 'Purchase Order / PO',
        'notice_to_proceed' => 'Notice to Proceed / NTP',
        'certificate_of_insurance' => 'Certificate of Insurance / COI',
        'w9' => 'W-9',
        'bond' => 'Bond / 보증서',
        'safety_plan' => 'Safety Plan / 안전계획',
        'schedule' => 'Schedule / 공정표',
        'lien_waiver' => 'Lien Waiver / 유치권 포기서',
        'pay_application' => 'Pay Application / 기성청구',
        'correspondence' => 'Official Correspondence / 공문',
        'other' => 'Other / 기타',
    ];

    public const STATUS_OPTIONS = [
        'draft' => 'Draft / 초안',
        'under_review' => 'Under Review / 검토 중',
        'approved' => 'Approved / 승인',
        'expired' => 'Expired / 만료',
        'superseded' => 'Superseded / 대체됨',
        'rejected' => 'Rejected / 반려',
    ];

    protected $fillable = [
        'project_contract_id',
        'replaces_document_id',
        'uploaded_by',
        'reviewed_by',
        'document_type',
        'title',
        'document_number',
        'version',
        'status',
        'is_required',
        'is_current',
        'is_confidential',
        'issued_on',
        'effective_on',
        'expires_on',
        'reviewed_at',
        'disk',
        'file_path',
        'original_file_name',
        'mime_type',
        'file_size',
        'notes',
        'payload',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProjectContractDocument $document): void {
            $document->disk = $document->disk ?: (string) config('document-intelligence.disk', 'local');

            if (blank($document->file_path) || ! Storage::disk($document->disk)->exists($document->file_path)) {
                return;
            }

            $document->original_file_name = $document->original_file_name ?: basename($document->file_path);
            $document->file_size = Storage::disk($document->disk)->size($document->file_path);
            $document->mime_type = Storage::disk($document->disk)->mimeType($document->file_path) ?: 'application/octet-stream';
        });

        static::updated(function (ProjectContractDocument $document): void {
            if (! $document->wasChanged('file_path')) {
                return;
            }

            $oldPath = $document->getOriginal('file_path');
            $oldDisk = $document->getOriginal('disk') ?: (string) config('document-intelligence.disk', 'local');

            if (filled($oldPath) && $oldPath !== $document->file_path) {
                Storage::disk($oldDisk)->delete($oldPath);
            }
        });

        static::deleting(function (ProjectContractDocument $document): void {
            if (filled($document->file_path)) {
                Storage::disk($document->disk ?: (string) config('document-intelligence.disk', 'local'))->delete($document->file_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_current' => 'boolean',
            'is_confidential' => 'boolean',
            'issued_on' => 'date',
            'effective_on' => 'date',
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    public function replacesDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function downloadName(): string
    {
        return $this->original_file_name ?: ($this->title.'-'.$this->version);
    }
}
