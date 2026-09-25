<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class WorkerPhone
{
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '';
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * 전화번호 <b>뒷 4자리</b>로 재직 중인 사람을 찾는다 — 현장 게이트와 작업자 앱이 같이 쓴다.
     *
     * 이 네 자리가 현장의 유일한 열쇠가 됐다(2026-09-23, PIN 폐지). 그래서 «어떻게
     * 비교하는가» 가 두 곳에 적혀 있으면 한쪽만 고쳐지는 순간 한쪽 문에서만 사람이
     * 안 찾아진다 — 그건 현장 입구에서 줄이 서는 일이다. 규칙은 여기 한 곳에 둔다.
     *
     * 네 자리가 아니면 <b>아무도</b> 돌려주지 않는다. 한 자리·두 자리를 허용하면
     * 숫자를 돌려가며 명단을 통째로 훑을 수 있다.
     */
    public static function matchingLast4(string $last4): Builder
    {
        $digits = preg_replace('/\D/', '', $last4) ?: '';

        $query = Employee::query()->where('employment_status', 'active');

        if (strlen($digits) !== 4) {
            return $query->whereRaw('1 = 0');
        }

        $column = "regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g')";

        return $query
            ->whereRaw("length({$column}) >= 4")
            ->whereRaw("right({$column}, 4) = ?", [$digits]);
    }

    public static function employees(string $phone): Builder
    {
        $key = self::normalize($phone);

        return Employee::query()->whereRaw(
            "CASE WHEN length(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g')) = 10 THEN '1' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') END = ?",
            [$key ?? 'invalid'],
        );
    }
}
