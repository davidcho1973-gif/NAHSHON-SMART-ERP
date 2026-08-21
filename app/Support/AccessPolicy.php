<?php

namespace App\Support;

use App\Models\User;

/**
 * 누가 무엇을 할 수 있는가 — 권한 규칙이 모이는 한 곳.
 *
 * 지금까지 역할 목록이 코드 곳곳에 복사돼 있었다. `['super_admin', 'admin',
 * 'hr_manager', 'payroll']` 이라는 같은 배열이 열한 군데에 있었고, 새 역할을 만들거나
 * 권한을 조정하려면 열한 군데를 찾아 고쳐야 했다 — 한 군데를 빠뜨리면 그 화면만
 * 조용히 다르게 동작한다.
 *
 * 그래서 <b>역할 목록이 아니라 "할 수 있는 일"</b> 로 묻게 한다:
 * canManageMoney(), canManagePeople(), … 화면과 서비스는 역할 이름을 몰라도 된다.
 *
 * ── 회사 격리 ─────────────────────────────────────────────────────────
 *
 * 협력사 관리자(vendor_admin)는 <b>자기 회사 사람만</b> 볼 수 있어야 한다. 남의 회사
 * 인건비·인원이 보이면 그것만으로 큰 문제가 된다. 그런데 예전 규칙은 access_scope 가
 * 'all_sites' 이면 무조건 통과였다 — 실수로 범위를 넓게 주는 순간 협력사 소장에게
 * 회사 전체가 열렸다.
 *
 * 여기서는 <b>역할이 범위를 이긴다.</b> 협력사 관리자는 범위를 아무리 넓게 줘도
 * 자기 회사 밖은 못 본다.
 */
final class AccessPolicy
{
    /** 회사 전체를 운영하는 사람 — 계정·권한까지 만질 수 있다. */
    public const SYSTEM_ROLES = ['super_admin', 'admin'];

    /** 돈을 다루는 사람 — 경비 승인·지급, 급여 실행. */
    public const MONEY_ROLES = ['super_admin', 'admin', 'hr_manager', 'payroll'];

    /** 사람을 등록·수정하는 사람. 회계는 여기 없다(등록과 지급을 나눈다). */
    public const PEOPLE_ROLES = ['super_admin', 'admin', 'hr_manager'];

    /** 현장을 운영하는 사람 — 출퇴근 수정, 문서, 공정 반영. */
    public const SITE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    /** 공지를 쓸 수 있는 사람. */
    public const ANNOUNCE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'];

    /** 자기 회사 울타리 밖으로 나갈 수 없는 역할. */
    public const COMPANY_LOCKED_ROLES = ['vendor_admin'];

    // ── 할 수 있는 일 ──────────────────────────────────────────────────

    public static function canManageSystem(?User $user): bool
    {
        return self::has($user, self::SYSTEM_ROLES);
    }

    /** 경비 승인·지급, 급여 실행. */
    public static function canManageMoney(?User $user): bool
    {
        return self::has($user, self::MONEY_ROLES);
    }

    /** 직원 등록·수정. */
    public static function canManagePeople(?User $user): bool
    {
        return self::has($user, self::PEOPLE_ROLES);
    }

    /** 현장 운영 — 출퇴근 수정, 문서함, 공정·조달 반영. */
    public static function canManageSite(?User $user): bool
    {
        return self::has($user, self::SITE_ROLES);
    }

    public static function canAnnounce(?User $user): bool
    {
        return self::has($user, self::ANNOUNCE_ROLES);
    }

    // ── 회사 격리 ──────────────────────────────────────────────────────

    /**
     * 이 사람이 갇혀 있는 회사. null 이면 회사 제한이 없다.
     *
     * 협력사 관리자에게만 걸린다 — 범위(access_scope)를 넓게 줘도 여기서 막힌다.
     * 소속 회사를 알 수 없으면 <b>아무것도 안 보이게</b> 한다(0). 모르는 채로 열어
     * 두는 것보다 안 보이는 편이 낫다.
     */
    public static function lockedCompanyId(?User $user): ?int
    {
        if (! $user || ! in_array($user->access_role, self::COMPANY_LOCKED_ROLES, true)) {
            return null;
        }

        return (int) ($user->allowed_company_id ?: $user->employee?->company_id ?: 0);
    }

    /**
     * 회사 울타리를 질의에 적용한다. 갇힌 사람이 아니면 아무것도 하지 않는다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyCompanyLock($query, ?User $user, string $column = 'company_id'): void
    {
        $companyId = self::lockedCompanyId($user);

        if ($companyId === null) {
            return;
        }

        if ($companyId === 0) {
            $query->whereRaw('1 = 0');   // 소속을 모르는 협력사 계정 — 열어 두지 않는다.

            return;
        }

        $query->where($column, $companyId);
    }

    /** 이 사람이 저 회사를 볼 수 있는가. */
    public static function canSeeCompany(?User $user, ?int $companyId): bool
    {
        $locked = self::lockedCompanyId($user);

        if ($locked === null) {
            return true;
        }

        return $locked !== 0 && $companyId !== null && $locked === (int) $companyId;
    }

    private static function has(?User $user, array $roles): bool
    {
        return $user !== null && in_array($user->access_role, $roles, true);
    }
}
