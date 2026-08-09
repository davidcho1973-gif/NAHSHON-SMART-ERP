<?php

namespace Tests\Feature;

use App\Jobs\WriteDailyClosingReportJob;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\DailyClosingService;
use App\Services\Ops\OpsLaborService;
use App\Services\Ops\OpsModuleRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 상황실이 인원을 읽어 보고에 반영하고, 마감 버튼이 그날을 정리한다.
 *
 * 핵심 원칙: 상황실이 읽은 "보고 인원" 은 게이트 QR 실적(급여 근거)과 분리되어 있고,
 * 두 값의 차이가 관리 포인트로 드러나야 한다.
 */
class OpsLaborAndClosingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $partner;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->partner = Company::create([
            'code' => 'SUB', 'name' => '한빛전기', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);
        $this->today = Carbon::now('America/Phoenix')->toDateString();
    }

    private function user(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    private function api(string $method, array $args = []): TestResponse
    {
        return $this->postJson('/smart-company-api/'.$method, ['args' => $args, 'siteId' => (string) $this->site->id]);
    }

    private function batchWithLaborItem(int $headcount, string $company = '한빛전기'): OpsIntakeItem
    {
        $batch = OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'paste',
            'raw_text' => $company.' '.$headcount.'명 나왔습니다', 'status' => 'done',
        ]);

        return OpsIntakeItem::create([
            'site_id' => $this->site->id,
            'ops_intake_batch_id' => $batch->id,
            'category' => 'labor',
            'summary' => $company.' '.$headcount.'명 출역',
            'raw_text' => $batch->raw_text,
            'confidence' => 90,
            'proposed' => ['headcount' => $headcount, 'company' => $company, 'trade' => '전기'],
            'status' => 'pending',
        ]);
    }

    /** 게이트 QR 로 실제 출근한 협력사 작업자를 만든다. */
    private function qrWorker(string $email): Employee
    {
        $emp = Employee::create([
            'company_id' => $this->partner->id, 'site_id' => $this->site->id,
            'name' => $email, 'email' => $email,
            'employment_status' => 'active', 'employment_type' => Employee::TYPE_INDIRECT,
        ]);

        AttendanceLog::create([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id, 'site_id' => $this->site->id,
            'attendance_date' => $this->today, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse($this->today.' 07:00:00', 'America/Phoenix'),
            'source' => 'gate_qr', 'status' => 'approved',
        ]);

        return $emp;
    }

    public function test_a_labor_item_is_reflected_immediately(): void
    {
        $item = $this->batchWithLaborItem(3);
        $batch = OpsIntakeBatch::find($item->ops_intake_batch_id);

        $result = app(OpsModuleRouter::class)->autoRoute($batch, $item);

        $this->assertSame(1, $result['labor']);
        $this->assertSame('applied', $item->fresh()->status);

        $row = OpsLaborReport::first();
        $this->assertSame(3, $row->headcount);
        $this->assertSame($this->partner->id, $row->company_id, '등록된 업체와 이름으로 연결돼야 한다');
    }

    public function test_a_labor_item_without_a_headcount_is_not_counted(): void
    {
        $item = $this->batchWithLaborItem(3);
        $item->update(['proposed' => ['company' => '한빛전기']]);   // 인원수를 못 읽은 경우
        $batch = OpsIntakeBatch::find($item->ops_intake_batch_id);

        $this->assertSame(0, app(OpsModuleRouter::class)->autoRoute($batch, $item)['labor']);
        $this->assertSame(0, OpsLaborReport::count(), '추측으로 인원을 만들면 안 된다');
    }

    public function test_reporting_twice_for_the_same_day_replaces_rather_than_doubles(): void
    {
        $router = app(OpsModuleRouter::class);

        $first = $this->batchWithLaborItem(3);
        $router->autoRoute(OpsIntakeBatch::find($first->ops_intake_batch_id), $first);

        $second = $this->batchWithLaborItem(5);
        $router->autoRoute(OpsIntakeBatch::find($second->ops_intake_batch_id), $second);

        $this->assertSame(1, OpsLaborReport::count());
        $this->assertSame(5, OpsLaborReport::first()->headcount);
    }

    public function test_the_gap_between_reported_and_qr_is_surfaced(): void
    {
        $item = $this->batchWithLaborItem(3);
        app(OpsModuleRouter::class)->autoRoute(OpsIntakeBatch::find($item->ops_intake_batch_id), $item);

        // 실제로는 2명만 QR 을 찍었다.
        $this->qrWorker('a@example.com');
        $this->qrWorker('b@example.com');

        $d = app(OpsLaborService::class)->forDate($this->site->id, $this->today);

        $this->assertSame(3, $d['reportedTotal']);
        $this->assertSame(2, $d['actualTotal']);
        $this->assertSame(1, $d['gap'], '보고 3명 · QR 2명 → 1명 미확인이 드러나야 한다');
        $this->assertSame(1, $d['rows'][0]['gap']);
    }

    public function test_a_company_that_scanned_but_was_never_reported_is_shown(): void
    {
        $this->qrWorker('c@example.com');

        $d = app(OpsLaborService::class)->forDate($this->site->id, $this->today);

        $this->assertSame(0, $d['reportedTotal']);
        $this->assertSame(1, $d['actualTotal']);
        $this->assertSame('한빛전기', $d['qrOnly'][0]['company'], '보고 누락도 반대 방향으로 보여야 한다');
    }

    public function test_a_manager_can_enter_and_delete_a_report_by_hand(): void
    {
        $user = $this->user();

        $this->actingAs($user)->api('api_saveOpsLabor', [['company' => '대한설비', 'headcount' => 4, 'trade' => '배관']])
            ->assertStatus(200)->assertJson(['success' => true]);

        $row = OpsLaborReport::where('company_label', '대한설비')->first();
        $this->assertSame(4, $row->headcount);

        $this->actingAs($user)->api('api_deleteOpsLabor', [$row->id])->assertJson(['success' => true]);
        $this->assertSame(0, OpsLaborReport::count());
    }

    public function test_hand_entry_rejects_bad_input(): void
    {
        $svc = app(OpsLaborService::class);

        $this->assertFalse($svc->save($this->site->id, ['company' => '', 'headcount' => 3])['success']);
        $this->assertFalse($svc->save($this->site->id, ['company' => 'X', 'headcount' => -1])['success']);
    }

    public function test_closing_metrics_are_computed_from_the_database(): void
    {
        $item = $this->batchWithLaborItem(3);
        app(OpsModuleRouter::class)->autoRoute(OpsIntakeBatch::find($item->ops_intake_batch_id), $item);
        $this->qrWorker('a@example.com');

        $m = app(DailyClosingService::class)->metrics($this->site->id, $this->today);

        $this->assertSame(3, $m['labor']['reported']);
        $this->assertSame(1, $m['labor']['actualQr']);
        $this->assertSame(2, $m['labor']['gap']);
        $this->assertSame('한빛전기', $m['labor']['byCompany'][0]['company']);
        $this->assertSame(1, $m['ops']['batches']);
        $this->assertSame(1, $m['ops']['parsed']);
    }

    public function test_closing_starts_immediately_and_is_written_in_the_background(): void
    {
        Bus::fake();
        $user = $this->user();

        $res = $this->actingAs($user)->api('api_startDailyClosing');

        $res->assertStatus(200)->assertJson(['success' => true, 'status' => 'writing']);

        // 요청 안에서 AI 를 부르지 않는다 — 마감도 응답 후에 작성된다.
        Bus::assertDispatchedAfterResponse(WriteDailyClosingReportJob::class);

        $report = DailyClosingReport::find($res->json('reportId'));
        $this->assertSame('writing', $report->status);
        $this->assertSame($user->id, $report->closed_by_id);
    }

    public function test_the_report_survives_an_ai_failure_with_metrics_intact(): void
    {
        $item = $this->batchWithLaborItem(6);
        app(OpsModuleRouter::class)->autoRoute(OpsIntakeBatch::find($item->ops_intake_batch_id), $item);

        // GEMINI_API_KEY 가 없는 테스트 환경 = AI 호출 실패. 그래도 숫자는 남아야 한다.
        $reportId = app(DailyClosingService::class)->start($this->site->id, $this->today, null)['reportId'];
        app(DailyClosingService::class)->write($reportId);

        $d = app(DailyClosingService::class)->show($reportId);

        $this->assertSame('done', $d['status'], 'AI 가 실패해도 마감 자체는 성립해야 한다');
        $this->assertSame(6, $d['metrics']['labor']['reported']);
        $this->assertTrue($d['narrative']['aiFailed']);
    }

    public function test_closing_the_same_day_twice_updates_one_report(): void
    {
        $svc = app(DailyClosingService::class);

        $first = $svc->start($this->site->id, $this->today, null)['reportId'];
        $second = $svc->start($this->site->id, $this->today, null)['reportId'];

        $this->assertSame($first, $second);
        $this->assertSame(1, DailyClosingReport::count());
    }

    public function test_recent_list_shows_headline_and_headcounts(): void
    {
        $item = $this->batchWithLaborItem(4);
        app(OpsModuleRouter::class)->autoRoute(OpsIntakeBatch::find($item->ops_intake_batch_id), $item);
        $id = app(DailyClosingService::class)->start($this->site->id, $this->today, null)['reportId'];
        app(DailyClosingService::class)->write($id);

        $list = app(DailyClosingService::class)->recent($this->site->id);

        $this->assertSame(4, $list['reports'][0]['reported']);
        $this->assertSame($this->today, $list['reports'][0]['date']);
    }
}
