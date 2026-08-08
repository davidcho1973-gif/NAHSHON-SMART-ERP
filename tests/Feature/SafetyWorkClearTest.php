<?php

namespace Tests\Feature;

use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\Site;
use App\Models\User;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 안전관리 전체 초기화 — 새 프로젝트 시작 시 작업 카드(서명·이슈 포함)를 깨끗이 비운다.
 */
class SafetyWorkClearTest extends TestCase
{
    use RefreshDatabase;

    private function card(?int $siteId, string $code): SafetyWorkItem
    {
        $item = SafetyWorkItem::create([
            'work_code' => $code, 'site_id' => $siteId, 'project' => 'P', 'title' => 'T', 'work_date' => '2026-07-20',
        ]);
        SafetyWorkSignature::create(['safety_work_item_id' => $item->id, 'name' => '김철수', 'signed' => true]);
        SafetyWorkIssue::create(['safety_work_item_id' => $item->id, 'type' => 'risk', 'body' => '이슈']);

        return $item;
    }

    public function test_clear_all_removes_cards_signatures_and_issues(): void
    {
        $this->card(null, 'WRK-1');
        $this->card(null, 'WRK-2');

        $result = app(SafetyWorkService::class)->clearAll('ALL');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['deleted']['cards']);
        $this->assertSame(0, SafetyWorkItem::count());
        $this->assertSame(0, SafetyWorkSignature::count());
        $this->assertSame(0, SafetyWorkIssue::count());
    }

    public function test_clear_is_scoped_by_site(): void
    {
        $a = Site::create(['code' => 'ST-A', 'name' => 'A', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $b = Site::create(['code' => 'ST-B', 'name' => 'B', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->card($a->id, 'WRK-A');
        $this->card($b->id, 'WRK-B');

        app(SafetyWorkService::class)->clearAll('ST-A');

        $this->assertNull(SafetyWorkItem::where('work_code', 'WRK-A')->first());
        $this->assertNotNull(SafetyWorkItem::where('work_code', 'WRK-B')->first());
        $this->assertSame(1, SafetyWorkSignature::count()); // B의 서명만 남음
    }

    public function test_clear_api_allows_active_admin(): void
    {
        $this->card(null, 'WRK-ADMIN-CLEAR');
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($admin)->postJson('/smart-company-api/api_clearSafetyWork', [
            'siteId' => 'ALL',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deleted.cards', 1)
            ->assertJsonPath('deleted.signatures', 1)
            ->assertJsonPath('deleted.issues', 1);
        $this->assertSame(0, SafetyWorkItem::count());
    }

    public function test_clear_api_denies_non_admin_and_preserves_legal_records(): void
    {
        // TBM 서명은 법적 기록이다. 되돌릴 수 없는 전체 삭제는 ERP 관리자만 할 수 있어야 한다 —
        // 화면의 확인창은 권한 경계가 아니다.
        $this->card(null, 'WRK-PROTECTED');
        $safetyManager = User::factory()->create([
            'access_role' => 'safety_manager',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($safetyManager)->postJson('/smart-company-api/api_clearSafetyWork', [
            'siteId' => 'ALL',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', '안전관리 전체 초기화 권한이 없습니다.');
        $this->assertSame(1, SafetyWorkItem::count());
        $this->assertSame(1, SafetyWorkSignature::count());
        $this->assertSame(1, SafetyWorkIssue::count());
    }
}
