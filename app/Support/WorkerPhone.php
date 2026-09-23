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

    public static function employees(string $phone): Builder
    {
        $key = self::normalize($phone);

        return Employee::query()->whereRaw(
            "CASE WHEN length(regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g')) = 10 THEN '1' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') END = ?",
            [$key ?? 'invalid'],
        );
    }
}
