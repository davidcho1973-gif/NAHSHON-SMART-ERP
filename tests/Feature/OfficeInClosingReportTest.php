<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\DailyClosingService;
use App\Services\Ops\DailyReportComposer;
use App\Services\Ops\TradeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 마감 보고서에 사무의 하루가 실리는가 — 현장 실적과 같은 무게로.
 *
 * 후드 샵드로잉이 안 나간 날은 «배관 60%» 보다 그것이 준공을 정한다. 그런데 마감
 * 보고서는 현장 항목만 실었고, 종합 의견은 현장만 봤다. 사무장이 올린 하루는
 * 어디에도 없었다.
 */
class OfficeInClosingReportTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-04';

    private Site $site;

    private Company $company;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'OC-CO', 'name' => 'Office Closing Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'OC', 'name' => '마감현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '사무장', 'position' => 'office', 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_STAFF,
        ]);
        $this->office = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'admin', 'account_status' => 'active',
        ]);
    }

    /** 사무장의 오늘 보고에 세 가지가 담겼다 — 넘어간 것, 사람을 기다리는 것, 참고. */
    private function officeDay(): DailyTradeReport
    {
        $report = app(TradeReportService::class)->forUserOrCreate($this->office, self::DATE);
        $report->forceFill([
            'status' => DailyTradeReport::STATUS_SUBMITTED,
            'submitted_by_id' => $this->office->id, 'submitted_at' => now(),
        ])->save();

        $batch = OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'ops-room', 'raw_text' => '오늘 사무',
            'image_count' => 0, 'daily_trade_report_id' => $report->id, 'status' => 'done',
        ]);
        $batch->forceFill(['created_at' => Carbon::parse(self::DATE.' 10:00', 'America/Phoenix')])->save();

        $base = ['site_id' => $this->site->id, 'ops_intake_batch_id' => $batch->id, 'source' => 'ops-room', 'confidence' => 90];
        OpsIntakeItem::create($base + [
            'raw_text' => '후드 샵드로잉 제출', 'category' => 'submittal', 'summary' => '배기후드 샵드로잉 제출',
            'target_type' => 'submittal', 'target_code' => '12', 'target_name' => '배기후드 샵드로잉',
            'status' => 'applied', 'applied_via' => OpsIntakeItem::VIA_REPORT,
        ]);
        OpsIntakeItem::create($base + [
            'raw_text' => '8월 기성 청구', 'category' => 'billing', 'summary' => '8월 기성 청구서 제출 $120,000',
            'status' => 'pending',
        ]);
        OpsIntakeItem::create($base + [
            'raw_text' => '원청 회의', 'category' => 'admin', 'summary' => '원청 주간회의 — 후드 납기 협의',
            'status' => 'pending',
        ]);

        return $report;
    }

    public function test_the_closing_metrics_carry_the_office_managers_day(): void
    {
        $this->officeDay();

        $m = app(DailyClosingService::class)->metrics($this->site->id, self::DATE);

        $office = $m['office'];
        $this->assertSame(3, $office['total']);
        $this->assertSame(1, $office['applied']);
        $this->assertSame(1, $office['held'], '청구는 돈이라 사람을 기다린다');
        $this->assertSame(['submittal' => 1, 'billing' => 1, 'admin' => 1], $office['byCategory']);

        $byCat = collect($office['items'])->keyBy('category');
        $this->assertSame('반영', $byCat['submittal']['state']);
        $this->assertSame('확인 대기', $byCat['billing']['state']);
        $this->assertSame('참고', $byCat['admin']['state'], '회의는 어느 표로도 가지 않는다 — 대기가 아니라 참고다');
        $this->assertSame('사무', $byCat['submittal']['dept']);
        $this->assertSame('사무장', $byCat['submittal']['reportedBy']);

        // 현황판에도 사무 줄이 부서로 선다.
        $row = collect($m['tradeReports']['rows'])->firstWhere('trade', '사무');
        $this->assertSame('office', $row['kind']);
    }

    public function test_the_report_people_receive_has_an_office_section_in_html_and_plain_text(): void
    {
        $this->officeDay();
        $service = app(DailyClosingService::class);
        $report = DailyClosingReport::create([
            'site_id' => $this->site->id, 'report_date' => self::DATE, 'status' => 'done',
            'metrics' => $service->metrics($this->site->id, self::DATE),
            'narrative' => [
                'headline' => '사무 3건, 현장 보고 없음',
                'summary' => '후드 샵드로잉이 오늘 나갔다.',
                'officeNote' => '샵드로잉 제출, 기성 청구 대기, 원청 회의 진행.',
                'laborNote' => '', 'progressNote' => '', 'done' => [], 'issues' => [], 'tomorrow' => [], 'attention' => [],
            ],
        ]);

        $mail = app(DailyReportComposer::class)->closing($report);

        $html = $mail['html'];
        $this->assertStringContainsString('사무 업무', $html);
        $this->assertStringContainsString('배기후드 샵드로잉 제출', $html);
        $this->assertStringContainsString('확인 대기', $html);
        $this->assertStringContainsString('샵드로잉 제출, 기성 청구 대기', $html);

        $text = $mail['text'];
        $this->assertStringContainsString('■ 사무 업무 (3건 · 반영 1 · 확인 대기 1)', $text);
        $this->assertStringContainsString('- [제출물·서류] 배기후드 샵드로잉 제출 — 배기후드 샵드로잉 (반영)', $text);
        $this->assertStringContainsString('- [청구·기성] 8월 기성 청구서 제출 $120,000 (확인 대기)', $text);
    }

    public function test_the_narrator_is_told_to_weave_field_and_office_together(): void
    {
        $this->officeDay();

        $seen = null;
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->once()->withArgs(function (array $images, string $prompt) use (&$seen): bool {
            $seen = $prompt;

            return true;
        })->andReturn(['data' => [
            'headline' => 'h', 'summary' => 's', 'laborNote' => '', 'progressNote' => '', 'officeNote' => '사무 한 문단',
        ], 'model' => 'mock']);
        $this->app->instance(OcrEngine::class, $engine);

        $report = DailyClosingReport::create(['site_id' => $this->site->id, 'report_date' => self::DATE, 'status' => 'writing']);
        app(DailyClosingService::class)->write($report->id);

        $this->assertNotNull($seen);
        $this->assertStringContainsString('3-4. `office`', $seen);
        $this->assertStringContainsString('현장과 사무를 한데 놓고', $seen);
        $this->assertStringContainsString('"office"', $seen, '집계 JSON 에 사무 블록이 실려야 서술이 그것을 쓴다');
        $this->assertSame('사무 한 문단', $report->fresh()->narrative['officeNote']);
    }

    public function test_a_day_without_office_reports_adds_no_empty_section(): void
    {
        $report = DailyClosingReport::create([
            'site_id' => $this->site->id, 'report_date' => self::DATE, 'status' => 'done',
            'metrics' => app(DailyClosingService::class)->metrics($this->site->id, self::DATE),
            'narrative' => ['headline' => 'h', 'summary' => 's', 'laborNote' => '', 'progressNote' => ''],
        ]);

        $mail = app(DailyReportComposer::class)->closing($report);

        $this->assertStringNotContainsString('사무 업무', $mail['html']);
        $this->assertStringNotContainsString('■ 사무 업무', $mail['text']);
    }
}
