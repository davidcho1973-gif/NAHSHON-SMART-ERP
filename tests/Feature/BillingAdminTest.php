<?php

namespace Tests\Feature;

use App\Models\BillingReceipt;
use App\Models\Company;
use App\Models\MobileExpense;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Services\Admin\ContractAdminService;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 기성 청구 · 수금 — 수주 계약 청구 원장(BillingAdminService)의 뒷단.
 *
 * 엑셀 대장에서 실제로 깨지던 무결성을 그대로 검증한다: 회차 연속성(D = 전회 D+E)이
 * 소급 수정·중간 삭제로 어긋나는 사고, 미인증(submitted) 청구가 매출로 잡혀 AR 이
 * 부풀던 과대계상, 유보금을 회차별 합산으로 이중 계상하던 실수, 그리고 수금이
 * 경비 원장(mobile_expenses)을 오염시키던 경로 — 재무 대시보드 '기성 수금액' 카드가
 * 직원 경비를 보여주다 제거된 근본 원인 — 를 막는다.
 */
class BillingAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $us;

    private Company $them;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->us = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->them = Company::create(['code' => 'LG', 'name' => 'LG', 'status' => 'active']);
        $this->site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function svc(): BillingAdminService
    {
        return app(BillingAdminService::class);
    }

    /** 수주 계약 픽스처 — 계약액 $1,200,000 · 유보율 10% (설계 §4.2 검증 예시 그대로). */
    private function contract(array $extra = []): ProjectContract
    {
        return ProjectContract::create(array_merge([
            'company_id' => $this->us->id, 'counterparty_company_id' => $this->them->id,
            'site_id' => $this->site->id, 'title' => 'LG ESS 전기공사',
            'direction' => 'receivable', 'status' => 'active',
            'original_amount' => 1200000, 'retainage_percent' => 10,
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function draft(ProjectContract $c, array $over = []): array
    {
        return $this->svc()->saveBilling(array_merge([
            'projectContractId' => (string) $c->id,
            'periodStart' => '2026-08-01',
            'periodEnd' => '2026-08-31',
            'thisPeriodAmount' => '0',
            'storedMaterialsAmount' => '0',
        ], $over));
    }

    /** @return array<string, mixed> */
    private function setStatus(int $id, string $action, array $extra = []): array
    {
        return $this->svc()->setBillingStatus(array_merge(['id' => $id, 'action' => $action], $extra));
    }

    /** @return array<string, mixed> */
    private function receipt(ProjectContract $c, ?int $appId, string $amount, array $over = []): array
    {
        return $this->svc()->saveBillingReceipt(array_merge([
            'projectContractId' => (string) $c->id,
            'payApplicationId' => $appId !== null ? (string) $appId : '',
            'receivedOn' => '2026-08-15',
            'amount' => $amount,
        ], $over));
    }

    /**
     * 설계 §4.2 검증 예시를 서비스 동선 그대로 재현.
     * #1 paid(입금 175,000 + 인정 차감 5,000) / #2 approved(승인액 160,000 삭감, 부분수금 157,000
     * + 불인정 차감 3,000) / #3 approved(미입금) / #4 retainage_release 20,000 submitted.
     */
    private function buildBlueprintScenario(ProjectContract $c): array
    {
        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $this->receipt($c, $a1['id'], '175000', [
            'method' => 'check', 'reference' => '1234',
            'deductionAmount' => '5000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '1',
        ]);

        $a2 = $this->draft($c, ['thisPeriodAmount' => '150000', 'storedMaterialsAmount' => '30000']);
        $this->setStatus($a2['id'], 'submit');
        $this->setStatus($a2['id'], 'approve', ['approvedAmount' => '160000']);
        $this->receipt($c, $a2['id'], '100000', ['method' => 'ach']);
        $this->receipt($c, $a2['id'], '57000', [
            'deductionAmount' => '3000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '0',
        ]);

        $a3 = $this->draft($c, ['thisPeriodAmount' => '100000']);
        $this->setStatus($a3['id'], 'submit');
        $this->setStatus($a3['id'], 'approve');

        $a4 = $this->draft($c, ['type' => 'retainage_release', 'retainageReleased' => '20000']);
        $this->setStatus($a4['id'], 'submit');

        return ['a1' => $a1['id'], 'a2' => $a2['id'], 'a3' => $a3['id'], 'a4' => $a4['id']];
    }

    // ── 접근 (§10-5) ─────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_the_billing_ledger(): void
    {
        $this->contract();
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->getBillingContracts()['success'], '미수금·유보금은 회사의 협상 카드다 — 열람 권한 밖에는 보이지 않아야 한다');
        $this->assertFalse($this->svc()->getBillings(1)['success']);
    }

    public function test_payroll_reads_but_cannot_manage(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('payroll'));

        $res = $this->svc()->getBillingContracts();
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage'], '급여 담당은 수금 현황을 봐야 하지만 청구서를 쓰는 사람은 아니다');

        $write = $this->draft($c, ['thisPeriodAmount' => '1000']);
        $this->assertFalse($write['success']);
        $this->assertStringContainsString('권한', $write['error']);
    }

    public function test_a_site_manager_only_sees_their_own_site(): void
    {
        $mine = $this->contract();
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'UTC', 'status' => 'active']);
        $theirs = $this->contract(['title' => '남의 현장', 'site_id' => $other->id]);

        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $ids = array_column($this->svc()->getBillingContracts()['rows'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        // 목록에서 안 보이는 계약은 쓰기 경로로도 닿을 수 없어야 한다 — 방어는 항상 서버다
        $blocked = $this->draft($theirs, ['thisPeriodAmount' => '1000']);
        $this->assertFalse($blocked['success']);
        $this->assertSame('계약을 찾을 수 없습니다.', $blocked['error']);
    }

    public function test_an_unscoped_site_manager_is_locked_out(): void
    {
        $this->contract();
        $this->actingAs($this->user('site_manager', ['access_scope' => 'team', 'allowed_site_id' => null]));

        $this->assertSame([], $this->svc()->getBillingContracts()['rows'], '스코프가 정의되지 않은 계정의 기본은 폐쇄다 — 실수로 전체가 열리면 안 된다');
    }

    // ── 채번 · 연속성 (§10-6) ────────────────────────────────────────────

    public function test_application_numbers_increase_within_the_contract(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->assertTrue($a1['success']);
        $this->assertSame(1, $a1['applicationNo']);
        $this->assertStringStartsWith('PA-'.now()->format('Y').'-', PayApplication::find($a1['id'])->internal_reference);

        $this->setStatus($a1['id'], 'submit');
        $a2 = $this->draft($c, ['thisPeriodAmount' => '100000']);
        $this->assertSame(2, $a2['applicationNo'], '회차 번호(App #)는 계약 안에서 이어 붙는다 — 연속성 사슬의 정렬 축이다');
    }

    public function test_the_previous_billed_amount_is_forced_by_the_server(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        // #1: E=200,000 + 보관 자재 F=30,000 → G=230,000
        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000', 'storedMaterialsAmount' => '30000']);
        $this->setStatus($a1['id'], 'submit');

        // #2: 조작 시도 — 파생값 키를 보내도 서버는 읽지 않고 강제 재계산해야 한다
        $a2 = $this->draft($c, [
            'thisPeriodAmount' => '100000',
            'previousBilledAmount' => '999999',
            'cumulativeAmount' => '1',
            'retainageHeld' => '0',
        ]);

        $this->assertTrue($a2['success']);
        $this->assertSame(200000.0, $a2['computed']['D'],
            '전회 F(보관 자재) 30,000 은 D 로 이월되지 않는다 — 시공되는 달에 한 번만 돈이 된다');
        $this->assertSame(300000.0, $a2['computed']['G']);
        $this->assertSame(200000.0, (float) PayApplication::find($a2['id'])->previous_billed_amount);
    }

    public function test_a_second_draft_cannot_be_opened(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $this->draft($c, ['thisPeriodAmount' => '1000']);
        $res = $this->draft($c, ['thisPeriodAmount' => '2000']);

        $this->assertFalse($res['success'], 'draft 가 둘이면 어느 쪽이 다음 청구인지 흐려지고 D 사슬이 갈라진다');
        $this->assertStringContainsString('작성 중인 회차가 이미 있습니다', $res['error']);
    }

    public function test_a_middle_application_cannot_be_deleted(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '1000']);
        $this->setStatus($a1['id'], 'submit');
        $a2 = $this->draft($c, ['thisPeriodAmount' => '2000']);

        $blocked = $this->svc()->deleteBilling($a1['id']);
        $this->assertFalse($blocked['success'], '중간 회차가 빠지면 후속 회차의 전회 누계(D·line7)가 허공을 가리킨다');
        $this->assertStringContainsString('다음 회차에서 조정', $blocked['error']);

        // 최신 draft 는 지울 수 있다 — 아직 아무도 이 회차를 참조하지 않는다
        $this->assertTrue($this->svc()->deleteBilling($a2['id'])['success']);
        $this->assertNull(PayApplication::find($a2['id']));
    }

    public function test_withdraw_and_unapprove_only_touch_the_latest_application(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $a2 = $this->draft($c, ['thisPeriodAmount' => '100000']);
        $this->setStatus($a2['id'], 'submit');

        // 중간 회차 회수 거부 — 중간을 되돌리면 후속 회차의 전회 누계가 낡는다
        $blocked = $this->setStatus($a1['id'], 'withdraw');
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('후속 회차', $blocked['error']);

        // 최신 회차는 무를 수 있다 — 제출일도 함께 지워진다
        $this->assertTrue($this->setStatus($a2['id'], 'withdraw')['success']);
        $fresh2 = PayApplication::find($a2['id']);
        $this->assertSame('draft', $fresh2->status);
        $this->assertNull($fresh2->submitted_on);

        $this->setStatus($a2['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $this->setStatus($a2['id'], 'approve', ['approvedAmount' => '90000']);

        $blocked = $this->setStatus($a1['id'], 'unapprove');
        $this->assertFalse($blocked['success'], '중간 회차의 승인 취소도 같은 이유로 막는다 — 사슬 보호');

        $this->assertTrue($this->setStatus($a2['id'], 'unapprove')['success']);
        $fresh2 = PayApplication::find($a2['id']);
        $this->assertSame('submitted', $fresh2->status);
        $this->assertNull($fresh2->approved_on);
        $this->assertNull($fresh2->approved_amount, '승인을 취소하면 승인액(삭감 기록)도 함께 소거돼야 한다');
    }

    // ── 상태 머신 (§10-7) ────────────────────────────────────────────────

    public function test_state_transitions_follow_the_whitelist(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '1000']);

        $this->assertFalse($this->setStatus($a1['id'], 'approve')['success'], 'draft 를 건너뛰어 승인할 수 없다 — 제출 없이 승인된 청구는 존재하지 않는다');
        $this->assertFalse($this->setStatus($a1['id'], 'close')['success']);
        $this->assertFalse($this->setStatus($a1['id'], 'reopen')['success']);
        $this->assertFalse($this->setStatus($a1['id'], 'nonsense')['success']);

        $this->setStatus($a1['id'], 'submit');
        $this->assertFalse($this->setStatus($a1['id'], 'submit')['success'], '이미 제출된 회차를 다시 제출할 수 없다');
        $this->assertSame('submitted', PayApplication::find($a1['id'])->status);
    }

    public function test_an_application_with_receipts_cannot_be_reverted(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $this->receipt($c, $a1['id'], '50000');

        $blocked = $this->setStatus($a1['id'], 'unapprove');
        $this->assertFalse($blocked['success'], '돈이 들어온 회차를 되돌리면 수금의 귀속처가 사라진다');
        $this->assertStringContainsString('수금', $blocked['error']);
        $this->assertSame('approved', PayApplication::find($a1['id'])->status);
    }

    public function test_an_approved_application_refuses_amount_edits(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        $res = $this->draft($c, ['id' => (string) $a1['id'], 'thisPeriodAmount' => '999999']);

        $this->assertFalse($res['success'], '승인된 기성은 다음 회차에서 조정한다 — GC 에 제출한 원본은 불변이다');
        $this->assertStringContainsString('다음 회차에서 조정', $res['error']);
        $this->assertSame(200000.0, (float) PayApplication::find($a1['id'])->this_period_amount);
    }

    // ── 승인액 분리 (§10-8) ──────────────────────────────────────────────

    public function test_the_certified_amount_drives_the_balance_but_the_claim_survives(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        // §4.2 #2 재현: 청구 162,000 → GC 가 160,000 으로 삭감 승인
        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $a2 = $this->draft($c, ['thisPeriodAmount' => '150000', 'storedMaterialsAmount' => '30000']);
        $this->setStatus($a2['id'], 'submit');
        $this->setStatus($a2['id'], 'approve', ['approvedAmount' => '160000']);

        $fresh = PayApplication::find($a2['id']);
        $this->assertSame(162000.0, (float) $fresh->amount_due, '청구 원본은 삭감돼도 남는다 — 삭감 이력이 분쟁·협상의 1차 기록이다');
        $this->assertSame(160000.0, (float) $fresh->approved_amount);

        // 잔액은 승인액 기준이다: 160,000 입금이면 청구액 162,000 에 못 미쳐도 완결
        $res = $this->receipt($c, $a2['id'], '160000');
        $this->assertSame('paid', $res['applicationStatus'], '수금 기대액은 approved_amount ?? amount_due — 승인액이 있으면 승인액이 기준이다');
    }

    // ── 수금 (§10-9) ─────────────────────────────────────────────────────

    public function test_a_partial_payment_leaves_the_balance_open(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        $res = $this->receipt($c, $a1['id'], '100000');
        $this->assertSame('approved', $res['applicationStatus'], '일부만 들어온 회차는 완료가 아니다 — 잔액 80,000 이 미수로 남는다');

        $row = collect($this->svc()->getBillings($c->id)['rows'])->firstWhere('applicationNo', 1);
        $this->assertSame(80000.0, $row['outstanding']);
        $this->assertContains('부분수금', array_column($row['alerts'], 'label'));
    }

    public function test_deduction_judgement_has_three_states(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        // 인정(true) / 미판단(null) / 불인정(false) 차감을 하나씩
        $this->receipt($c, $a1['id'], '50000', ['deductionAmount' => '5000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '1']);
        $this->receipt($c, $a1['id'], '50000', ['deductionAmount' => '3000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '']);
        $this->receipt($c, $a1['id'], '50000', ['deductionAmount' => '2000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '0']);

        $row = collect($this->svc()->getBillings($c->id)['rows'])->firstWhere('applicationNo', 1);

        // 180,000 − 150,000(입금) − 5,000(인정 차감만) = 25,000. 미판단·불인정은 잔액에 남는다
        $this->assertSame(25000.0, $row['outstanding'],
            'GC 가 상계를 통보했다고 채권이 사라지는 게 아니다 — 우리가 인정해야 장부에서 빠진다');
        $this->assertSame(2000.0, $row['disputedDeductions'], '불인정 차감만 분쟁 잔액으로 집계된다 — 미판단은 아직 분쟁이 아니다');
        $this->assertContains('분쟁 차감', array_column($row['alerts'], 'label'));

        $judgements = collect($row['receipts'])->pluck('deductionAccepted')->all();
        $this->assertSame([true, null, false], $judgements, '3상태(인정/미판단/불인정)가 가공 없이 그대로 내려가야 화면이 판단 변경을 그릴 수 있다');
    }

    public function test_full_payment_settles_the_application_automatically(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        $res = $this->receipt($c, $a1['id'], '175000', ['deductionAmount' => '5000', 'deductionReason' => 'backcharge', 'deductionAccepted' => '1']);
        $this->assertSame('paid', $res['applicationStatus'], '잔액 ≤ 0 단일 기준 — 입금 175,000 + 인정 차감 5,000 = 기대액 180,000 이면 자동 완료다');

        $fresh = PayApplication::find($a1['id']);
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertNotNull($fresh->paid_by_user_id);

        // 자동 완료된 회차는 reopen 대상이 아니다 — 수금을 삭제하면 저절로 되돌아온다
        $this->assertFalse($this->setStatus($a1['id'], 'reopen')['success']);
    }

    public function test_an_overpayment_stays_paid_with_a_badge(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        $res = $this->receipt($c, $a1['id'], '181000');
        $this->assertSame('paid', $res['applicationStatus'], '과입금도 소멸이다 — 상태 기준은 하나(잔액 ≤ 0)고 초과분은 배지로만 알린다');

        $row = collect($this->svc()->getBillings($c->id)['rows'])->firstWhere('applicationNo', 1);
        $this->assertSame(-1000.0, $row['outstanding']);
        $this->assertContains('과입금', array_column($row['alerts'], 'label'));
    }

    public function test_deleting_a_receipt_reopens_the_application(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $rid = $this->receipt($c, $a1['id'], '180000')['id'];
        $this->assertSame('paid', PayApplication::find($a1['id'])->status);

        $res = $this->svc()->deleteBillingReceipt($rid);

        $this->assertTrue($res['success']);
        $this->assertSame('approved', $res['applicationStatus'], '수금을 지우면 잔액이 재발생한다 — 완료 딱지가 남아 있으면 미수금이 증발한다');
        $fresh = PayApplication::find($a1['id']);
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->paid_at, '되돌림 시 지급 필드도 함께 소거된다 — 낡은 지급 기록은 감사 때 독이다');
        $this->assertNull($fresh->paid_by_user_id);
    }

    public function test_an_unassigned_receipt_can_be_matched_later(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        // 입금이 먼저 오고 매칭은 나중 — 회차 미배정(계약 직속)으로 기록된다
        $res = $this->receipt($c, null, '180000');
        $this->assertTrue($res['success']);
        $this->assertNull($res['applicationStatus'], '미배정 입금은 어느 회차의 잔액도 건드리지 않는다');

        $detail = $this->svc()->getBillings($c->id);
        $this->assertCount(1, $detail['unassignedReceipts'], '매칭 대기 입금은 별도 섹션에 떠 있어야 잊히지 않는다');
        $this->assertSame('approved', PayApplication::find($a1['id'])->status);

        // 배정하는 순간 잔액이 반영된다 — 180,000 전액이라 자동 완료까지
        $assigned = $this->svc()->assignBillingReceipt(['id' => $res['id'], 'payApplicationId' => (string) $a1['id']]);
        $this->assertTrue($assigned['success']);
        $this->assertSame('paid', $assigned['applicationStatus']);
        $this->assertSame([], $this->svc()->getBillings($c->id)['unassignedReceipts']);
    }

    public function test_a_manual_write_off_needs_a_reason_and_survives_receipt_deletion(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // due 180,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');
        $rid = $this->receipt($c, $a1['id'], '100000')['id'];

        // 사유 없는 회수 포기는 기록할 수 없다 — 포기는 사업 판단이고 판단에는 근거가 남아야 한다
        $noReason = $this->setStatus($a1['id'], 'close');
        $this->assertFalse($noReason['success']);
        $this->assertArrayHasKey('memo', $noReason['errors']);

        // 현장 소장(site_manager)은 회수 포기를 결정할 수 없다 — 확정 기록을 접는 건 삭제 권한자다
        $this->actingAs($this->user('site_manager'));
        $this->assertFalse($this->setStatus($a1['id'], 'close', ['memo' => 'x'])['success']);

        $this->actingAs($this->user('admin'));
        $closed = $this->setStatus($a1['id'], 'close', ['memo' => 'GC 정산 합의 — 잔액 80,000 회수 포기']);
        $this->assertTrue($closed['success']);
        $fresh = PayApplication::find($a1['id']);
        $this->assertSame('paid', $fresh->status);
        $this->assertTrue((bool) data_get($fresh->payload, 'closedManually'));

        // 수동 종결분은 수금이 지워져도 자동 복귀하지 않는다 — 포기 결정이 수금 정리에 휩쓸리면 안 된다
        $this->assertSame('paid', $this->svc()->deleteBillingReceipt($rid)['applicationStatus']);

        // 재개는 수동 종결분 전용이고, 재개하면 포기 기록이 걷힌다
        $reopened = $this->setStatus($a1['id'], 'reopen');
        $this->assertTrue($reopened['success']);
        $fresh = PayApplication::find($a1['id']);
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertFalse((bool) data_get($fresh->payload, 'closedManually'));
    }

    // ── 유보 해제 상한 (§10-10) ──────────────────────────────────────────

    public function test_a_retainage_release_above_the_cap_is_refused(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));

        $a1 = $this->draft($c, ['thisPeriodAmount' => '200000']);   // held 20,000
        $this->setStatus($a1['id'], 'submit');
        $this->setStatus($a1['id'], 'approve');

        $a2 = $this->draft($c, ['type' => 'retainage_release', 'retainageReleased' => '25000']);
        $this->assertTrue($a2['success'], '상한 검증은 제출 게이트다 — 작성 중 저장까지 막지 않는다');

        $blocked = $this->setStatus($a2['id'], 'submit');
        $this->assertFalse($blocked['success']);
        $this->assertArrayHasKey('retainageReleased', $blocked['errors'],
            '유보 잔액 20,000 을 넘는 해제 25,000 은 GC 가 줄 수 없는 돈이다 — 청구서가 반려된다');

        // 상한과 같은 전액 해제(경계값)는 통과해야 한다 — 그것이 최종 정산이다
        $this->draft($c, ['id' => (string) $a2['id'], 'type' => 'retainage_release', 'retainageReleased' => '20000']);
        $ok = $this->setStatus($a2['id'], 'submit');
        $this->assertTrue($ok['success']);
        $this->assertSame(0.0, $ok['computed']['held']);
        $this->assertSame(20000.0, $ok['computed']['due'], '해제 회차의 순청구액은 해제액 그 자체다');
    }

    // ── 과청구 경고 (§10-11) ─────────────────────────────────────────────

    public function test_overbilling_warns_but_saves(): void
    {
        $c = $this->contract();   // 계약액 1,200,000
        $this->actingAs($this->user('admin'));

        $res = $this->draft($c, ['thisPeriodAmount' => '1300000']);

        $this->assertTrue($res['success'], 'CO 승인 지연 중 선청구가 현실이다 — 경고하되 차단하지 않는다');
        $this->assertContains('과청구', array_column($res['alerts'], 'label'));

        $submitted = $this->setStatus($res['id'], 'submit');
        $this->assertTrue($submitted['success']);
        $this->assertContains('과청구', array_column($submitted['alerts'], 'label'), '제출 응답에도 같은 경고가 실려야 화면이 배지를 그린다');
    }

    // ── 재무 대시보드 (§10-12) ───────────────────────────────────────────

    public function test_finance_stats_reports_the_billing_ledger(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->buildBlueprintScenario($c);

        $stats = SmartCompanyData::financeStats('ALL');

        // billedTotal = 180,000 + 160,000(승인 삭감액) + 63,000 — #4(submitted 20,000)는 제외
        $this->assertSame(403000.0, $stats['billedTotal'],
            '미인증(submitted) 청구를 매출로 세면 AR 이 부풀어 자금 계획이 틀어진다 — 확정분만 센다');
        $this->assertSame(20000.0, $stats['submittedPending'], '미확정 파이프라인은 별도 키로 분리해 보여준다');
        $this->assertSame(332000.0, $stats['receivedTotal']);
        $this->assertSame(66000.0, $stats['arOutstanding'], 'AR = 403,000 − 332,000 − 5,000(인정 차감) — 회차 잔액 3,000 + 63,000 과 교차 검산이 맞아야 한다');
        $this->assertSame(3000.0, $stats['disputedDeductions']);
        $this->assertSame(45000.0, $stats['retainageHeld'],
            '유보금은 회차 합산이 아니라 누계다 — 최신 확정 회차(#3)의 45,000 하나만이 진짜 잔액이다');
        $this->assertEqualsWithDelta(82.4, $stats['collectionRate'], 0.001, '수금률 = 332,000 ÷ 403,000 = 82.4%');
    }

    public function test_finance_stats_scope_keeps_headquarters_rows(): void
    {
        $this->actingAs($this->user('admin'));
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'UTC', 'status' => 'active']);

        $mine = $this->contract(['retainage_percent' => 0]);
        $theirs = $this->contract(['title' => '남의 현장', 'site_id' => $other->id, 'retainage_percent' => 0]);
        $hq = $this->contract(['title' => '본사 공통', 'site_id' => null, 'retainage_percent' => 0]);

        foreach ([[$mine, '1000', '500'], [$theirs, '2000', '700'], [$hq, '4000', '100']] as [$contract, $amount, $paid]) {
            $app = $this->draft($contract, ['thisPeriodAmount' => $amount]);
            $this->setStatus($app['id'], 'submit');
            $this->setStatus($app['id'], 'approve');
            $this->receipt($contract, $app['id'], $paid);
        }

        $scoped = SmartCompanyData::financeStats((string) $this->site->id);
        $this->assertSame(5000.0, $scoped['billedTotal'],
            '현장 필터는 본사 공통(site_id NULL)을 함께 본다 — 본사 계약 수금이 현장 화면에서 증발하면 안 된다');
        $this->assertSame(600.0, $scoped['receivedTotal']);
        $this->assertSame(4400.0, $scoped['arOutstanding']);

        $all = SmartCompanyData::financeStats('ALL');
        $this->assertSame(7000.0, $all['billedTotal']);
        $this->assertSame(1300.0, $all['receivedTotal']);
    }

    public function test_the_contract_list_aggregates_match_the_ledger(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->buildBlueprintScenario($c);
        $this->receipt($c, null, '10000');   // 매칭 대기 1건

        $row = collect($this->svc()->getBillingContracts()['rows'])->firstWhere('id', $c->id);

        $this->assertSame(403000.0, $row['billedTotal']);
        $this->assertSame(342000.0, $row['receivedTotal']);   // 332,000 + 미배정 10,000
        $this->assertSame(45000.0, $row['retainageHeld']);
        $this->assertSame(3000.0, $row['disputedDeductions']);
        $this->assertSame(450000.0, $row['cumulative'], '청구 누계(G)는 최신 확정 회차(#4) 값이다 — 회차 합산이 아니다');
        $this->assertSame(750000.0, $row['balanceToFinish']);
        $this->assertSame(4, $row['applicationCount']);
        $this->assertSame(1, $row['unassignedCount'], '매칭 대기 건수가 목록에 떠야 사장이 배정을 잊지 않는다');
    }

    // ── 경비 원장 불가침 (§10-13) ────────────────────────────────────────

    public function test_the_expense_ledger_is_untouched_by_billing(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->buildBlueprintScenario($c);
        $this->receipt($c, null, '10000');

        $this->assertSame(4, PayApplication::count());
        $this->assertSame(4, BillingReceipt::count());
        $this->assertSame(0, MobileExpense::count(),
            '수입은 지출로 둔갑하지 않는다 — 기성·수금 원장은 경비 원장(mobile_expenses)과 완전히 분리된 독립 테이블이다');
    }

    // ── API (§10-14) ─────────────────────────────────────────────────────

    public function test_the_api_exposes_the_screen_and_blocks_read_only_clients(): void
    {
        $c = $this->contract();

        $this->actingAs($this->user('admin'));
        $this->postJson('/smart-company-api/api_getBillingContracts', ['args' => [[]], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);

        // 열람 전용(원청) 계정 — api_get* 접두사가 아닌 쓰기 엔드포인트는 403 으로 끊긴다
        $this->actingAs($this->user('client'));
        $this->postJson('/smart-company-api/api_saveBilling', [
            'args' => [['projectContractId' => (string) $c->id, 'periodEnd' => '2026-08-31', 'thisPeriodAmount' => '1000']],
            'siteId' => 'ALL',
        ])->assertStatus(403);

        $this->assertSame(0, PayApplication::count(), '원청 계정이 우리 청구 원장에 회차를 만들 수 있으면 안 된다');
    }

    // ── 계약 삭제 가드 (§10-15) ──────────────────────────────────────────

    public function test_a_contract_with_billing_records_cannot_be_deleted(): void
    {
        $c = $this->contract();
        $this->actingAs($this->user('admin'));
        $this->draft($c, ['thisPeriodAmount' => '1000']);

        $res = app(ContractAdminService::class)->delete($c->id);

        $this->assertFalse($res['success'], 'GC 제출·수취의 재무 1차 기록이 계약과 함께 사라지면 분쟁·감사 증빙이 소실된다');
        $this->assertStringContainsString('기성 청구·수금 기록이 1건', $res['error']);
        $this->assertNotNull(ProjectContract::find($c->id));
    }
}
