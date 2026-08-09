<?php

namespace Tests\Feature;

use App\Models\Item;
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

    public function test_품목_마스터를_연결하고_해제할_수_있다(): void
    {
        // 품목 마스터는 등록만 되고 아무도 안 읽는 고립 모듈이었다 — 조달이 첫 소비자다.
        $this->sub('PRC-01-A090', 'EMT 전선관 자재 조달', ['crew_size' => 0]);
        $item = Item::create(['name' => '3/4in EMT Conduit', 'unit' => 'EA', 'standard_cost' => 3.5, 'status' => 'active']);

        app(ProcurementService::class)->update('PRC-01', 'PRC-01-A090', [
            'status' => '발주완료', 'item_id' => $item->id,
        ], 'ALL');

        $row = app(ProcurementService::class)->list('PRC-01', 'ALL')['items'][0];
        $this->assertSame($item->id, $row['itemId']);
        $this->assertSame('3/4in EMT Conduit', $row['itemName']);

        // 빈 값이면 연결 해제. 없는 id 도 연결하지 않는다.
        app(ProcurementService::class)->update('PRC-01', 'PRC-01-A090', ['item_id' => ''], 'ALL');
        $this->assertNull(app(ProcurementService::class)->list('PRC-01', 'ALL')['items'][0]['itemId']);

        app(ProcurementService::class)->update('PRC-01', 'PRC-01-A090', ['item_id' => 999999], 'ALL');
        $this->assertNull(app(ProcurementService::class)->list('PRC-01', 'ALL')['items'][0]['itemId']);
    }
}
