<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Team;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\DecisionReplyConnector;
use App\Services\Finance\ExpenseReviewService;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 연계 수선 R3 — 소통 회로: 결정이 방으로 돌아온다.
 */
class IntegrationRepairR3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private CommunicationRoom $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => '자사', 'status' => 'active']);
        $this->site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active', 'company_id' => $this->company->id]);
        $this->room = CommunicationRoom::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_OPS, 'name' => '상황실', 'status' => 'active',
        ]);
    }

    private function roomMessage(string $body = '보고'): CommunicationMessage
    {
        return CommunicationMessage::create([
            'communication_room_id' => $this->room->id, 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'kind' => 'text', 'body' => $body, 'status' => 'active',
        ]);
    }

    public function test_상황실_경비의_승인_결과가_그_메시지_답글로_돌아온다(): void
    {
        $origin = $this->roomMessage('홈디포 자재 $120 영수증');
        $item = OpsIntakeItem::create([
            'site_id' => $this->site->id, 'source' => 'room', 'communication_message_id' => $origin->id,
            'raw_text' => '영수증', 'category' => 'expense', 'confidence' => 90, 'summary' => '자재 구매',
            'target_type' => 'expense', 'target_code' => 'EXP',
            'proposed' => ['amount' => 120, 'vendor' => 'Home Depot'], 'status' => 'pending',
        ]);
        $applied = app(\App\Services\Ops\OpsModuleRouter::class)->applyExpense($item);
        $expense = MobileExpense::find($applied['expenseId']);

        $reviewer = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        app(ExpenseReviewService::class)->review($expense, 'approved', $reviewer);

        $reply = CommunicationMessage::query()->where('parent_id', $origin->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)->first();
        $this->assertNotNull($reply, '방 답글이 영원히 "승인대기"에 멈춰 있으면 안 된다');
        $this->assertStringContainsString('경비 승인', $reply->body);
        $this->assertStringContainsString('120', $reply->body);

        // 같은 결정으로 두 번 답하지 않는다.
        app(ExpenseReviewService::class)->review($expense->fresh(), 'approved', $reviewer);
        $this->assertSame(1, CommunicationMessage::query()->where('parent_id', $origin->id)->count());

        // 반려로 바뀌면 그건 새 결정이다 — 새 답글이 간다.
        $expense->fresh()->update(['rejection_reason' => '증빙 불명확']);
        app(ExpenseReviewService::class)->review($expense->fresh(), 'rejected', $reviewer);
        $this->assertSame(2, CommunicationMessage::query()->where('parent_id', $origin->id)->count());
        $this->assertStringContainsString('증빙 불명확', CommunicationMessage::query()->where('parent_id', $origin->id)->latest('id')->first()->body);
    }

    public function test_공정_반영_결과가_보고_메시지_답글로_돌아온다(): void
    {
        $origin = $this->roomMessage('천장 배관 60% 진행');
        WbsItem::create([
            'project_code' => 'R3-P', 'level' => 'subtask', 'wbs_code' => 'R3-P-W-A100',
            'name' => '천장 배관', 'status' => '진행중', 'progress' => 30, 'sort_order' => 1,
        ]);
        $item = OpsIntakeItem::create([
            'site_id' => $this->site->id, 'source' => 'room', 'communication_message_id' => $origin->id,
            'raw_text' => '천장 배관 60%', 'category' => 'progress', 'confidence' => 90, 'summary' => '진행률 60%',
            'target_type' => 'wbs', 'target_code' => 'R3-P-W-A100', 'target_name' => '천장 배관',
            'proposed' => ['progress' => 60], 'status' => 'pending',
        ]);

        $r = app(OpsIntakeService::class)->apply($item->id);

        $this->assertTrue($r['success']);
        $reply = CommunicationMessage::query()->where('parent_id', $origin->id)->first();
        $this->assertNotNull($reply, '"반영됐나요?"를 묻지 않게 결과가 방으로 돌아와야 한다');
        $this->assertStringContainsString('공정표 반영 완료', $reply->body);
    }

    public function test_신규_직원은_회사방과_팀방에_자동_가입된다(): void
    {
        $team = Team::create(['company_id' => $this->company->id, 'site_id' => $this->site->id, 'code' => 'T1', 'name' => '배관1팀', 'status' => 'active']);

        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'team_id' => $team->id,
            'first_name' => '신', 'last_name' => '입', 'name' => '신입', 'employment_status' => 'active',
        ]);

        $memberships = CommunicationRoomMember::query()->where('employee_id', $employee->id)->get();
        $this->assertSame(2, $memberships->count(), '함수는 있는데 부르는 곳이 0곳이던 구멍(점검 ⑮)');
        $types = CommunicationRoom::query()->whereIn('id', $memberships->pluck('communication_room_id'))->pluck('type')->all();
        $this->assertEqualsCanonicalizing([CommunicationRoom::TYPE_COMPANY, CommunicationRoom::TYPE_TEAM], $types);
    }

    public function test_조달_ETA_초과가_알림_센터에_실린다(): void
    {
        ProcurementItem::create([
            'project_code' => 'R3-P', 'site_id' => $this->site->id, 'wbs_code' => 'R3-P-W-A100',
            'po_no' => 'PO-55', 'status' => '발주완료', 'eta' => now()->subDays(3)->toDateString(),
        ]);

        app(\App\Services\Alerts\UnifiedAlertService::class)->refreshKnownModules();

        $alert = UnifiedAlert::query()->where('event_type', 'procurement_late')->first();
        $this->assertNotNull($alert, '조달은 알림 6갈래 어디에도 발원이 없었다');
        $this->assertStringContainsString('PO-55', $alert->title);
        $this->assertSame('PROC', $alert->source_module);
    }

    public function test_승인_대기_적체가_알림으로_잡힌다(): void
    {
        $stale = MobileExpense::create([
            'site_id' => $this->site->id, 'payment_type' => 'corporate', 'category' => '5201 Job Materials',
            'description' => '오래된 대기 건', 'amount' => 300, 'expense_date' => now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);
        $stale->forceFill(['created_at' => now()->subDays(10)])->save();

        app(\App\Services\Alerts\UnifiedAlertService::class)->refreshKnownModules();

        $this->assertSame(1, UnifiedAlert::query()->where('event_type', 'expense_backlog')->count());
    }
}
