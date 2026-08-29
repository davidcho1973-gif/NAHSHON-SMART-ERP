<?php

namespace App\Console\Commands;

use App\Models\IntelligentDocument;
use App\Services\Documents\KnowledgeKeeper;
use Illuminate\Console\Command;

/**
 * 지식 창고 일괄 수확 — 이미 분석이 끝난 문서들의 key_facts 를 지식 카드로 축적한다.
 *
 * 새 문서는 분석이 끝날 때 자동으로 수확되지만, 이 기능이 생기기 전에 분석된
 * 문서들은 이 커맨드로 한 번 밀어 넣는다. 문서 단위 멱등이라 몇 번을 돌려도 안전하다.
 */
class HarvestKnowledge extends Command
{
    protected $signature = 'erp:harvest-knowledge
        {--limit=0 : 0 이면 전부}
        {--force : 이미 최신인 문서도 다시 수확(재임베딩)}';

    protected $description = '분석 완료 문서의 key_facts 를 지식 창고로 일괄 수확 (임베딩 포함)';

    public function handle(KnowledgeKeeper $keeper): int
    {
        $query = IntelligentDocument::query()
            ->whereIn('ai_status', ['ready', 'review_required'])
            ->whereNotNull('key_facts')
            ->orderBy('id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $docs = 0;
        $cards = 0;
        $skipped = 0;
        $failed = 0;
        $force = (bool) $this->option('force');

        foreach ($query->cursor() as $document) {
            try {
                // 이미 최신인 문서는 건너뛴다 — 매일 스케줄로 돌아도 임베딩 호출이 늘지 않는다.
                if (! $force && $keeper->isFresh($document)) {
                    $skipped++;

                    continue;
                }
                $n = $keeper->harvest($document);
                $docs++;
                $cards += $n;
                $this->line(sprintf('#%d %s → 카드 %d장', $document->id, mb_substr((string) ($document->title ?: $document->original_file_name), 0, 50), $n));
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("#{$document->id} 실패: ".mb_substr($e->getMessage(), 0, 120));
            }
        }

        $this->info("수확 완료 — 문서 {$docs}건 · 지식 카드 {$cards}장 · 최신이라 건너뜀 {$skipped}건 · 실패 {$failed}건");

        return self::SUCCESS;
    }
}
