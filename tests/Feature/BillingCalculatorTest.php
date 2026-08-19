<?php

namespace Tests\Feature;

use App\Services\Finance\BillingCalculator;
use Tests\TestCase;

/**
 * 기성 산식 정본(BillingCalculator) 순수 단위 검증.
 *
 * 설계 청사진 §4.2 검증 예시(계약액 $1,200,000 · 유보율 10%)의 표 4회차
 * 전 수치를 그대로 단언한다. DB 를 쓰지 않는다 — 산식이 틀리면 원장 전체가
 * 틀리므로, 숫자 하나하나가 "왜 그 값이어야 하는가"라는 사업 문장과 함께
 * 실패해야 한다.
 */
class BillingCalculatorTest extends TestCase
{
    // ── §4.2 표 4회차 전 수치 ────────────────────────────────────────────

    public function test_application_1_first_progress_billing(): void
    {
        // #1: E=200,000 / F=0 — 첫 회차라 전회값 전부 0
        $r = BillingCalculator::derive(0, 0, 200000.00, 0, 10, 0, 0);

        $this->assertSame(0.0, $r['D'], '첫 회차의 전회 기성은 0 이다');
        $this->assertSame(200000.00, $r['G'], '청구 누계 = D+E+F');
        $this->assertSame(20000.00, $r['held'], '유보 = 누계 200,000 의 10%');
        $this->assertSame(180000.00, $r['line6'], 'line6 = 누계 − 유보');
        $this->assertSame(0.0, $r['line7'], '첫 회차의 전회 인증액은 0 이다');
        $this->assertSame(180000.00, $r['due'], '금회 순청구 = line6 − line7');
    }

    public function test_application_2_with_stored_materials(): void
    {
        // #2: E=150,000 / F=30,000(보관 자재 신규 반입) — 전회 G=200,000 / 전회 F=0 / 전회 line6=180,000
        $r = BillingCalculator::derive(200000.00, 0, 150000.00, 30000.00, 10, 0, 180000.00);

        $this->assertSame(200000.00, $r['D'], 'D = 전회 (D+E) = 0 + 200,000 — 전회 G 도 전회 F 도 아니다');
        $this->assertSame(380000.00, $r['G'], '청구 누계 = 200,000 + 150,000 + 30,000');
        $this->assertSame(38000.00, $r['held'], '유보는 누계 380,000 에 한 번만 계산한다');
        $this->assertSame(342000.00, $r['line6'], 'line6 = 380,000 − 38,000');
        $this->assertSame(180000.00, $r['line7'], 'line7 = 전회 line6 스냅샷');
        $this->assertSame(162000.00, $r['due'], '금회 순청구 = 342,000 − 180,000');
    }

    public function test_application_3_stored_materials_converted_to_work(): void
    {
        // #3 (스톡 전환 회차): 전회 보관 자재 30,000 전량 시공 + 신규 70,000 → E=100,000, F=0
        $r = BillingCalculator::derive(380000.00, 30000.00, 100000.00, 0, 10, 0, 342000.00);

        $this->assertSame(350000.00, $r['D'], '보관 자재는 이월 청구 누계가 아니다 — 시공되는 달에 한 번만 돈이 된다');
        $this->assertNotSame(380000.00, $r['D'], '전회 F 30,000 이 D 에 끼면 시공 전환분이 이중청구된다');
        $this->assertSame(450000.00, $r['G'], '청구 누계 = 350,000 + 100,000 + 0');
        $this->assertSame(45000.00, $r['held'], '유보 = 누계 450,000 의 10%');
        $this->assertSame(405000.00, $r['line6'], 'line6 = 450,000 − 45,000');
        $this->assertSame(342000.00, $r['line7'], 'line7 = 전회 line6 스냅샷');
        $this->assertSame(63000.00, $r['due'], '금회 순증 70,000 에서 유보 증가분 7,000 을 뺀 값이어야 한다');
    }

    public function test_application_4_partial_retainage_release(): void
    {
        // #4 (부분 해제): E=F=0, 금회 해제 20,000 → 해제 누계 20,000
        $r = BillingCalculator::derive(450000.00, 0, 0, 0, 10, 20000.00, 405000.00);

        $this->assertSame(450000.00, $r['D'], '해제 회차도 전회 (D+E) 연속성은 그대로다');
        $this->assertSame(450000.00, $r['G'], '해제는 청구 누계를 바꾸지 않는다');
        $this->assertSame(25000.00, $r['held'], '유보 잔액 = round(450,000×10%) − 해제 누계 20,000');
        $this->assertSame(425000.00, $r['line6'], 'line6 = 450,000 − 25,000');
        $this->assertSame(405000.00, $r['line7'], 'line7 = 전회 line6 스냅샷');
        $this->assertSame(20000.00, $r['due'], '해제 회차의 순청구는 해제액과 같아야 한다');
    }

    // ── 유보 반올림·소급 ─────────────────────────────────────────────────

    public function test_retainage_rounds_once_on_cumulative(): void
    {
        $r = BillingCalculator::derive(0, 0, 123456.78, 0, 10, 0, 0);

        $this->assertSame(12345.68, $r['held'], '유보는 누계에 1회 half-up 반올림한다 — 12,345.678 은 12,345.68 이다');
    }

    public function test_retainage_rate_cut_applies_retroactively(): void
    {
        // §4.2 인하 검산: #3 에서 유보율을 10% → 5% 로 내리면 누계 전체가 새 율로 소급된다
        $r = BillingCalculator::derive(380000.00, 30000.00, 100000.00, 0, 5, 0, 342000.00);

        $this->assertSame(22500.00, $r['held'], '유보율 인하는 이번 회차분만이 아니라 누계 450,000 전체에 적용된다');
        $this->assertSame(85500.00, $r['due'], '이미 떼어 둔 유보와의 차액 15,500 이 환급분으로 순청구에 자동 반영되어야 한다');
    }

    // ── 유보 해제 상한 ───────────────────────────────────────────────────

    public function test_release_cap_is_enforced(): void
    {
        $this->assertTrue(BillingCalculator::releaseWithinCap(20000.00, 45000.00), '잔액 이내 해제는 허용된다');
        $this->assertTrue(BillingCalculator::releaseWithinCap(45000.00, 45000.00), '잔액 전액 해제(최종 정산)도 허용된다');
        $this->assertFalse(BillingCalculator::releaseWithinCap(50000.00, 45000.00), '유보 해제가 직전 승인 회차 유보 잔액을 넘으면 없는 돈을 청구하는 것이다');
        $this->assertFalse(BillingCalculator::releaseWithinCap(-1.00, 45000.00), '음수 해제 입력으로 유보 잔액을 불리는 경로는 막는다');
    }

    // ── 연속성 불변식 ────────────────────────────────────────────────────

    public function test_continuity_invariant_detects_broken_chain(): void
    {
        // #3 의 올바른 D = 전회 G 380,000 − 전회 F 30,000 = 350,000
        $this->assertTrue(BillingCalculator::continuityHolds(350000.00, 380000.00, 30000.00), 'D 가 전회 (D+E)와 일치하면 사슬은 건강하다');
        $this->assertFalse(BillingCalculator::continuityHolds(380000.00, 380000.00, 30000.00), '전회 보관 자재까지 D 로 끌고 오면 연속성이 깨진 것이다');
        $this->assertFalse(BillingCalculator::continuityHolds(340000.00, 380000.00, 30000.00), '중간 회차가 소급 수정되면 후속 회차의 전회 누계가 낡는다');
    }

    // ── 수금 잔액 (3상태 차감) ───────────────────────────────────────────

    public function test_outstanding_only_subtracts_accepted_deductions(): void
    {
        // §4.2 수금 시나리오 #1: 입금 175,000 + 인정 차감 5,000 → 잔액 0
        $settled = BillingCalculator::outstanding(180000.00, [
            ['amount' => 175000.00, 'deduction_amount' => 5000.00, 'deduction_accepted' => true],
        ]);
        $this->assertSame(0.0, $settled, '인정한 차감은 입금과 같은 자격으로 잔액에서 빠진다');
        $this->assertTrue(BillingCalculator::isSettled($settled), '잔액 0 이면 자동 paid — 판정 기준은 outstanding ≤ 0 하나뿐이다');

        // §4.2 수금 시나리오 #2: 승인액 160,000, 입금 100,000 + 57,000, 불인정 차감 3,000
        $disputed = BillingCalculator::outstanding(160000.00, [
            ['amount' => 100000.00, 'deduction_amount' => 0.0, 'deduction_accepted' => null],
            ['amount' => 57000.00, 'deduction_amount' => 3000.00, 'deduction_accepted' => false],
        ]);
        $this->assertSame(3000.00, $disputed, '미판단·불인정 차감은 잔액에 남는다 — GC 통보가 아니라 우리 인정이 장부를 줄인다');
        $this->assertFalse(BillingCalculator::isSettled($disputed), '분쟁 잔액이 남은 회차는 paid 가 아니다');
    }

    public function test_disputed_deductions_count_only_rejected(): void
    {
        $receipts = [
            ['amount' => 100000.00, 'deduction_amount' => 2000.00, 'deduction_accepted' => null],
            ['amount' => 57000.00, 'deduction_amount' => 3000.00, 'deduction_accepted' => false],
            ['amount' => 10000.00, 'deduction_amount' => 5000.00, 'deduction_accepted' => true],
        ];

        $this->assertSame(3000.00, BillingCalculator::disputedDeductions($receipts), '분쟁 집계는 불인정(false)만 — 미판단(null)은 아직 분쟁이 아니다');
    }

    public function test_overpayment_still_settles(): void
    {
        $over = BillingCalculator::outstanding(100.00, [
            ['amount' => 150.00, 'deduction_amount' => 0.0, 'deduction_accepted' => null],
        ]);

        $this->assertSame(-50.00, $over, '과입금은 음수 잔액으로 남는다');
        $this->assertTrue(BillingCalculator::isSettled($over), '과입금도 paid 다 — 배지로 표시할 뿐 상태 기준을 늘리지 않는다');
    }

    // ── payment_terms 파싱 ───────────────────────────────────────────────

    public function test_parse_net_days(): void
    {
        $this->assertSame(30, BillingCalculator::parseNetDays('Net 30'), '"Net 30" 은 제출일 + 30일이다');
        $this->assertSame(45, BillingCalculator::parseNetDays('net45'), '공백 없는 "net45" 도 읽어야 한다');
        $this->assertNull(BillingCalculator::parseNetDays('월말 마감 후 익월 지급'), '자유 텍스트는 null — 호출부가 45일 폴백을 쓴다');
        $this->assertSame(45, BillingCalculator::DEFAULT_NET_DAYS, '폴백 기본값은 제출~입금 45~75일 현실의 하한 45일이다');
    }
}
