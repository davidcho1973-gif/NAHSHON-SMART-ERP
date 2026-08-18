<?php

namespace App\Services\Vendors;

use App\Models\Vendor;

/**
 * 협력사 이름 → 거래처 마스터 행. 이 규칙은 여기 한 곳에만 있다.
 *
 * 같은 협력사가 세 벌로 적히고 있었다 — 계약에서는 companies 행, 발주에서는
 * 자유 텍스트 문자열, 거래처 화면에서는 vendors 행. "Graybar" 와 "Graybar Inc."
 * 는 사람 눈에는 같은 회사지만 집계에는 다른 회사다. 장비를 글자로 적던 시절
 * 임대료가 원가에 안 잡혔던 것과 같은 구조다: <b>대장 밖의 글자는 돈으로
 * 이어지지 않는다.</b>
 *
 * 그래서 정본을 vendors 하나로 정했다. 이름이 들어오면 대장에서 찾고(대소문자
 * 무시), 없으면 대장에 만든다. 만들지 않고 거절하면 사람들은 메모 칸에 이름을
 * 적기 시작하고, 그러면 자유 텍스트가 자리만 옮긴 것이 된다.
 */
class VendorResolver
{
    /** 이름으로 거래처를 찾거나 만든다. 빈 이름은 null. */
    public function resolve(?string $name, ?int $companyId = null): ?Vendor
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $existing = Vendor::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->orderByRaw('company_id IS NOT NULL')   // 전사 공통(company_id null) 우선
            ->first();
        if ($existing) {
            return $existing;
        }

        return Vendor::query()->create([
            'name' => $name,
            'company_id' => $companyId,
            'status' => 'active',
        ]);
    }
}
