<?php

use Illuminate\Database\Migrations\Migration;

/**
 * [폐기됨] 회사 소유 자산 데모 시드 — 사용자 요청으로 데모 데이터 생성을 제거했다.
 * 자재/장비 현황판은 실제 등록 자산만 보여준다. 생성됐던 데모 행(OWN-001..004)은
 * 2026_06_25_000104_remove_inventory_demo_seed_data 에서 삭제한다.
 *
 * 마이그레이션 히스토리 일관성을 위해 파일은 유지하되 동작은 no-op으로 둔다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // no-op — 데모 시드 폐기됨.
    }

    public function down(): void
    {
        // no-op.
    }
};
