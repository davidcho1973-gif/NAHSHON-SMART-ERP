<?php

namespace Tests\Feature;

use App\Models\ProcurementItem;
use App\Models\WbsItem;
use App\Services\Procurement\ProcurementService;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 조달 관리 — 발주·조달 공정 자동 추출 + 납기(ETA vs need-by) 여유·지연 판정 + 상태 upsert.
 * 그리고 조달 공정은 '오늘 할 일'에서 제외된다.
 */
class ProcurementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function sub(string $code, string $name, array $attrs = []): WbsItem
    {
        return WbsItem::create(array_merge([
            'project_code' => 'PRC-01', 'level' => 'subtask', 'wbs_code' => $code,
            'activity_id' => $code, 'name' => $name, 'status' => '진행중',
        ], $attrs));
    }

    public function test_detects_procurement_activities_and_excludes_from_today(): void
    {
        // 조달성(인원 없음 + '조달'/'발주') vs 현장 노무(인원 있음).
        $this->sub('PRC-01-A030', '도어/프레임 발주 및 조달', ['crew_size' => 0, 'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(30)->toDateString()]);
        $this->sub('PRC-01-M010', '실내 타프작업', ['crew_size' => 2, 'planned_start' => now()->toDateString(), 'planned_end' => now()->toDateString()]);

        $proc = app(ProcurementService::class)->list('PRC-01', 'ALL');
        $this->assertSame(1, $proc['total']);
        $this->assertSame('PRC-01-A030', $proc['items'][0]['wbs_id']);

        // 조달 공정은 오늘 할 일에서 빠지고 노무만 남는다.
        $today = app(WbsService::class)->todayWork('PRC-01', 'ALL');
        $codes = array_column($today['items'], 'wbs_id');
        $this->assertContains('PRC-01-M010', $codes);
        $this->assertNotContains('PRC-01-A030', $codes);
    }

    public function test_late_eta_on_critical_path_raises_critical_alert(): void
    {
        $this->sub('PRC-01-A080', '기계설비 장비 조달', [
            'crew_size' => 0, 'is_critical' => true,
            'planned_end' => now()->addDays(5)->toDateString(), // need-by = 5일 뒤
        ]);
        // ETA 를 납기보다 늦게(10일 뒤) 입력 → 지연.
        app(ProcurementService::class)->update('PRC-01', 'PRC-01-A080', [
            'status' => '선적중', 'eta' => now()->addDays(10)->toDateString(), 'vendor' => 'LG 본사',
        ], 'ALL');

        $row = app(ProcurementService::class)->list('PRC-01', 'ALL')['items'][0];
        $this->assertSame('late', $row['delay']);
        $this->assertSame('critical', $row['alert']);   // 임계경로 + 지연
        $this->assertTrue($row['slack'] < 0);
        $this->assertSame('선적중', $row['status']);
        $this->assertSame('LG 본사', $row['vendor']);
    }

    public function test_update_records_order_date_when_advancing_status(): void
    {
        $this->sub('PRC-01-A040', '유리 조달', ['crew_size' => 0, 'planned_end' => now()->addDays(20)->toDateString()]);
        app(ProcurementService::class)->update('PRC-01', 'PRC-01-A040', ['status' => '발주완료'], 'ALL');

        $item = ProcurementItem::where('wbs_code', 'PRC-01-A040')->first();
        $this->assertSame('발주완료', $item->status);
        $this->assertNotNull($item->ordered_on); // 발주로 넘어가면 발주일 자동 기록
    }
}
