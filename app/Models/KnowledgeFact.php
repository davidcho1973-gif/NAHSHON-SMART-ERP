<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 지식 카드 한 장 — 문서 분석이 남긴 "기억해야 하는 사실" 하나.
 *
 * 문서(intelligent_documents)의 key_facts 를 낱개로 꺼내 쌓은 것이다.
 * 개정 문서가 오면 retired_at 이 찍혀 은퇴한다 — 살아있는 카드만 답변에 쓴다.
 */
class KnowledgeFact extends Model
{
    protected $fillable = [
        'company_id', 'site_id', 'project_id', 'intelligent_document_id',
        'doc_title', 'document_type', 'document_number', 'revision', 'document_date',
        'fact', 'embedding', 'retired_at', 'retired_by_document_id',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'retired_at' => 'datetime',
        ];
    }

    /** 아직 유효한 지식 — 개정으로 은퇴하지 않은 것. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligentDocument::class, 'intelligent_document_id');
    }
}
