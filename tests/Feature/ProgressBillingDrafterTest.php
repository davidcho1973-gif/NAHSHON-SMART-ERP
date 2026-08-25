<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PayApplication;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Finance\ProgressBillingDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 기성 자동 초안 — 공정률(Σ 배분원가×진척률)이 draft 회차가 되는가.
 */
class ProgressBillingDrafterTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $us = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $them = Company::create(['code' => 'GC', 'name' => 'GC', 'status' => 'active']);
        $site = Site::create(['code' => 'S1', 'name' => '현장', 'status' => 'active']);
        $project = Project::create(['project_code' => 'BILL-01', 'name' => '기성 테스트', 'construction_type' => 'equipment_setting']);

        $this->contract = ProjectContract::create([
            'company_id' => $us->id, 'counterparty_company_id' => $them->id,
            'site_id' => $site->id, 'project_id' => $project->id,
            'title' => '전기공사', 'direction' => 'receivable', 'status' => 'active',
            'original_amount' => 100000, 'retainage_percent' => 10,
        ]);

        // 공정표: 배분원가 $60,000 + $40,000. A 는 완료(100%), B 는 25%.
        $stage = WbsItem::create(['project_code' => 'BILL-01', 'level' => 'stage', 'wbs_code' => 'BILL-01-S-1', 'name' => 'S', 'sort_order' => 0]);
        $task = WbsItem::create(['project_code' => 'BILL-01', 'level' => 'task', 'parent_id' => $stage->id, 'wbs_code' => 'BILL-01-T-1', 'name' => 'T', 'sort_order' => 0]);
        WbsItem::create(['project_code' => 'BILL-01', 'level' => 'subtask', 'parent_id' => $task->id, 'wbs_code' => 'BILL-01-W-A',
            'activity_id' => 'A', 'name' => '배관', 'planned_cost' => 60000, 'status' => '완료', 'progress' => 100, 'sort_order' => 1]);
        WbsItem::create(['project_code' => 'BILL-01', 'level' => 'subtask', 'parent_id' => $task->id, 'wbs_code' => 'BILL-01-W-B',
            'activity_id' => 'B', 'name' => '결선', 'planned_cost' => 40000, 'status' => '진행중', 'progress' => 25, 'sort_order' => 2]);

        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    private function draft(): array
    {
        return app(ProgressBillingDrafter::class)->draft($this->contract->id, '2026-08-31');
    }

    public function test_공정률이_draft_회차가_된다(): void
    {
        $r = $this->draft();

        $this->assertTrue($r['success'], $r['error'] ?? '');
        // 기성고 = 60,000×100% + 40,000×25% = $70,000. 유보 10% → 청구액 $63,000.
        $this->assertSame(70000.0, $r['earned']);
        $this->assertSame(70000.0, $r['thisPeriod']);
        $this->assertSame(63000.0, $r['amountDue']);

        $app = PayApplication::find($r['id']);
        $this->assertSame('draft', $app->status, '자동은 초안까지 — 제출·승인은 사람이 한다');
        $this->assertStringStartsWith('wbs-progress:', (string) $app->source_ref);
        $this->assertStringContainsString('자동 초안', (string) $app->notes);
    }

    public function test_공정이_더_진행되면_같은_초안이_갱신된다(): void
    {
        $first = $this->draft();

        WbsItem::query()->where('wbs_code', 'BILL-01-W-B')->update(['progress' => 50]);
        $second = $this->draft();

        $this->assertTrue($second['updated'], '초안이 쌓이면 어느 것이 진짜인지 모른다 — 갱신이어야 한다');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(80000.0, $second['earned']); // 60,000 + 40,000×50%
        $this->assertSame(1, PayApplication::query()->where('project_contract_id', $this->contract->id)->count());
    }

    public function test_이미_청구한_누계는_빼고_금회분만_잡는다(): void
    {
        // 1회차(수동, 승인)로 $50,000 이 이미 청구된 상태.
        PayApplication::create([
            'project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'site_id' => $this->contract->site_id, 'project_id' => $this->contract->project_id,
            'type' => 'progress', 'status' => 'approved', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'this_period_amount' => 50000, 'cumulative_amount' => 50000, 'retainage_percent' => 10,
            'retainage_held' => 5000, 'earned_less_retainage' => 45000, 'previous_certificates' => 0, 'amount_due' => 45000,
        ]);

        $r = $this->draft();

        $this->assertTrue($r['success']);
        $this->assertSame(20000.0, $r['thisPeriod'], '기성고 70,000 − 기청구 50,000');
    }

    public function test_신규_기성이_없으면_초안을_만들지_않는다(): void
    {
        PayApplication::create([
            'project_contract_id' => $this->contract->id, 'company_id' => $this->contract->company_id,
            'type' => 'progress', 'status' => 'approved', 'period_end' => '2026-07-31',
            'this_period_amount' => 70000, 'cumulative_amount' => 70000, 'retainage_percent' => 10,
            'retainage_held' => 7000, 'earned_less_retainage' => 63000, 'previous_certificates' => 0, 'amount_due' => 63000,
        ]);

        $r = $this->draft();

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('신규 기성이 없습니다', $r['error']);
    }

    public function test_권한_없는_사용자는_초안을_만들_수_없다(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']));

        $r = $this->draft();

        $this->assertFalse($r['success']);
        $this->assertSame(0, PayApplication::query()->count());
    }
}
