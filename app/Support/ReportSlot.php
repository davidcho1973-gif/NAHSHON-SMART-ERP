<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * 일일보고에서 이 사람의 «자리» — 공종이 있으면 공종, 없으면 직책이 정한다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 일일보고는 쓰는 곳이 아니라 <b>모이는 곳</b>이다. 각 관리자가 자기 앱에서 하루 일을
 * 올리면 그날 한 장의 보고에 모이고, 종합 분석돼 마감 보고서가 된다. 그런데 자리를
 * «현장 + 공종» 으로만 잡아 두어서, 공종이 없는 사람 — 사무·안전·현장소장·공무 — 은
 * 앱에서 무엇을 올려도 묶일 자리가 없었다. 그 사람들의 하루(서류 제출·발주·청구·
 * 인허가·안전 점검)가 보고서에서 통째로 빠졌다.
 *
 * ── 규칙 ─────────────────────────────────────────────────────────────
 *  1. 공종(employees.role)이 있으면 그것이 자리다. 반장·기사가 여기 든다.
 *  2. 공종이 없으면 직책(position)이 정한다 — 사무·안전·현장관리·공무.
 *  3. 직책도 없는데 관리직(employment_type=staff)이면 «사무» 로 본다.
 *  4. 원청 소속은 우리 자리가 아니다. 공종도 직책도 없는 작업자도 자리가 없다 —
 *     그 사람의 일은 반장의 보고에 실린다.
 *
 * 자리의 «열쇠(key)» 는 daily_trade_reports.trade 칸에 그대로 들어간다. 공종 이름과
 * 부서 이름이 한 칸을 나눠 쓰지만, 종류(kind)가 함께 적히므로 현황판과 보고서는
 * 둘을 갈라 보여 줄 수 있다.
 */
final class ReportSlot
{
    public const KIND_TRADE = 'trade';

    public const KIND_OFFICE = 'office';

    /**
     * 공종이 없는 직책의 자리 — 직책 코드 → 부서 이름. 순서가 현황판의 순서다.
     *
     * 반장(foreman)과 작업자(worker)는 여기 없다. 그들은 공종이 있어야 하고, 없으면
     * 직원 정보가 덜 된 것이다 — 자리를 만들어 주면 그 빈칸이 영원히 안 채워진다.
     */
    public const OFFICE_SLOTS = [
        'office' => '사무',
        'safety' => '안전',
        'superintendent' => '현장관리',
        'engineer' => '공무',
    ];

    /**
     * @return array{key: string, kind: string, label: string}|null
     */
    public static function of(?Employee $employee): ?array
    {
        if (! $employee || $employee->employment_type === Employee::TYPE_CLIENT) {
            return null;
        }

        $trade = trim((string) ($employee->role ?? ''));
        if ($trade !== '') {
            return ['key' => $trade, 'kind' => self::KIND_TRADE, 'label' => $trade];
        }

        $position = trim((string) ($employee->position ?? ''));
        $department = self::OFFICE_SLOTS[$position] ?? null;

        if ($department === null && $position === '' && $employee->employment_type === Employee::TYPE_STAFF) {
            $department = self::OFFICE_SLOTS['office'];
        }

        if ($department === null) {
            return null;
        }

        return ['key' => $department, 'kind' => self::KIND_OFFICE, 'label' => $department];
    }

    public static function keyOf(?Employee $employee): ?string
    {
        return self::of($employee)['key'] ?? null;
    }

    /** 이 열쇠가 부서 자리인가(공종이 아니라). */
    public static function isOffice(string $key): bool
    {
        return in_array($key, self::OFFICE_SLOTS, true);
    }

    public static function kindOf(string $key): string
    {
        return self::isOffice($key) ? self::KIND_OFFICE : self::KIND_TRADE;
    }

    /**
     * 직원들 중 이 자리에 드는 사람만.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    public static function filter(Collection $employees, string $key): Collection
    {
        return $employees->filter(fn (Employee $e): bool => self::keyOf($e) === $key)->values();
    }

    /**
     * 직원들의 자리 열쇠 — 공종이 먼저(가나다), 부서가 뒤(정해진 순서).
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, string>
     */
    public static function keysOf(Collection $employees): array
    {
        $keys = $employees->map(fn (Employee $e): ?string => self::keyOf($e))->filter()->unique();

        return self::sort($keys->all());
    }

    /**
     * 현황판 순서 — 공종(가나다) 다음에 부서(사무·안전·현장관리·공무).
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function sort(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $trades = array_filter($keys, fn (string $k): bool => ! self::isOffice($k));
        sort($trades);
        $offices = array_values(array_filter(
            array_values(self::OFFICE_SLOTS),
            fn (string $dept): bool => in_array($dept, $keys, true),
        ));

        return array_merge(array_values($trades), $offices);
    }
}
