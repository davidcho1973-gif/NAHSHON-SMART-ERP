<?php

namespace App\Services\Documents;

use App\Models\IntelligentDocument;
use App\Models\KnowledgeFact;
use App\Models\Site;
use App\Models\User;
use App\Support\AccessPolicy;
use App\Support\GeminiEmbedder;
use App\Support\SensitiveDocuments;

/**
 * 지식 창고지기 — 문서 분석의 결과를 축적하고, 개정되면 은퇴시키고, 질문이 오면 찾아 준다.
 *
 * 문서가 늘수록 이 창고가 저절로 두꺼워진다. 채팅 AI 는 어떤 질문이든 문서 원문을
 * 뒤지기 전에 여기부터 본다 — 카드는 작고 밀도가 높아서 답이 빨리 나온다.
 *
 * ── 개정 규칙 ──────────────────────────────────────────────────────────
 * 같은 회사·현장에서 문서번호가 같은 이전 문서가 있으면, 그 문서의 살아있는 카드를
 * 전부 은퇴시킨다. 은퇴는 삭제가 아니다 — retired_at 만 찍는다. "그때 왜 그렇게
 * 답했나" 를 따질 때 은퇴 카드가 증거가 된다.
 */
class KnowledgeKeeper
{
    /**
     * 돈·개인정보가 실리는 문서 종류 — 이 문서에서 나온 지식 카드는
     * 재무 권한자(canManageMoney)에게만 검색된다. 영수증 금액과 급여 내역이
     * 대화방에서 아무에게나 새어 나가면 채팅이 재무 화면의 뒷문이 된다.
     */
    /**
     * 목록의 정본은 App\Support\SensitiveDocuments 에 있다.
     *
     * 예전에는 이 목록이 여기에만 있었고, 그래서 채팅 AI 는 급여를 인용하지 않는데
     * 문서함 검색은 같은 문서의 본문을 그대로 돌려주고 있었다(열람 전용 원청 계정으로
     * 재현됨). 규칙이 한 곳에만 있으면 다른 곳은 규칙이 없는 것과 같다.
     */
    private const MONEY_DOC_TYPES = SensitiveDocuments::MONEY_TYPES;

    /**
     * 창고가 준비됐는가 — 마이그레이션 전 배포 순서 꼬임에도 채팅이 죽지 않게.
     * 스키마 조회는 요청당 한 번이면 충분하다.
     */
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= \Illuminate\Support\Facades\Schema::hasTable('knowledge_facts');
    }

    /** 이 문서의 카드가 최신인가 — 분석 이후에 수확된 카드가 있으면 다시 할 일이 없다. */
    public function isFresh(IntelligentDocument $document): bool
    {
        if (! self::ready() || $document->analyzed_at === null) {
            return false;
        }

        $newest = KnowledgeFact::query()
            ->where('intelligent_document_id', $document->id)
            ->max('created_at');

        return $newest !== null && \Illuminate\Support\Carbon::parse($newest)->gte($document->analyzed_at);
    }

    /** 분석이 끝난 문서에서 지식 카드를 수확한다. 재분석하면 그 문서 카드는 갈아엎는다. */
    public function harvest(IntelligentDocument $document): int
    {
        if (! self::ready()) {
            return 0;
        }

        $facts = array_values(array_filter(
            is_array($document->key_facts) ? $document->key_facts : [],
            fn ($f): bool => is_string($f) && mb_strlen(trim($f)) >= 5,
        ));

        $this->retireSuperseded($document);

        // 재분석 멱등 — 이 문서가 이미 심어 둔 카드는 지우고 새로 심는다.
        KnowledgeFact::query()->where('intelligent_document_id', $document->id)->delete();

        if ($facts === []) {
            return 0;
        }

        // 임베딩은 카드에 출처 맥락을 붙여 뽑는다 — "회신기한 10일" 만으로는
        // 무슨 문서의 10일인지 벡터도 모른다.
        $context = trim(($document->title ?: $document->original_file_name).' '.(string) $document->document_type);
        $embeddings = GeminiEmbedder::embedBatch(array_map(fn (string $f): string => $context.' — '.$f, $facts));

        $now = now();
        KnowledgeFact::query()->insert(array_map(fn (string $fact, int $i): array => [
            'company_id' => $document->company_id,
            'site_id' => $document->site_id,
            'project_id' => $document->project_id,
            'intelligent_document_id' => $document->id,
            'doc_title' => mb_substr((string) ($document->title ?: $document->original_file_name), 0, 250),
            'document_type' => $document->document_type,
            'document_number' => $document->document_number,
            'revision' => $document->revision,
            'document_date' => $document->document_date?->toDateString(),
            'fact' => $fact,
            'embedding' => $embeddings[$i] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $facts, array_keys($facts)));

        return count($facts);
    }

    /**
     * 지식 검색 — 키워드(글자)와 의미(벡터)를 섞는다.
     *
     * 키워드가 정확히 걸린 카드가 먼저, 나머지 자리는 뜻이 비슷한 카드가 채운다.
     * 권한은 화면과 같은 규칙(AccessPolicy) — 대화방이 뒷문이 되면 안 된다.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array<string, mixed>>
     */
    public function search(?Site $site, User $asker, array $terms, string $question, int $limit = 8): array
    {
        if (! self::ready()) {
            return [];
        }

        $base = KnowledgeFact::query()->active();
        if ($site) {
            $base->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($base, $asker);

        // 돈 문서 카드는 재무 권한자만 — 화면(money 토픽)과 같은 선을 지킨다.
        if (! AccessPolicy::canManageMoney($asker)) {
            $base->where(function ($q): void {
                $q->whereNull('document_type')->orWhereNotIn('document_type', self::MONEY_DOC_TYPES);
            });
        }

        // 기밀 표시 문서의 카드는 시스템 관리자만 — 출처 문서의 등급을 따라간다.
        if (! AccessPolicy::canManageSystem($asker)) {
            $base->whereHas('document', function ($q): void {
                $q->whereIn('confidentiality', ['public', 'internal']);
            });
        }

        if ((clone $base)->count() === 0) {
            return [];
        }

        $picked = collect();

        if ($terms !== []) {
            $picked = (clone $base)
                ->where(function ($q) use ($terms): void {
                    foreach ($terms as $t) {
                        $q->orWhere('fact', 'ilike', "%{$t}%")
                            ->orWhere('doc_title', 'ilike', "%{$t}%");
                    }
                })
                ->latest('document_date')
                ->limit($limit)
                ->get();
        }

        // 의미 검색 — 남는 자리를 뜻이 가까운 카드로 채운다.
        if ($picked->count() < $limit) {
            $queryVector = GeminiEmbedder::available() ? GeminiEmbedder::embed($question) : null;

            if ($queryVector !== null) {
                $seen = $picked->pluck('id')->all();
                $candidates = (clone $base)
                    ->whereNotNull('embedding')
                    ->whereNotIn('id', $seen)
                    ->latest('id')
                    ->limit(2000) // 카드 수천 장까지는 PHP 내적으로 충분 — 넘어가면 pgvector 로 옮긴다
                    ->get(['id', 'fact', 'doc_title', 'document_date', 'revision', 'embedding']);

                $semantic = $candidates
                    ->map(function (KnowledgeFact $f) use ($queryVector): array {
                        return ['row' => $f, 'score' => GeminiEmbedder::cosine((string) $f->embedding, $queryVector)];
                    })
                    ->filter(fn (array $x): bool => $x['score'] >= 0.5) // 억지로 끼워 맞춘 카드는 소음이다
                    ->sortByDesc('score')
                    ->take($limit - $picked->count())
                    ->map(fn (array $x) => $x['row']);

                $picked = $picked->concat($semantic);
            }
        }

        return $picked->map(fn (KnowledgeFact $f): array => array_filter([
            '사실' => $f->fact,
            '출처' => $f->doc_title,
            'Rev' => $f->revision,
            '문서일' => $f->document_date?->toDateString(),
        ]))->values()->all();
    }

    /** 같은 문서번호의 이전 문서 카드를 은퇴시킨다 — 옛 사양으로 답하는 사고를 막는다. */
    private function retireSuperseded(IntelligentDocument $document): void
    {
        $olderIds = collect();

        if (filled($document->document_number)) {
            $olderIds = IntelligentDocument::query()
                ->where('company_id', $document->company_id)
                ->where('document_number', $document->document_number)
                ->where('id', '<>', $document->id)
                ->when($document->site_id, fn ($q) => $q->where('site_id', $document->site_id))
                ->where('id', '<', $document->id)
                ->pluck('id');
        }

        if ($document->supersedes_document_id) {
            $olderIds->push($document->supersedes_document_id);
        }

        if ($olderIds->isEmpty()) {
            return;
        }

        KnowledgeFact::query()
            ->whereIn('intelligent_document_id', $olderIds->unique()->all())
            ->whereNull('retired_at')
            ->update(['retired_at' => now(), 'retired_by_document_id' => $document->id]);

        // 개정 사슬 기록 — 컬럼은 있었지만 아무도 안 쓰던 supersedes 를 살린다.
        if (! $document->supersedes_document_id) {
            $document->forceFill(['supersedes_document_id' => $olderIds->max()])->saveQuietly();
        }
    }
}
