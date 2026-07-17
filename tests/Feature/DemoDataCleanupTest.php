<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 데모(가짜) 시드 데이터가 마이그레이션 완주 후 남아있지 않음을 보증.
 *
 * 시드 마이그레이션(2026_06_24_000011, 000021)은 히스토리 보존을 위해 그대로 두고,
 * 정리 마이그레이션(2026_07_16_000100)이 뒤에서 지우는 구조다 — 이 테스트가 그
 * 순서가 앞으로도 깨지지 않도록 지킨다.
 */
class DemoDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_wbs_tree_is_absent_after_migrations(): void
    {
        $this->assertSame(
            0,
            DB::table('wbs_items')->where('project_code', 'HFF-02')->count(),
            'HFF-02 데모 WBS 가 정리 마이그레이션 이후에도 남아 있습니다.'
        );
    }

    public function test_demo_safety_work_cards_are_absent_after_migrations(): void
    {
        $demoCodes = ['WRK-2605-001', 'WRK-2605-002', 'WRK-2605-003'];

        $this->assertSame(
            0,
            DB::table('safety_work_items')->whereIn('work_code', $demoCodes)->count(),
            '데모 안전 작업카드가 정리 마이그레이션 이후에도 남아 있습니다.'
        );

        // 링크 방향이 카드 → 공정으로 바뀐 뒤에도(2026_07_16_000210) 데모 잔재가 없어야 한다.
        $this->assertSame(
            0,
            DB::table('safety_work_items')->whereIn('work_code', $demoCodes)->whereNotNull('wbs_code')->count(),
            '삭제된 데모 안전카드의 공정 링크가 남아 있습니다.'
        );
    }
}
