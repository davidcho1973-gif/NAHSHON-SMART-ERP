<?php

namespace App\Services\Finance;

use App\Models\MobileExpense;
use Illuminate\Support\Carbon;

/**
 * 같은 돈이 두 번 앉는 것을 입구에서 의심한다.
 *
 * 경비 입구가 여럿이다(ERP 등록·영수증앱·문서함·상황실). 같은 영수증이 두 입구로
 * 들어오면 원장에 두 번 앉는데, 각 입구의 멱등 키는 자기 입구 안에서만 산다.
 * 입구를 가로질러 잡는 방법은 내용 대조뿐이다: 금액이 같고, 날짜가 ±3일이고,
 * 거래처가 닮았으면 "같은 돈일 수 있음" — 막지 않고 표시한다. 진짜 두 건일 수도
 * 있으므로(같은 가게 두 번 방문) 판단은 승인하는 사람이 한다.
 */
class DuplicateExpenseSentry
{
    private const DAY_WINDOW = 3;

    /** 의심 표시 — 설명란 끝에 붙어 승인 목록 어디서든 보인다. */
    public const MARK = '⚠️ 중복 의심';

    public function findSuspect(
        float $amount,
        string $expenseDate,
        ?int $vendorId,
        string $vendorText,
        ?int $excludeId = null,
    ): ?MobileExpense {
        if ($amount <= 0) {
            return null;
        }

        try {
            $date = Carbon::parse($expenseDate);
        } catch (\Throwable) {
            return null;
        }

        $candidates = MobileExpense::query()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->whereBetween('amount', [round($amount - 0.01, 2), round($amount + 0.01, 2)])
            ->whereBetween('expense_date', [
                $date->copy()->subDays(self::DAY_WINDOW)->toDateString(),
                $date->copy()->addDays(self::DAY_WINDOW)->toDateString(),
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $vendorText = mb_strtolower(trim($vendorText));

        return $candidates->first(function (MobileExpense $e) use ($vendorId, $vendorText): bool {
            if ($vendorId !== null && (int) $e->vendor_id === $vendorId) {
                return true;
            }
            if ($vendorText === '') {
                // 거래처를 모르면 금액+날짜만으로 의심하지 않는다 — 오탐이 신뢰를 깎는다.
                return false;
            }

            $candidateVendor = mb_strtolower(trim((string) data_get($e->ocr_data, 'vendor_name', '')));
            $haystack = mb_strtolower((string) $e->description.' '.$candidateVendor);

            return str_contains($haystack, $vendorText)
                || ($candidateVendor !== '' && str_contains($vendorText, $candidateVendor));
        });
    }

    /** 설명란에 붙일 의심 문구. */
    public function note(MobileExpense $suspect): string
    {
        return ' '.self::MARK.': #'.$suspect->id.' ('.$suspect->expense_date?->toDateString().' $'.number_format((float) $suspect->amount, 2).') 와 금액·날짜·거래처 유사 — 승인 전에 확인하세요.';
    }
}
