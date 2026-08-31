<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Site;
use App\Models\User;

/**
 * 입력·조회 화면의 기본 소속 — "누구의 눈으로 시작하는가" 를 한 곳에서 정한다.
 *
 * 현장 사람은 자기 현장·프로젝트가 이미 골라져 있어야 한다(매번 고르게 하면
 * 언젠가 남의 현장에 올린다). 반대로 전체를 보는 사람(수퍼관리자·고위관리자·
 * 회계)은 Global 이 기본이다 — 이들의 입력은 특정 현장 소속이 아닐 때가 많고,
 * 자동으로 아무 현장에나 앉히면 그게 더 큰 오류다.
 *
 * 우선순위는 화면이 지킨다: 사람이 방금 고른 값(요청 파라미터) > 이 기본값.
 */
class DefaultScope
{
    /** 전체가 기본인 역할 — 수퍼관리자 · 고위관리자 · 회계관리자. */
    public const GLOBAL_ROLES = ['super_admin', 'admin', 'payroll'];

    public static function isGlobal(?User $user): bool
    {
        return $user !== null && in_array($user->access_role, self::GLOBAL_ROLES, true);
    }

    /** 이 사람의 기본 현장 — Global 역할이거나 소속이 없으면 null(전체). */
    public static function siteId(?User $user): ?int
    {
        if ($user === null || self::isGlobal($user)) {
            return null;
        }

        $siteId = $user->allowed_site_id ?: $user->employee?->site_id;

        return $siteId ? (int) $siteId : null;
    }

    /** 기본 현장의 회사 — 현장이 없으면 본인 허용 회사. */
    public static function companyId(?User $user): ?int
    {
        if ($user === null || self::isGlobal($user)) {
            return null;
        }

        $siteId = self::siteId($user);
        if ($siteId) {
            $companyId = Site::query()->whereKey($siteId)->value('company_id');
            if ($companyId) {
                return (int) $companyId;
            }
        }

        return $user->allowed_company_id ? (int) $user->allowed_company_id : null;
    }

    /**
     * 기본 프로젝트 — 그 현장의 프로젝트가 <b>정확히 하나</b>일 때만.
     * 여럿이면 짐작하지 않는다(영수증·급여 커넥터와 같은 규칙).
     */
    public static function projectId(?User $user, ?int $siteId = null): ?int
    {
        $siteId ??= self::siteId($user);
        if (! $siteId) {
            return null;
        }

        $ids = Project::query()->where('site_id', $siteId)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
