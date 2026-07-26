<?php

namespace App\Services\Ops;

use App\Models\OpsIntakeItem;

/**
 * 상황실 학습 — 쓸수록 정확해지게 한다.
 *
 * 모델을 재훈련하는 게 아니라, **우리 현장에서 축적된 사실**을 매 판독 때 함께 넘긴다.
 *   1) 현장 용어사전 : 사람이 부르는 이름("천장배관") ↔ 실제 코드(A100) — 반영 성공에서 학습
 *   2) 교정 사례     : 사람이 무시/되돌린 판독 — 같은 실수를 반복하지 않게
 * 결과적으로 "점점 알아듣는" 효과가 난다.
 */
class OpsLearningService
{
    /** 프롬프트에 실을 최대 항목 수(너무 길면 판독 품질이 떨어진다). */
    private const GLOSSARY_LIMIT = 40;

    private const CORRECTION_LIMIT = 12;

    /**
     * 현장 용어사전 — 실제로 반영에 성공한 (이름 → 코드) 쌍을 빈도순으로.
     *
     * @return array<int, array{name: string, code: string, hits: int}>
     */
    public function glossary(?int $siteId = null): array
    {
        return OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('status', 'applied')
            ->whereNotNull('target_code')->whereNotNull('target_name')
            ->selectRaw('target_name, target_code, count(*) as hits')
            ->groupBy('target_name', 'target_code')
            ->orderByDesc('hits')
            ->limit(self::GLOSSARY_LIMIT)
            ->get()
            ->map(fn ($r) => [
                'name' => (string) $r->target_name,
                'code' => (string) $r->target_code,
                'hits' => (int) $r->hits,
            ])->all();
    }

    /**
     * 교정 사례 — 사람이 무시했거나 되돌린 판독(= AI 가 틀린 것).
     *
     * @return array<int, array{raw: string, wrong: string}>
     */
    public function corrections(?int $siteId = null): array
    {
        return OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('status', 'dismissed')
            ->whereNotNull('summary')
            ->latest()
            ->limit(self::CORRECTION_LIMIT)
            ->get()
            ->map(fn (OpsIntakeItem $i) => [
                'raw' => mb_substr((string) $i->raw_text, 0, 120),
                'wrong' => mb_substr((string) $i->summary, 0, 120),
            ])->all();
    }

    /**
     * 판독 프롬프트에 덧붙일 "우리 현장 지식" 블록. 학습된 게 없으면 빈 문자열.
     */
    public function promptBlock(?int $siteId = null): string
    {
        $glossary = $this->glossary($siteId);
        $corrections = $this->corrections($siteId);

        if ($glossary === [] && $corrections === []) {
            return '';
        }

        $out = "\n## 우리 현장에서 학습된 지식 (이전 판독 결과에서 축적됨 — 우선 참고하세요)\n";

        if ($glossary !== []) {
            $out .= "\n[현장 용어사전 — 사람들이 이렇게 부르면 이 코드입니다]\n";
            foreach ($glossary as $g) {
                $out .= sprintf("- \"%s\" → %s (과거 %d회 확인됨)\n", $g['name'], $g['code'], $g['hits']);
            }
        }

        if ($corrections !== []) {
            $out .= "\n[과거 오판 사례 — 같은 실수를 반복하지 마세요]\n";
            foreach ($corrections as $c) {
                $out .= sprintf("- 원문 \"%s\" 를 \"%s\" 로 읽었으나 관리자가 반려했습니다.\n", $c['raw'], $c['wrong']);
            }
        }

        return $out;
    }
}
