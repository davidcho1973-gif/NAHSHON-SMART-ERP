<?php

namespace App\Services\Finance;

/**
 * 기성 청구 산식 정본 — DB 를 모르는 순수 정적 함수 모음.
 *
 * 산식이 모델 저장 훅·서비스·화면에 흩어지면 회차 연속성(D = 전회 D+E)이
 * 계산 지점마다 다르게 나오는 사고가 난다. 엑셀 대장에서 가장 자주 깨지던
 * 무결성이 바로 이것이라, 파생 금액 계산은 전부 이 클래스 한 곳에만 둔다.
 * 서비스는 사용자가 보낸 파생값을 무시하고 여기 결과로 강제 덮어쓴다.
 *
 * AIA G702/G703 정합 규칙 (설계 청사진 §4.1):
 *  - D(전회 기성) = 전회 (D+E) = 전회 G − 전회 F. F(보관 자재)는 매회
 *    "현재 보관분"을 다시 적는 스톡이라 D 로 이월되지 않는다 — 시공되는 달에
 *    E 로 옮겨 한 번만 돈이 된다. 흐름으로 가산하면 이중청구 경로가 열린다.
 *  - 유보금은 누계(G)에 1회만 반올림한다. 회차별 반올림 합산은 오차가
 *    표류하고, 유보율 중도 인하 시 소급 재산정이 불가능해서 폐기했다.
 *  - 저장 금액은 전부 소수 2자리 고정 — 반올림 지점은 유보 산정 한 곳뿐이고,
 *    나머지 round() 는 부동소수 이진 오차가 표에 새는 것을 막는 방어다.
 */
class BillingCalculator
{
    /** Net N 파싱 실패 시 지급 기일 기본 일수(제출일 + 45일) — 제출~입금 45~75일 현실. */
    public const DEFAULT_NET_DAYS = 45;

    /**
     * 회차 파생 금액 일괄 계산 (G702 헤더 산식).
     *
     * @param  float  $previousCumulative  전회 회차의 G (첫 회차는 0)
     * @param  float  $previousStored  전회 회차의 F (첫 회차는 0)
     * @param  float  $thisPeriod  E: 금회 시공분 (보관 자재의 당월 시공 전환분 포함)
     * @param  float  $stored  F: 현재 보관 자재 스냅샷 — 누계 가산 아님
     * @param  float  $rate  유보율(%) — 이 회차 기준값. 중도 인하 시 누계 전체에 자동 소급
     * @param  float  $releasedToDate  이번 회차까지의 유보 해제 누계 (금회 해제 포함)
     * @param  float  $prevLine6  전회 회차의 line6 (첫 회차는 0)
     * @return array{D: float, G: float, held: float, line6: float, line7: float, due: float}
     */
    public static function derive(
        float $previousCumulative,
        float $previousStored,
        float $thisPeriod,
        float $stored,
        float $rate,
        float $releasedToDate,
        float $prevLine6
    ): array {
        $d = self::previousBilled($previousCumulative, $previousStored);
        $g = round($d + $thisPeriod + $stored, 2);
        // 유일한 산술 반올림 지점: 누계(G)에 1회 half-up. 회차별 반올림 합산 금지.
        $held = round(round($g * $rate / 100, 2) - $releasedToDate, 2);
        $line6 = round($g - $held, 2);
        $line7 = round($prevLine6, 2);
        $due = round($line6 - $line7, 2);

        return ['D' => $d, 'G' => $g, 'held' => $held, 'line6' => $line6, 'line7' => $line7, 'due' => $due];
    }

    /**
     * D(전회 기성)의 정본 유도 — 전회 (D+E) = 전회 G − 전회 F.
     * 보관 자재(F)를 D 로 이월하면 시공 전환하는 달에 같은 돈이 두 번 청구된다.
     */
    public static function previousBilled(float $previousCumulative, float $previousStored): float
    {
        return round($previousCumulative - $previousStored, 2);
    }

    /**
     * 연속성 불변식 검증: 이 회차에 적힌 D 가 전회 (D+E)와 일치하는가.
     * 어긋나면 전회 F 를 끌고 왔거나(이중청구) 중간 회차가 소급 수정된 것이다.
     */
    public static function continuityHolds(float $claimedPreviousBilled, float $previousCumulative, float $previousStored): bool
    {
        return self::cents($claimedPreviousBilled) === self::cents(self::previousBilled($previousCumulative, $previousStored));
    }

    /**
     * 유보 해제 상한 검증: 해제액 ≤ 직전 승인 회차의 유보 잔액(retainage_held).
     * 음수 해제(수기 음수 입력으로 유보를 불리는 경로)도 여기서 차단한다.
     */
    public static function releaseWithinCap(float $released, float $priorHeld): bool
    {
        if ($released < 0) {
            return false;
        }

        return self::cents($released) <= self::cents($priorHeld);
    }

    /**
     * 회차 잔액 = 기대액 − Σ입금 − Σ인정(accepted=true) 차감.
     * 미판단(null)·불인정(false) 차감은 잔액에 남긴다 — GC 가 상계를 통보했다고
     * 채권이 사라지는 게 아니라, 우리가 인정해야 비로소 장부에서 빠진다.
     *
     * @param  float  $expected  approved_amount ?? amount_due
     * @param  array<int, array|object>  $receipts  각 항목: amount / deduction_amount / deduction_accepted
     */
    public static function outstanding(float $expected, array $receipts): float
    {
        $received = 0.0;
        $accepted = 0.0;

        foreach ($receipts as $receipt) {
            $received += (float) (data_get($receipt, 'amount') ?? 0);

            if (self::isAccepted(data_get($receipt, 'deduction_accepted'))) {
                $accepted += (float) (data_get($receipt, 'deduction_amount') ?? 0);
            }
        }

        return round($expected - $received - $accepted, 2);
    }

    /**
     * 분쟁 차감 합계 — 불인정(accepted=false)으로 판단한 상계만 집계한다.
     * 미판단(null)은 분쟁이 아니라 아직 판단 전이므로 포함하지 않는다.
     *
     * @param  array<int, array|object>  $receipts
     */
    public static function disputedDeductions(array $receipts): float
    {
        $disputed = 0.0;

        foreach ($receipts as $receipt) {
            if (self::isRejected(data_get($receipt, 'deduction_accepted'))) {
                $disputed += (float) (data_get($receipt, 'deduction_amount') ?? 0);
            }
        }

        return round($disputed, 2);
    }

    /**
     * 자동 paid 판정의 단일 기준: outstanding ≤ 0.00 (F4).
     * 과입금(음수 잔액)도 소멸로 본다 — 배지로 표시할 뿐 상태 기준은 하나다.
     */
    public static function isSettled(float $outstanding): bool
    {
        return self::cents($outstanding) <= 0;
    }

    /**
     * 과청구 검사: 청구 누계(G)가 계약 현재 금액을 넘었는가.
     * 경고용이다 — CO 승인 지연 중 선청구가 현실이라 차단하지 않는다 (§4.1).
     */
    public static function isOverbilled(float $cumulative, float $contractAmount): bool
    {
        return self::cents($cumulative) > self::cents($contractAmount);
    }

    /**
     * payment_terms 문자열에서 Net N 일수 추출 ("Net 30", "net45" 등).
     * 파싱 실패는 null — 호출부가 DEFAULT_NET_DAYS(45일)로 폴백한다.
     */
    public static function parseNetDays(?string $paymentTerms): ?int
    {
        if ($paymentTerms === null) {
            return null;
        }

        return preg_match('/net\s*(\d+)/i', $paymentTerms, $matches) === 1 ? (int) $matches[1] : null;
    }

    /** 2자리 금액의 동치·대소 비교는 센트 정수로 — 부동소수 == 비교 금지. */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * 차감 인정 여부 정규화 — 명시적 인정(true)만 참.
     * Eloquent boolean 캐스트(true/false/null) 외에 selectRaw 경유 PostgreSQL
     * 원시 불리언('t'/'f')도 받는다 — 집계 쿼리가 캐스트를 우회하기 때문.
     */
    private static function isAccepted(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if ($value === 't') {
            return true;
        }
        if ($value === 'f') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    /** 차감 불인정(분쟁) 여부 정규화 — 명시적 불인정(false)만 참, 미판단(null)은 거짓. */
    private static function isRejected(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if ($value === 'f') {
            return true;
        }
        if ($value === 't') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false;
    }
}
