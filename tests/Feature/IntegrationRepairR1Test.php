<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Models\EmployeePayrollProfile;
use App\Models\MobileExpense;
use App\Models\OpsIntakeItem;
use App\Models\PayrollTimesheet;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Ops\OpsModuleRouter;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 연계 수선 R1 — 정합·안전 급수선 묶음 (docs/시스템-연계-점검.md).
 */
class IntegrationRepairR1Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'OWN', 'name' => '자사', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create(['code' => 'S1', 'name' => '1현장', 'status' => 'active', 'company_id' => $this->company->id]);
    }

    private function worker(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => '철수', 'last_name' => '김', 'name' => '김철수',
            'employment_status' => 'active',
        ], $overrides));
    }

    // ── ⑤ 퇴사 캐스케이드 ──────────────────────────────────────────────

    public function test_퇴사하면_계정_배지_기기_채팅방_푸시가_한번에_닫힌다(): void
    {
        $employee = $this->worker();
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active']);
        $badge = EmployeeBadgeQrToken::activeForEmployee($employee);
        WorkerDevice::create(['employee_id' => $employee->id, 'token_hash' => hash('sha256', 'dev'), 'label' => '폰']);
        $room = CommunicationRoom::create(['company_id' => $this->company->id, 'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '방', 'status' => 'active']);
        $member = CommunicationRoomMember::create(['communication_room_id' => $room->id, 'employee_id' => $employee->id, 'status' => 'active']);
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://p.example/1', 'endpoint_hash' => hash('sha256', 'e1'), 'public_key' => 'pk', 'auth_token' => 'at']);

        $employee->update(['employment_status' => 'terminated']);

        $this->assertSame('suspended', $user->fresh()->account_status, '구글 로그인이 계속 되면 안 된다');
        $this->assertSame('revoked', $badge->fresh()->status);
        $this->assertSame(0, WorkerDevice::query()->where('employee_id', $employee->id)->count());
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(0, PushSubscription::query()->where('user_id', $user->id)->count());
    }

    public function test_복직해도_계정은_자동으로_살아나지_않는다(): void
    {
        // 관리자가 일부러 정지시킨 계정이 복직 처리에 딸려 되살아나면 안 된다(점검 ③의 규칙 충돌).
        $employee = $this->worker();
        $user = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active']);

        $employee->update(['employment_status' => 'terminated']);
        $employee->update(['employment_status' => 'active']);

        $this->assertSame('suspended', $user->fresh()->account_status, '복구는 사람이 계정 화면에서 명시적으로 한다');
    }

    // ── ⑱ 배지 재직 검사 ──────────────────────────────────────────────

    public function test_퇴사자의_배지_QR은_토큰이_살아있어도_무효다(): void
    {
        $employee = $this->worker();
        $badge = EmployeeBadgeQrToken::activeForEmployee($employee);
        $token = (string) $badge->token;

        $this->assertNotNull(EmployeeBadgeQrToken::activeForToken($token));

        // 캐스케이드를 우회해 상태만 바꿔도(직접 DB 수정 등) 읽는 쪽이 막아야 한다.
        Employee::query()->whereKey($employee->id)->update(['employment_status' => 'terminated']);

        $this->assertNull(EmployeeBadgeQrToken::activeForToken($token), '퇴사자 신원이 QR 로 노출되면 안 된다');
    }

    // ── ⑦ 안전 서명 ↔ 직원 배선 ──────────────────────────────────────

    private function saveCard(array $signatures): void
    {
        app(SafetyWorkService::class)->save([[
            'id' => 'WRK-R1-001', 'title' => '배관 작업', 'project' => 'P', 'crew' => 2,
            'planStatus' => '승인완료', 'tbmStatus' => '완료', 'closeStatus' => '시작전', 'progressStatus' => '미분석',
            'signatures' => $signatures,
        ]], $this->site->code, null);
    }

    public function test_이름만_적어도_동명이인이_없으면_직원과_이어진다(): void
    {
        $employee = $this->worker(['name' => '박용접', 'first_name' => '용접', 'last_name' => '박']);

        $this->saveCard([['name' => '박용접', 'role' => '용접공', 'signed' => true]]);

        $sig = \App\Models\SafetyWorkSignature::query()->where('name', '박용접')->first();
        $this->assertSame($employee->id, $sig->employee_id, '이 연결이 없으면 공정별 실투입 인원이 항상 0이다');
    }

    public function test_동명이인이면_잇지_않는다(): void
    {
        $this->worker(['name' => '김반장']);
        $this->worker(['name' => '김반장', 'site_id' => null]);
        $other = Site::create(['code' => 'S2', 'name' => '2현장', 'status' => 'active']);

        // 카드가 현장에 속하면 그 현장 소속 한 명으로 좁혀 잇는다.
        $this->saveCard([['name' => '김반장', 'signed' => true]]);
        $sig = \App\Models\SafetyWorkSignature::query()->where('name', '김반장')->first();
        $this->assertNotNull($sig->employee_id, '현장 소속이 한 명뿐이면 그 사람이다');

        // 현장으로도 못 좁히면 null — 틀린 연결보다 빈 연결이 낫다.
        $this->worker(['name' => '이무명', 'site_id' => null]);
        $this->worker(['name' => '이무명', 'site_id' => null]);
        $this->saveCard([
            ['name' => '김반장', 'signed' => true],
            ['name' => '이무명', 'signed' => true],
        ]);
        $this->assertNull(\App\Models\SafetyWorkSignature::query()->where('name', '이무명')->value('employee_id'));
    }

    public function test_왕복_저장이_기존_배정을_지우지_않는다(): void
    {
        $employee = $this->worker(['name' => '최전기']);
        $this->saveCard([['name' => '최전기', 'signed' => false]]);

        // 화면이 employeeId 없이 같은 이름을 되보내도(단순 카드 저장) 연결은 남는다.
        $this->saveCard([['name' => '최전기', 'signed' => true]]);

        $sig = \App\Models\SafetyWorkSignature::query()->where('name', '최전기')->first();
        $this->assertSame($employee->id, $sig->employee_id);
        $this->assertTrue((bool) $sig->signed);
    }

    // ── ② 회사분류 백필 ──────────────────────────────────────────────

    public function test_회사를_협력사로_분류하면_기존_직원의_고용형태도_맞춰진다(): void
    {
        $partner = Company::create(['code' => 'PTN', 'name' => '한빛전기', 'status' => 'active']);
        $stale = $this->worker(['company_id' => $partner->id, 'name' => '협력공', 'employment_type' => Employee::TYPE_DIRECT]);
        $manual = $this->worker(['company_id' => $partner->id, 'name' => '특수직', 'employment_type' => Employee::TYPE_CLIENT]);

        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin)
            ->postJson('/smart-company-api/api_setCompanyType', ['args' => [$partner->id, Company::TYPE_PARTNER], 'siteId' => 'ALL'])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'backfilled' => 1]);

        $this->assertSame(Employee::TYPE_INDIRECT, $stale->fresh()->employment_type, '분류 전 등록 인원이 시급 직영으로 남아 있으면 안 된다');
        $this->assertSame(Employee::TYPE_CLIENT, $manual->fresh()->employment_type, '사람이 따로 정한 값은 건드리지 않는다');
    }

    // ── ⑫ 0원 시급 알림 ──────────────────────────────────────────────

    public function test_일은_했는데_시급이_0원이면_알림이_뜬다(): void
    {
        $employee = $this->worker(['name' => '무급자']);
        EmployeePayrollProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            ['pay_type' => 'hourly', 'base_rate' => 0, 'overtime_multiplier' => 1.5],
        );
        PayrollTimesheet::create([
            'employee_id' => $employee->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'work_date' => '2026-08-10',
            'check_in_at' => Carbon::parse('2026-08-10 07:00:00'), 'check_out_at' => Carbon::parse('2026-08-10 15:00:00'),
            'regular_minutes' => 480, 'overtime_minutes' => 0, 'status' => 'approved',
        ]);

        app(PayrollCalculator::class)->aggregate(Carbon::parse('2026-08-03'), Carbon::parse('2026-08-16'));

        $alert = UnifiedAlert::query()->where('event_type', 'payroll_zero_rate')->first();
        $this->assertNotNull($alert, '$0 명세서가 조용히 발행되면 안 된다');
        $this->assertSame($employee->id, $alert->employee_id);

        // 멱등 — 화면을 여러 번 열어도 같은 기간엔 한 건이다.
        app(PayrollCalculator::class)->aggregate(Carbon::parse('2026-08-03'), Carbon::parse('2026-08-16'));
        $this->assertSame(1, UnifiedAlert::query()->where('event_type', 'payroll_zero_rate')->count());
    }

    // ── 알림 소유자 두 칸 판정 ────────────────────────────────────────

    public function test_employee_id_로만_발행된_알림도_본인에게_보인다(): void
    {
        $otherSite = Site::create(['code' => 'S9', 'name' => '9현장', 'status' => 'active']);
        $employee = $this->worker(['name' => '파견자']);
        $me = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'worker', 'account_status' => 'active',
            'access_scope' => 'site', 'allowed_site_id' => $otherSite->id, // 다른 현장으로 파견 중
        ]);

        UnifiedAlert::create([
            'alert_code' => 'AL-R1-1', 'fingerprint' => 'r1-owner-test',
            'site_id' => $this->site->id, 'employee_id' => $employee->id,
            'source_module' => 'HR', 'event_type' => 'visa_expiry', 'severity' => 'critical',
            'title' => '비자 만료 임박', 'status' => 'unresolved', 'occurred_at' => now(),
        ]);

        $visible = UnifiedAlert::query()->visibleTo($me)->pluck('fingerprint')->all();
        $this->assertContains('r1-owner-test', $visible, '내 비자 만료 알림은 파견 현장 스코프와 무관하게 내게 와야 한다');
    }

    // ── E. 상황실 지출 멱등 + 계정 정본 ──────────────────────────────

    public function test_상황실_지출은_두번_반영해도_원장에_한건이고_계정은_정본을_지난다(): void
    {
        $item = OpsIntakeItem::create([
            'site_id' => $this->site->id, 'source' => 'paste', 'raw_text' => '홈디포 자재 $120',
            'category' => 'expense', 'confidence' => 90, 'summary' => '자재 구매',
            'target_type' => 'expense', 'target_code' => 'EXP',
            'proposed' => ['amount' => 120, 'vendor' => 'Home Depot', 'category' => '자재'], 'status' => 'pending',
        ]);

        $router = app(OpsModuleRouter::class);
        $first = $router->applyExpense($item);
        $this->assertTrue($first['success']);

        $expense = MobileExpense::query()->find($first['expenseId']);
        $this->assertSame('ops:'.$item->id, $expense->source_ref);
        $this->assertSame($this->company->id, $expense->company_id, '회사 스코프 화면에서 보이려면 company_id 가 있어야 한다');
        // 'Home Depot' 문맥 → 정본 추론 규칙이 계정을 정한다. 자유 문구('자재비')가 아니라
        // 정본 목록의 계정 문구가 앉는지만 본다.
        $this->assertContains($expense->accounting_account, \App\Support\FinanceChartOfAccounts::accounts());

        // 되돌렸다 다시 반영하는 상황 — 라우터를 한 번 더 불러도 원장은 한 건이다.
        $second = $router->applyExpense($item->fresh());
        $this->assertTrue($second['success']);
        $this->assertSame($first['expenseId'], $second['expenseId']);
        $this->assertSame(1, MobileExpense::query()->where('source_ref', 'ops:'.$item->id)->count(), '같은 영수증이 원장에 두 번 앉으면 안 된다');
    }

    // ── D. 조달 → 경비: 계정 정본 + company_id ───────────────────────

    public function test_자재_입고_경비는_정본_계정과_회사를_달고_원장에_앉는다(): void
    {
        $project = Project::create(['project_code' => 'R1-P', 'name' => '수선 프로젝트', 'construction_type' => 'equipment_setting', 'company_id' => $this->company->id]);
        $po = ProcurementItem::create([
            'project_code' => 'R1-P', 'site_id' => $this->site->id, 'wbs_code' => 'R1-P-W-A010',
            'status' => '입고완료', 'amount' => 5400, 'vendor' => 'Graybar', 'po_no' => 'PO-77',
        ]);

        app(\App\Services\Finance\ProcurementExpenseConnector::class)->sync($po);

        $expense = MobileExpense::query()->where('source_ref', "procurement:{$po->id}")->first();
        $this->assertNotNull($expense);
        $this->assertSame('5201 Job Materials', $expense->accounting_account, '정본에 없는 문구면 계정별 집계에서 갈라진다');
        $this->assertSame($this->company->id, $expense->company_id);
    }
}
