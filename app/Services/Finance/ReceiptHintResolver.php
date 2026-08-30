<?php

namespace App\Services\Finance;

use App\Models\Project;
use App\Support\SiteFromText;

/**
 * 영수증의 현장 힌트 → 현장·프로젝트 귀속.
 *
 * 수기 메모의 "HFF 현장" 한 줄은 지금까지 글자로만 남았다. 이 해석기가 그 글자를
 * 현장 코드·이름과 대조해, 확실할 때만 경비를 그 현장(과 그 현장의 유일한
 * 프로젝트)에 귀속시킨다 — 원가 대조(계획 vs 실적)의 정확도가 여기서 올라간다.
 *
 * 원칙: 확실할 때만. 코드 정확 일치가 최우선, 이름은 유일하게 걸릴 때만.
 * 애매하면 null — 틀린 현장에 앉은 경비는 없는 귀속보다 나쁘다.
 *
 * 글자 → 현장 판단은 문서함의 "현장 미지정 일괄 정리"와 같은 규칙이어야 하므로
 * {@see SiteFromText} 한 곳에 두고 둘이 나눠 쓴다.
 */
class ReceiptHintResolver
{
    /**
     * @return array{site_id: int, project_id: ?int, matched: string}|null
     */
    public function resolve(?string $siteHint, ?string $handwrittenNotes = null): ?array
    {
        $sites = SiteFromText::sites();
        $site = SiteFromText::match($siteHint, $sites)
            ?? SiteFromText::match($handwrittenNotes, $sites);

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
}
