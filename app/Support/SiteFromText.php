<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * 글자 한 줄에서 현장을 찾아낸다 — 확실할 때만.
 *
 * 영수증의 "HFF 현장" 메모와 문서함의 "HFF-02 RFI-023.pdf" 파일명은 같은 문제다:
 * 사람이 적어 둔 글자에 현장이 들어 있는데 시스템은 그것을 모른다. 판단 규칙이
 * 두 곳에 따로 있으면 한쪽만 고쳐지고 결과가 갈리므로 여기 한 벌만 둔다.
 *
 * 원칙: 코드 정확 일치가 최우선(겹치면 긴 코드가 이긴다), 이름은 유일하게 걸릴 때만.
 * 애매하면 null — 틀린 현장에 붙은 귀속은 없는 귀속보다 나쁘다.
 */
class SiteFromText
{
    /**
     * @param  Collection<int, Site>|null  $sites  미리 읽어 둔 현장 목록(여러 건을 훑을 때 재사용)
     */
    public static function match(?string $text, ?Collection $sites = null): ?Site
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $sites ??= self::sites();
        $upper = mb_strtoupper($text);

        // 1. 코드가 글자 속에 통째로 들어 있는가 — "HFF-02 현장" → HFF-02.
        //    코드끼리 겹치면(HFF, HFF-02) 긴 코드가 이긴다.
        $byCode = $sites
            ->filter(fn (Site $s): bool => trim((string) $s->code) !== ''
                && str_contains($upper, mb_strtoupper(trim((string) $s->code))))
            ->sortByDesc(fn (Site $s): int => mb_strlen(trim((string) $s->code)));
        if ($byCode->isNotEmpty()) {
            return $byCode->first();
        }

        // 2. 현장 이름이 들어 있는가 — 유일할 때만.
        $byName = $sites->filter(fn (Site $s): bool => trim((string) $s->name) !== ''
            && mb_stripos($text, trim((string) $s->name)) !== false);

        return $byName->count() === 1 ? $byName->first() : null;
    }

    /** @return Collection<int, Site> */
    public static function sites(): Collection
    {
        return Site::query()->where('status', 'active')->get(['id', 'code', 'name', 'company_id']);
    }
}
