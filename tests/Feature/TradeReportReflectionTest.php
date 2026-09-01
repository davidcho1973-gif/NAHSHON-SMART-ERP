<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Ops\TradeReportReflector;
use App\Services\Ops\TradeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 제출 = 반영. 반장이 「오늘 보고 제출」을 누르면 그 내용이 ERP 로 넘어간다.
 *
 * 이 시험지의 절반은 <b>넘어가면 안 되는 것</b>에 관한 것이다. 아무도 안 보는 채로
 * 잘못된 숫자가 공정표에 들어가는 것이, 반영이 안 되는 것보다 훨씬 나쁘다.
 */
class TradeReportReflectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private const DATE = '2026-09-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'RF-CO', 'name' => 'Reflect Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'RF', 'name' => '반영현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        Project::firstOrCreate(['project_code' => 'RF-01'], ['name' => 'RF', 'construction_type' => 'equipment_setting']);
    }

    // ── 준비물 ─────────────────────────────────────────────────────

    private function foreman(string $name = '김반장', string $trade = 'Piping'): User
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'role' => $trade, 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_DIRECT,
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'attendance_date' => self::DATE, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse(self::DATE.' 06:00', 'America/Phoenix'),
            'source' => 'gate', 'status' => 'approved',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'worker', 'account_status' => 'active',
        ]);
    }

    private function wbs(string $code = 'RF-01-W-A100', int $progress = 20): WbsItem
    {
        return WbsItem::create([
            'project_code' => 'RF-01', 'site_id' => $this->site->id, 'level' => 'subtask',
            'wbs_code' => $code, 'activity_id' => 'A100', 'node_no' => '1.1',
            'name' => '3층 배관', 'status' => '진행중', 'crew_size' => 3, 'progress' => $progress,
            'planned_start' => self::DATE, 'planned_end' => self::DATE,
        ]);
    }

    private function batch(DailyTradeReport $report, string $status = 'done'): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'ops-room', 'raw_text' => '오늘 한 일',
            'image_count' => 1, 'daily_trade_report_id' => $report->id, 'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function item(OpsIntakeBatch $batch, array $attrs = []): OpsIntakeItem
    {
        return OpsIntakeItem::create(array_merge([
            'site_id' => $this->site->id,
            'ops_intake_batch_id' => $batch->id,
            'source' => 'ops-room',
            'raw_text' => '3층 배관 60% 했습니다',
            'category' => 'progress',
            'confidence' => 90,
            'summary' => '3층 배관 60%',
            'target_type' => 'wbs',
            'target_code' => 'RF-01-W-A100',
            'target_name' => '3층 배관',
            'proposed' => ['progress' => 60],
            'status' => 'pending',
        ], $attrs));
    }

    private function reflector(): TradeReportReflector
    {
        return app(TradeReportReflector::class);
    }

    private function submitted(User $user): DailyTradeReport
    {
        $report = app(TradeReportService::class)->forUserOrCreate($user, self::DATE);
        $report->forceFill([
            'status' => DailyTradeReport::STATUS_SUBMITTED,
            'submitted_by_id' => $user->id,
            'submitted_at' => now(),
        ])->save();

        return $report;
    }

    // ── 넘어간다 ───────────────────────────────────────────────────

    public function test_a_submitted_report_moves_progress_into_the_schedule(): void
    {
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report));

        $result = $this->reflector()->reflect($report, $user);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(60, (int) $wbs->fresh()->progress);

        $item->refresh();
        $this->assertSame('applied', $item->status);
        // 누가 눌렀나가 아니라 «어떤 길로» 들어왔는지가 남아야, 나중에 이 숫자를 설명할 수 있다.
        $this->assertSame(OpsIntakeItem::VIA_REPORT, $item->applied_via);
        $this->assertSame($user->id, $item->applied_by_id);
    }

    public function test_the_result_is_written_on_the_report_so_the_foreman_can_see_it(): void
    {
        $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report));

        $this->reflector()->reflect($report, $user);

        $report->refresh();
        $this->assertSame(1, $report->applied_count);
        $this->assertNotNull($report->reflected_at);
        $this->assertStringContainsString('반영', (string) $report->reflection_note);
    }

    // ── 넘어가면 안 되는 것 ────────────────────────────────────────

    public function test_progress_never_goes_backwards_on_its_own(): void
    {
        // 조용히 덮으면 이미 기록된 일이 사라진다. 재시공인지 착오인지는 사람만 안다.
        $wbs = $this->wbs(progress: 80);
        $user = $this->foreman();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), ['proposed' => ['progress' => 60]]);

        $this->reflector()->reflect($report, $user);

        $this->assertSame(80, (int) $wbs->fresh()->progress);
        $this->assertSame('needs_input', $item->fresh()->status);
        $this->assertStringContainsString('80%', (string) $item->fresh()->question);
    }

    public function test_completion_is_a_human_declaration_not_an_automatic_one(): void
    {
        // 「완료」는 진척률보다 훨씬 센 레버다 — 실적일이 찍히고(되돌려도 남는다),
        // CPM 이 그 행을 고정해 선행이 밀려도 후속이 안 움직인다. TBM 서명과 같은
        // 종류의 «사람이 책임지는 선언» 이라 자동으로 넘기지 않는다.
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report), ['proposed' => ['progress' => 100, 'status' => '완료']]);

        $this->reflector()->reflect($report, $user);

        $fresh = $wbs->fresh();
        $this->assertSame(100, (int) $fresh->progress);
        $this->assertSame('진행중', $fresh->status);   // 상태는 그대로
        $this->assertNull($fresh->actual_end);
    }

    public function test_money_is_never_applied_by_a_submit(): void
    {
        $user = $this->foreman();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), [
            'category' => 'expense',
            'target_type' => null, 'target_code' => null,
            'proposed' => ['amount' => 421.5, 'vendor' => 'Home Depot'],
        ]);

        $result = $this->reflector()->reflect($report, $user);

        $this->assertSame(0, $result['applied']);
        $this->assertSame('pending', $item->fresh()->status);   // 상태를 낮추지도 않는다
        $this->assertSame(0, MobileExpense::query()->count());
    }

    public function test_a_low_confidence_reading_waits_for_a_person(): void
    {
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report), ['confidence' => TradeReportReflector::AUTO_CONFIDENCE - 1]);

        $this->reflector()->reflect($report, $user);

        $this->assertSame(20, (int) $wbs->fresh()->progress);
    }

    public function test_two_foremen_saying_different_things_stops_both(): void
    {
        // 늦게 올린 쪽이 이기게 두면 그날의 진척이 «올린 순서» 로 정해진다.
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $batch = $this->batch($report);
        $a = $this->item($batch, ['proposed' => ['progress' => 60]]);
        $b = $this->item($batch, ['proposed' => ['progress' => 90]]);

        $this->reflector()->reflect($report, $user);

        $this->assertSame(20, (int) $wbs->fresh()->progress);
        $this->assertSame('needs_input', $a->fresh()->status);
        $this->assertSame('needs_input', $b->fresh()->status);
        $this->assertStringContainsString('서로 다른 보고', (string) $a->fresh()->question);
    }

    public function test_nothing_is_applied_while_photos_are_still_being_read(): void
    {
        // 사진이 늦게 읽히면 «같은 대상에 두 말» 을 비교할 상대가 사라진다.
        // 다 읽힐 때까지 기다렸다가 한 번에 본다.
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report));
        $this->batch($report, status: 'analyzing');

        $result = $this->reflector()->reflect($report, $user);

        $this->assertTrue($result['waiting'] ?? false);
        $this->assertSame(20, (int) $wbs->fresh()->progress);
        $this->assertStringContainsString('사진 판독 중', (string) $report->fresh()->reflection_note);
    }

    public function test_a_reopened_report_is_not_reflected(): void
    {
        // 소장이 「아직 못 받았다」며 되돌린 보고의 내용이 ERP 에 들어가 있으면 안 된다.
        $wbs = $this->wbs();
        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report));

        $report->forceFill(['status' => DailyTradeReport::STATUS_OPEN, 'submitted_at' => null])->save();

        $result = $this->reflector()->reflect($report, $user);

        $this->assertSame('not-submitted', $result['skipped'] ?? null);
        $this->assertSame(20, (int) $wbs->fresh()->progress);
    }

    public function test_reopening_releases_the_automatic_holds_so_a_resubmit_retries(): void
    {
        // 되돌리기는 「다시 해 보자」는 뜻이다. 풀어 주지 않으면 그 항목은 다음
        // 제출에서 후보에도 못 들어가고, 반장 화면에는 같은 숫자가 영원히 남는다.
        $this->wbs(progress: 80);
        $user = $this->foreman();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), ['proposed' => ['progress' => 60]]);

        $this->reflector()->reflect($report, $user);
        $this->assertSame('needs_input', $item->fresh()->status);

        $boss = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        app(TradeReportService::class)->reopen($report->id, '사진이 빠졌습니다', $boss);

        $item->refresh();
        $this->assertSame('pending', $item->status);
        $this->assertNull($item->question);
        $this->assertSame(0, (int) $report->fresh()->held_count);
    }

    // ── 사람에게 올린다 ────────────────────────────────────────────

    public function test_held_items_raise_one_alert_per_site_per_day_and_it_closes_when_cleared(): void
    {
        $this->wbs(progress: 80);
        $user = $this->foreman();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), ['proposed' => ['progress' => 60]]);

        $this->reflector()->reflect($report, $user);

        $alert = UnifiedAlert::query()->where('event_type', 'trade_report_held')->sole();
        $this->assertSame('unresolved', $alert->status);
        $this->assertStringContainsString('Piping', $alert->content);

        // 사람이 처리하면 그 줄은 닫혀야 한다 — 끝난 일로 알림이 남으면 다음 날을 더럽힌다.
        $item->forceFill(['status' => 'dismissed'])->save();
        $this->reflector()->restamp($report->fresh());

        $this->assertSame('completed', $alert->fresh()->status);
    }

    public function test_a_report_that_only_has_notes_does_not_ring_the_bell(): void
    {
        // 계획·이슈는 애초에 반영 대상이 아니다. 그걸 «확인 필요» 로 세면 그 숫자는
        // 절대 0 이 되지 않고, 매일 울리는 알림은 곧 아무도 안 여는 알림이 된다.
        $user = $this->foreman();
        $report = $this->submitted($user);
        $batch = $this->batch($report);
        $this->item($batch, [
            'category' => 'issue', 'target_type' => null, 'target_code' => null,
            'proposed' => null, 'summary' => '안전고리 미착용 지적',
        ]);

        $this->reflector()->reflect($report, $user);

        $this->assertSame(0, (int) $report->fresh()->held_count);
        $this->assertSame(0, UnifiedAlert::query()->where('event_type', 'trade_report_held')->count());
        $this->assertStringContainsString('참고 1건', (string) $report->fresh()->reflection_note);
    }

    // ── 현장 격리 ──────────────────────────────────────────────────

    public function test_a_purchase_order_number_from_another_site_is_never_touched(): void
    {
        // po_no 에는 유일 제약이 없다 — 벤더 PO 번호는 프로젝트마다 001 부터 다시
        // 시작하는 것이 관행이라 중복이 예외가 아니라 기본값에 가깝다.
        $other = Site::create([
            'company_id' => $this->company->id, 'code' => 'OTHER', 'name' => '남의현장', 'status' => 'active',
        ]);
        $theirs = ProcurementItem::create([
            'site_id' => $other->id, 'project_code' => 'OTHER-01', 'wbs_code' => 'OTHER-01-W-1',
            'po_no' => 'PO-001', 'vendor' => 'Graybar', 'status' => '발주완료',
            'eta' => '2026-10-01',
        ]);

        $user = $this->foreman();
        $report = $this->submitted($user);
        $this->item($this->batch($report), [
            'category' => 'procurement', 'target_type' => 'procurement', 'target_code' => 'PO-001',
            'proposed' => ['eta' => '2026-09-15'],
        ]);

        $result = $this->reflector()->reflect($report, $user);

        $this->assertSame(0, $result['applied']);
        $this->assertSame('2026-10-01', $theirs->fresh()->eta->toDateString());
    }

    public function test_an_inspection_date_lands_on_the_register_but_the_status_does_not(): void
    {
        $submittal = Submittal::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'seq' => 7, 'csi' => '03 3000', 'section' => '콘크리트', 'category' => '시험·검사',
            'title' => '앵커 인장시험', 'status' => '미착수',
        ]);

        // 제출물 대장은 관리 권한이 있어야 고칠 수 있다. 반영은 <b>낸 사람의 권한</b>으로
        // 이뤄지므로, 공정별 팀장(site_manager)이 낸 보고는 대장까지 닿는다.
        $lead = $this->foreman('박팀장', 'Concrete');
        $lead->forceFill(['access_role' => 'site_manager'])->save();

        $report = $this->submitted($lead);
        $this->item($this->batch($report), [
            'category' => 'inspection', 'target_type' => 'submittal', 'target_code' => '7',
            'proposed' => ['planned_on' => '2026-09-08', 'status' => '승인'],
        ]);

        $this->reflector()->reflect($report, $lead);

        $fresh = $submittal->fresh();
        $this->assertSame('2026-09-08', $fresh->planned_on->toDateString());
        // 승인은 승인본 서류가 있어야 성립하는 준수 기록이다 — 말로 넘어가지 않는다.
        $this->assertSame('미착수', $fresh->status);
    }

    public function test_a_worker_cannot_move_the_compliance_register_and_the_item_stays_visible(): void
    {
        // 권한이 없으면 반영되지 않는 것이 맞다. 다만 <b>조용히 사라지면</b> 안 된다 —
        // 확인 대기로 남아 관리자가 보고 처리할 수 있어야 한다.
        $submittal = Submittal::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'seq' => 9, 'csi' => '03 3000', 'section' => '콘크리트', 'category' => '시험·검사',
            'title' => '앵커 인장시험', 'status' => '미착수',
        ]);

        $user = $this->foreman();   // access_role = worker
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), [
            'category' => 'inspection', 'target_type' => 'submittal', 'target_code' => '9',
            'proposed' => ['planned_on' => '2026-09-08'],
        ]);

        $this->reflector()->reflect($report, $user);

        $this->assertNull($submittal->fresh()->planned_on);
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertStringContainsString('권한', (string) $item->fresh()->result_note);
    }

    // ── 조회가 사실을 만들어 내지 않는다 ──────────────────────────

    public function test_looking_at_the_screen_does_not_create_a_report(): void
    {
        // 쉬는 날 화면을 열어 보기만 해도 보고 장이 생기면, 그 빈 장이 현황판에
        // «미제출» 로 뜨고 마감보고서의 «미제출 공종» 이 되어 원청에 나간다.
        $user = $this->foreman();

        $this->assertNull(app(TradeReportService::class)->forUser($user, self::DATE));
        $this->assertSame(0, DailyTradeReport::query()->count());

        $this->actingAs($user)->get(route('attendance-app.ops-room'))->assertOk();

        $this->assertSame(0, DailyTradeReport::query()->count());
    }

    public function test_an_empty_report_row_is_not_counted_as_missing(): void
    {
        $user = $this->foreman();
        app(TradeReportService::class)->forUserOrCreate($user, self::DATE);   // 빈 장

        // 출근 기록이 있으므로 기대 목록에는 뜨지만, 빈 장 자체가 줄을 만들어서는 안 된다.
        $board = app(TradeReportService::class)->board($this->site->id, self::DATE);
        $this->assertSame(['Piping'], $board['missingTrades']);
        $this->assertSame(1, $board['total']);
    }

    // ── 폰에서 올린 기록이 보고에 묶인다 ──────────────────────────

    public function test_a_record_uploaded_without_a_site_still_joins_the_report(): void
    {
        // 모바일 상황실은 오랫동안 현장을 'ALL' 로 보냈고, 그래서 배치가 site_id=null
        // 로 저장돼 <b>단 한 건도</b> 보고에 묶이지 않았다 — 「올린 기록 0건」.
        $user = $this->foreman();
        $batch = OpsIntakeBatch::create([
            'site_id' => null, 'created_by_id' => $user->id, 'source' => 'ops-room',
            'raw_text' => '3층 배관 12개 완료', 'image_count' => 0,
        ]);

        app(TradeReportService::class)->attach($batch, $user);

        $batch->refresh();
        $this->assertSame($this->site->id, $batch->site_id);
        $this->assertNotNull($batch->daily_trade_report_id);
    }
}
