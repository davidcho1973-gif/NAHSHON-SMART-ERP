<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use App\Services\Ops\OpsIntakeService;
use App\Services\Ops\TradeReportReflector;
use App\Services\Ops\TradeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 사무관리자의 하루도 일일보고에 모이고, ERP 로 넘어간다.
 *
 * 일일보고는 쓰는 곳이 아니라 모이는 곳이다. 반장의 «배관 60%» 가 공정표로 가듯,
 * 사무장의 «후드 샵드로잉 오늘 제출» 은 제출물 대장으로 간다. 다만 승인·반려는
 * 상대의 회신이라 회신 문서를 본 사람이 누르고, 돈이 걸린 청구는 언제나 사람이 본다.
 */
class OfficeReportReflectionTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-04';

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'OF-CO', 'name' => 'Office Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'OF', 'name' => '사무현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        Project::firstOrCreate(['project_code' => 'OF-01'], ['name' => 'OF', 'construction_type' => 'equipment_setting']);
    }

    /** 사무장 — 공종 없이 직책만 있고, 제출물 대장을 고칠 수 있는 관리자 계정. */
    private function officeManager(string $role = 'admin'): User
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '사무장', 'position' => 'office', 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_STAFF,
        ]);
        AttendanceLog::create([
            'employee_id' => $employee->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'attendance_date' => self::DATE, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse(self::DATE.' 07:00', 'America/Phoenix'),
            'source' => 'app', 'status' => 'approved',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => $role, 'account_status' => 'active',
        ]);
    }

    private function submittal(int $seq, string $status = '작성중', string $category = 'Action 제출물'): Submittal
    {
        return Submittal::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'seq' => $seq, 'csi' => '23 3800', 'section' => '주방 배기', 'category' => $category,
            'title' => '배기후드 샵드로잉', 'status' => $status,
        ]);
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

    private function batch(DailyTradeReport $report): OpsIntakeBatch
    {
        return OpsIntakeBatch::create([
            'site_id' => $this->site->id, 'source' => 'ops-room', 'raw_text' => '오늘 사무',
            'image_count' => 0, 'daily_trade_report_id' => $report->id, 'status' => 'done',
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function item(OpsIntakeBatch $batch, array $attrs): OpsIntakeItem
    {
        return OpsIntakeItem::create(array_merge([
            'site_id' => $this->site->id, 'ops_intake_batch_id' => $batch->id, 'source' => 'ops-room',
            'raw_text' => '후드 샵드로잉 오늘 제출했습니다', 'confidence' => 92, 'status' => 'pending',
            'summary' => '배기후드 샵드로잉 제출',
        ], $attrs));
    }

    public function test_the_office_managers_report_lands_in_the_same_daily_report(): void
    {
        $user = $this->officeManager();

        $report = app(TradeReportService::class)->forUserOrCreate($user, self::DATE);

        $this->assertSame('사무', $report->trade);
        $this->assertSame(DailyTradeReport::KIND_OFFICE, $report->kind);
    }

    public function test_a_submitted_shop_drawing_moves_the_register_to_submitted(): void
    {
        $row = $this->submittal(12);
        $user = $this->officeManager();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), [
            'category' => 'submittal', 'target_type' => 'submittal', 'target_code' => '12',
            'proposed' => ['status' => '제출', 'submitted_on' => self::DATE],
        ]);

        $result = app(TradeReportReflector::class)->reflect($report, $user);

        $this->assertSame(1, $result['applied']);
        $fresh = $row->fresh();
        $this->assertSame('제출', $fresh->status);
        $this->assertSame(self::DATE, $fresh->submitted_on->toDateString());

        $item->refresh();
        $this->assertSame('applied', $item->status);
        $this->assertSame(OpsIntakeItem::VIA_REPORT, $item->applied_via);
        $this->assertStringContainsString('서류', (string) $report->fresh()->reflection_note);
    }

    public function test_an_approval_waits_for_the_reply_document(): void
    {
        // «승인 받았다» 는 상대의 회신이다. 말만 듣고 승인으로 넘기면 그 뒤의 발주가
        // 승인 없이 나간다. 회신 문서를 본 사람이 상황실에서 누른다.
        $row = $this->submittal(13, '제출');
        $user = $this->officeManager();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), [
            'category' => 'submittal', 'target_type' => 'submittal', 'target_code' => '13',
            'proposed' => ['status' => '승인', 'approved_on' => self::DATE],
        ]);

        $result = app(TradeReportReflector::class)->reflect($report, $user);

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['held']);
        $this->assertSame('제출', $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_on);
        $this->assertSame('pending', $item->fresh()->status, '사람 차례일 뿐 잘못된 것이 아니다 — 확인 대기에 그대로 남는다');
        $this->assertStringContainsString('회신 문서', (string) $item->fresh()->result_note);
        $this->assertSame(1, $report->fresh()->held_count);
    }

    public function test_a_rejection_also_waits_for_the_reply_document(): void
    {
        $row = $this->submittal(14, '제출');
        $user = $this->officeManager();
        $report = $this->submitted($user);
        $this->item($this->batch($report), [
            'category' => 'submittal', 'target_type' => 'submittal', 'target_code' => '14',
            'proposed' => ['status' => '반려', 'notes' => '두께 재확인 요구'],
        ]);

        app(TradeReportReflector::class)->reflect($report, $user);

        $this->assertSame('제출', $row->fresh()->status);
        $this->assertNull($row->fresh()->notes);
    }

    public function test_a_billing_report_is_money_and_waits_for_a_person(): void
    {
        $user = $this->officeManager();
        $report = $this->submitted($user);
        $item = $this->item($this->batch($report), [
            'category' => 'billing', 'raw_text' => '8월 기성 청구서 냈습니다 $120,000',
            'summary' => '8월 기성 청구서 제출 $120,000',
            'proposed' => ['amount' => 120000, 'reference' => '8월 기성', 'status' => 'submitted'],
        ]);

        $result = app(TradeReportReflector::class)->reflect($report, $user);

        $this->assertSame(0, $result['applied']);
        $this->assertStringContainsString('금액', (string) $item->fresh()->result_note);
    }

    public function test_plain_office_work_is_kept_for_the_report_without_being_a_hold(): void
    {
        // 회의·인허가·인사는 ERP 의 어느 표로도 가지 않는다. 그래도 사라지면 안 된다 —
        // 마감 보고서의 «사무 업무» 가 이것을 싣는다. 다만 «확인 필요» 로 세면 그 숫자는
        // 구조적으로 0 이 안 되고, 매일 울리는 알림은 곧 아무도 안 보는 알림이 된다.
        $user = $this->officeManager();
        $report = $this->submitted($user);
        $batch = $this->batch($report);
        $this->item($batch, ['category' => 'admin', 'summary' => '원청 주간회의 — 후드 납기 협의', 'proposed' => ['title' => '후드 납기 협의']]);
        $this->item($batch, ['category' => 'permit', 'summary' => '카운티 빌딩 퍼밋 승인', 'proposed' => []]);
        $this->item($batch, ['category' => 'hr', 'summary' => '용접공 2명 채용 확정 — 월요일 출근', 'proposed' => ['due_on' => '2026-09-08']]);

        $result = app(TradeReportReflector::class)->reflect($report, $user);

        $this->assertSame(0, $result['applied']);
        $report->refresh();
        $this->assertSame(0, $report->held_count);
        $this->assertStringContainsString('참고 3건', (string) $report->reflection_note);
        $this->assertSame(3, OpsIntakeItem::query()->where('status', 'pending')->count());
    }

    public function test_the_reader_is_given_the_submittal_register_and_keeps_a_real_number(): void
    {
        // 검사 후보 목록에는 시험·검사만 있었다. 사무장이 말하는 «샵드로잉» 은 Action 제출물이라
        // 그 목록에 없고, 목록에 없는 번호는 환각으로 버려졌다 — 대장 전체를 후보로 준다.
        $this->submittal(21, '작성중', 'Action 제출물');
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => [[
                    'raw_text' => '후드 샵드로잉 오늘 제출했습니다', 'speaker' => '', 'category' => 'submittal',
                    'confidence' => 93, 'summary' => '배기후드 샵드로잉 제출', 'target_type' => 'submittal',
                    'target_code' => '21', 'target_name' => '배기후드 샵드로잉', 'occurred_on' => self::DATE,
                    'proposed' => ['status' => '제출', 'submitted_on' => self::DATE], 'question' => '',
                ]]])]]]]],
            ]),
        ]);

        app(OpsIntakeService::class)->ingest('후드 샵드로잉 오늘 제출했습니다', $this->site);

        $item = OpsIntakeItem::query()->firstOrFail();
        $this->assertSame('submittal', $item->category);
        $this->assertSame('21', $item->target_code, '대장 번호가 환각으로 버려지면 안 된다');
        $this->assertSame('pending', $item->status);

        Http::assertSent(function (Request $request): bool {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            return str_contains($prompt, '[등록된 제출물 대장')
                && str_contains($prompt, '[21] 23 3800 주방 배기 — 배기후드 샵드로잉')
                && str_contains($prompt, '- submittal :')
                && str_contains($prompt, '- billing ');
        });
    }

    public function test_office_categories_have_labels_people_can_read(): void
    {
        foreach (OpsIntakeItem::OFFICE_CATEGORIES as $category) {
            $this->assertArrayHasKey($category, OpsIntakeItem::CATEGORY_LABELS, $category.' 에 라벨이 없다');
        }
    }
}
