<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Ops\TradeReportReminderService;
use App\Services\Ops\TradeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 공종별 일일보고 — 반장의 몫.
 *
 * 핵심 규칙: <b>기대 목록은 출퇴근에서 뽑는다.</b> 사람이 관리하는 목록은 곧 안
 * 맞고, 안 맞는 현황판은 영원히 빨갛고, 영원히 빨간 판은 아무도 보지 않는다.
 */
class TradeReportTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private TradeReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'TR-CO', 'name' => 'Trade Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'TR', 'name' => '현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->service = app(TradeReportService::class);
    }

    private function worker(string $name, string $trade, string $type = Employee::TYPE_DIRECT): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'role' => $trade, 'employment_status' => 'active',
            'employment_type' => $type,
        ]);
    }

    private function clockIn(Employee $e, string $date = '2026-08-31'): void
    {
        AttendanceLog::create([
            'employee_id' => $e->id, 'company_id' => $e->company_id, 'site_id' => $this->site->id,
            'attendance_date' => $date, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse($date.' 06:00', 'America/Phoenix'),
            'source' => 'gate', 'status' => 'approved',
        ]);
    }

    private function userFor(Employee $e): User
    {
        return User::factory()->create([
            'employee_id' => $e->id, 'access_role' => 'worker', 'account_status' => 'active',
        ]);
    }

    private function entry(DailyTradeReport $report): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'ops-room', 'raw_text' => '배관 12개 완료',
            'image_count' => 2, 'daily_trade_report_id' => $report->id,
        ]);
    }

    // ── 기대 목록은 출퇴근이 정본 ───────────────────────────────────

    public function test_expected_trades_come_from_who_actually_clocked_in(): void
    {
        $piping = $this->worker('김반장', 'Piping');
        $electric = $this->worker('이반장', 'Electrical');
        $this->worker('안 온 사람', 'Duct');   // 출근 안 함

        $this->clockIn($piping);
        $this->clockIn($electric);

        $this->assertSame(['Electrical', 'Piping'], $this->service->expectedTrades($this->site->id, '2026-08-31'));
    }

    public function test_the_client_side_is_not_our_trade(): void
    {
        // 원청 소속이 현장에 왔다고 우리가 그들의 보고를 낼 수는 없다.
        $client = $this->worker('원청 감독', 'Inspection', Employee::TYPE_CLIENT);
        $this->clockIn($client);

        $this->assertSame([], $this->service->expectedTrades($this->site->id, '2026-08-31'));
    }

    public function test_a_day_nobody_worked_expects_nothing(): void
    {
        $this->worker('김반장', 'Piping');

        $this->assertSame([], $this->service->expectedTrades($this->site->id, '2026-08-31'));
    }

    // ── 제출 ───────────────────────────────────────────────────────

    public function test_a_foreman_submits_their_trade_and_the_board_turns_green(): void
    {
        $piping = $this->worker('김반장', 'Piping');
        $this->clockIn($piping);
        $user = $this->userFor($piping);

        $report = $this->service->forUserOrCreate($user, '2026-08-31');
        $this->assertNotNull($report);
        $this->entry($report);

        $result = $this->service->submit($user, '2026-08-31');
        $this->assertTrue($result['success']);

        $board = $this->service->board($this->site->id, '2026-08-31');
        $this->assertSame(1, $board['submitted']);
        $this->assertSame(0, $board['missing']);
        $this->assertTrue($board['rows'][0]['submitted']);
        $this->assertSame('김반장', $board['rows'][0]['submittedBy']);
        $this->assertSame(2, $board['rows'][0]['photos']);
        // 화면의 「되돌리기」가 이 번호로 걸린다 — 없으면 되돌릴 방법이 없다.
        $this->assertSame($report->id, $board['rows'][0]['reportId']);
    }

    public function test_an_empty_report_cannot_be_submitted(): void
    {
        // 아무것도 안 올리고 제출만 누르면 현황판은 초록인데 종합보고서에는
        // 그 공종이 비어 있다 — 그게 제일 나쁜 상태다.
        $piping = $this->worker('김반장', 'Piping');
        $this->clockIn($piping);
        $user = $this->userFor($piping);

        $result = $this->service->submit($user, '2026-08-31');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('올린 기록이 없습니다', $result['error']);
    }

    public function test_two_foremen_of_one_trade_share_one_report(): void
    {
        // 현장에서 "배관 오늘 보고" 는 한 장이지 사람 수만큼이 아니다.
        $a = $this->worker('김반장', 'Piping');
        $b = $this->worker('박반장', 'Piping');
        $this->clockIn($a);
        $this->clockIn($b);

        $ra = $this->service->forUserOrCreate($this->userFor($a), '2026-08-31');
        $rb = $this->service->forUserOrCreate($this->userFor($b), '2026-08-31');

        $this->assertSame($ra->id, $rb->id);
        $this->assertSame(1, DailyTradeReport::query()->count());
    }

    public function test_a_worker_with_neither_trade_nor_position_gets_no_report_slot(): void
    {
        // 공종도 직책도 없는 작업자 — 그 사람의 일은 반장의 보고에 실린다. 자리를
        // 만들면 현황판에 낼 수 없는 줄이 하나 생긴다.
        $nobody = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '작업자', 'employment_status' => 'active',
        ]);

        $this->assertNull($this->service->forUserOrCreate($this->userFor($nobody), '2026-08-31'));
    }

    /** 공종 없는 관리자 — 직책이 자리를 정한다. */
    private function staff(string $name, ?string $position): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'position' => $position, 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_STAFF,
        ]);
    }

    public function test_an_office_manager_gets_a_slot_named_by_their_position(): void
    {
        // 일일보고는 쓰는 곳이 아니라 모이는 곳이다. 사무관리자가 앱에서 올린 하루도
        // 같은 장에 모여야 한다 — 공종이 없다고 빠지면 반쪽 보고서다.
        $office = $this->staff('사무장', 'office');
        $safety = $this->staff('안전', 'safety');
        $super = $this->staff('소장', 'superintendent');
        $engineer = $this->staff('공무', 'engineer');
        $untitled = $this->staff('관리직', null);   // 직책이 비어 있어도 관리직이면 사무

        $this->assertSame('사무', $this->service->forUserOrCreate($this->userFor($office), '2026-08-31')?->trade);
        $this->assertSame('안전', $this->service->forUserOrCreate($this->userFor($safety), '2026-08-31')?->trade);
        $this->assertSame('현장관리', $this->service->forUserOrCreate($this->userFor($super), '2026-08-31')?->trade);
        $this->assertSame('공무', $this->service->forUserOrCreate($this->userFor($engineer), '2026-08-31')?->trade);
        $this->assertSame('사무', $this->service->forUserOrCreate($this->userFor($untitled), '2026-08-31')?->trade);

        $report = DailyTradeReport::query()->where('trade', '사무')->first();
        $this->assertSame(DailyTradeReport::KIND_OFFICE, $report->kind, '현황판이 공종 줄과 갈라 그릴 수 있어야 한다');
        // 사무장과 직책 없는 관리직은 같은 «사무» 한 장을 나눠 쓴다 — 부서 하나에 보고 하나.
        $this->assertSame(4, DailyTradeReport::query()->count());
    }

    public function test_a_trade_wins_over_a_position(): void
    {
        // 반장 직책에 공종이 있으면 공종이 자리다 — 반장은 «반장» 보고가 아니라 «배관» 보고를 낸다.
        $foreman = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '배관반장', 'role' => 'Piping', 'position' => 'foreman',
            'employment_status' => 'active', 'employment_type' => Employee::TYPE_DIRECT,
        ]);

        $report = $this->service->forUserOrCreate($this->userFor($foreman), '2026-08-31');

        $this->assertSame('Piping', $report->trade);
        $this->assertSame(DailyTradeReport::KIND_TRADE, $report->kind);
    }

    public function test_office_staff_who_clocked_in_are_expected_after_the_trades(): void
    {
        $this->clockIn($this->worker('덕트', 'HVAC'));
        $this->clockIn($this->worker('배관', 'Piping'));
        $this->clockIn($this->staff('사무장', 'office'));
        $this->clockIn($this->staff('안전', 'safety'));
        $this->staff('안 나온 소장', 'superintendent');   // 출근 안 함 — 기대 목록에 없다

        // 공종은 가나다, 부서는 정해진 순서(사무·안전·현장관리·공무)로 뒤에 선다.
        $this->assertSame(['HVAC', 'Piping', '사무', '안전'], $this->service->expectedTrades($this->site->id, '2026-08-31'));
    }

    public function test_the_office_row_on_the_board_is_marked_as_office_and_counts_its_people(): void
    {
        $this->clockIn($this->worker('배관', 'Piping'));
        $office = $this->staff('사무장', 'office');
        $this->clockIn($office);
        $this->clockIn($this->staff('경리', 'office'));

        $user = $this->userFor($office);
        $report = $this->service->forUserOrCreate($user, '2026-08-31');
        $this->entry($report);
        $this->assertTrue($this->service->submit($user, '2026-08-31')['success']);

        $board = $this->service->board($this->site->id, '2026-08-31');
        $rows = collect($board['rows'])->keyBy('trade');

        $this->assertSame('trade', $rows['Piping']['kind']);
        $this->assertSame('office', $rows['사무']['kind']);
        $this->assertSame(2, $rows['사무']['headcount'], '사무 자리에 든 사람 수 — 공종 칸이 아니라 직책으로 센다');
        $this->assertTrue($rows['사무']['submitted']);
        $this->assertSame(['Piping'], $board['missingTrades']);
    }

    public function test_the_missing_list_names_office_staff_who_worked_but_did_not_submit(): void
    {
        $this->clockIn($this->staff('사무장', 'office'));

        $reminder = app(TradeReportReminderService::class);

        $this->assertSame(['사무'], $reminder->missingTrades($this->site->id, '2026-08-31'));
    }

    public function test_reopening_requires_a_reason_and_reverts_the_status(): void
    {
        $piping = $this->worker('김반장', 'Piping');
        $this->clockIn($piping);
        $user = $this->userFor($piping);
        $report = $this->service->forUserOrCreate($user, '2026-08-31');
        $this->entry($report);
        $this->service->submit($user, '2026-08-31');

        $boss = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);

        // 까닭 없이 되돌리면 반장은 무엇을 더 올려야 할지 모른다.
        $this->assertFalse($this->service->reopen($report->id, '  ', $boss)['success']);

        $this->assertTrue($this->service->reopen($report->id, '사진이 없습니다', $boss)['success']);
        $fresh = $report->fresh();
        $this->assertSame(DailyTradeReport::STATUS_OPEN, $fresh->status);
        $this->assertSame('사진이 없습니다', $fresh->reopen_reason);
        $this->assertNull($fresh->submitted_at);
    }

    // ── 미제출 알림 ────────────────────────────────────────────────

    public function test_missing_trades_are_the_ones_that_worked_but_did_not_submit(): void
    {
        $piping = $this->worker('김반장', 'Piping');
        $duct = $this->worker('최반장', 'Duct');
        $this->clockIn($piping);
        $this->clockIn($duct);

        $user = $this->userFor($piping);
        $report = $this->service->forUserOrCreate($user, '2026-08-31');
        $this->entry($report);
        $this->service->submit($user, '2026-08-31');

        $missing = app(TradeReportReminderService::class)->missingTrades($this->site->id, '2026-08-31');

        $this->assertSame(['Duct'], $missing);
    }

    public function test_after_the_deadline_the_site_manager_gets_the_list(): void
    {
        $duct = $this->worker('최반장', 'Duct');
        $this->clockIn($duct);

        // 마감(17시)이 지난 시각 — 이제 사람에게 올라간다.
        app(TradeReportReminderService::class)->run(Carbon::parse('2026-08-31 18:00', 'America/Phoenix'));

        $alert = UnifiedAlert::query()->where('event_type', 'trade_report_missing')->sole();
        $this->assertSame('OPS', $alert->source_module);
        $this->assertStringContainsString('Duct', $alert->content);
        $this->assertSame($this->site->id, $alert->site_id);
    }

    public function test_nothing_is_escalated_before_the_deadline(): void
    {
        $duct = $this->worker('최반장', 'Duct');
        $this->clockIn($duct);

        app(TradeReportReminderService::class)->run(Carbon::parse('2026-08-31 09:00', 'America/Phoenix'));

        $this->assertSame(0, UnifiedAlert::query()->where('event_type', 'trade_report_missing')->count());
    }

    public function test_a_day_nobody_worked_raises_nothing(): void
    {
        // 아무도 안 나온 날 현황판이 빨가면, 영원히 빨간 판이 되어 아무도 안 본다.
        $this->worker('최반장', 'Duct');

        app(TradeReportReminderService::class)->run(Carbon::parse('2026-08-31 18:00', 'America/Phoenix'));

        $this->assertSame(0, UnifiedAlert::query()->where('event_type', 'trade_report_missing')->count());
    }
}
