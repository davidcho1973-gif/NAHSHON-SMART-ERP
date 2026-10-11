<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ClaimSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'profile_version' => 'integer',
            'evidence' => 'array',
            'profile_snapshot' => 'array',
            'application_snapshot' => 'array',
            'manifest' => 'array',
            'response_document' => 'array',
            'history' => 'array',
            'file_size' => 'integer',
            'prepared_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'response_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $submission): void {
            // A new revision is a new packet; updating a live source never rewrites the sent record.
            $mutable = ['status', 'sent_at', 'received_at', 'response_at', 'reference', 'sent_reference', 'response_document', 'history', 'updated_at'];
            if (array_diff(array_keys($submission->getDirty()), $mutable)) {
                throw ValidationException::withMessages(['submission' => '준비된 제출본은 변경할 수 없습니다. 새 개정본을 만드세요.']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['submission' => '제출본과 처리 이력은 삭제할 수 없습니다.']);
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PayApplication::class, 'pay_application_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }
}
