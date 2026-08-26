<?php

namespace App\Services\Finance;

use App\Models\Project;
use App\Models\Site;

/**
 * 영수증의 현장 힌트 → 현장·프로젝트 귀속.
 *
 * 수기 메모의 "HFF 현장" 한 줄은 지금까지 글자로만 남았다. 이 해석기가 그 글자를
 * 현장 코드·이름과 대조해, 확실할 때만 경비를 그 현장(과 그 현장의 유일한
 * 프로젝트)에 귀속시킨다 — 원가 대조(계획 vs 실적)의 정확도가 여기서 올라간다.
 *
 * 원칙: 확실할 때만. 코드 정확 일치가 최우선, 이름은 유일하게 걸릴 때만.
 * 애매하면 null — 틀린 현장에 앉은 경비는 없는 귀속보다 나쁘다.
 */
class ReceiptHintResolver
{
    /**
     * @return array{site_id: int, project_id: ?int, matched: string}|null
     */
    public function resolve(?string $siteHint, ?string $handwrittenNotes = null): ?array
    {
        $site = $this->siteFor(trim((string) $siteHint))
            ?? $this->siteFor(trim((string) $handwrittenNotes));

        if ($site === null) {
            return null;
        }

        // 그 현장의 프로젝트가 정확히 하나면 프로젝트까지 귀속(급여 커넥터와 같은 규칙).
        $projectIds = Project::query()->where('site_id', $site->id)->limit(2)->pluck('id');

        return [
            'site_id' => (int) $site->id,
            'project_id' => $projectIds->count() === 1 ? (int) $projectIds->first() : null,
            'matched' => (string) $site->code,
        ];
    }

    private function siteFor(string $text): ?Site
    {
        if ($text === '') {
            return null;
        }
        $upper = mb_strtoupper($text);

        $sites = Site::query()->where('status', 'active')->get(['id', 'code', 'name']);

        // 1. 코드가 글자 속에 통째로 들어 있는가 — "HFF-02 현장" → HFF-02.
        //    코드끼리 겹치면(HFF, HFF-02) 긴 코드가 이긴다.
        $byCode = $sites
            ->filter(fn (Site $s): bool => $s->code !== '' && str_contains($upper, mb_strtoupper((string) $s->code)))
            ->sortByDesc(fn (Site $s): int => mb_strlen((string) $s->code));
        if ($byCode->isNotEmpty()) {
            return $byCode->first();
        }

        // 2. 현장 이름이 들어 있는가 — 유일할 때만.
        $byName = $sites->filter(fn (Site $s): bool => trim((string) $s->name) !== ''
            && mb_stripos($text, trim((string) $s->name)) !== false);

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
