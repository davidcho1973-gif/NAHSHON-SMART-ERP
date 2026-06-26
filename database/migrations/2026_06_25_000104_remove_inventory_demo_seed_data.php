<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 자재/장비 데모 시드 데이터 정리 — 사용자 요청으로 가짜(시드) 자산을 모두 제거하고
 * 실제 등록 자산만 남긴다.
 *
 * 삭제 대상은 시드 마이그레이션(000101, 000103)이 만든 고정 코드뿐이며,
 * 사용자가 실제 등록한 자산(EQ-### 등 자동코드)은 건드리지 않는다.
 * Idempotent — 이미 없으면 0건 삭제.
 */
return new class extends Migration
{
    /** @var array<int, string> 시드가 생성한 데모 자산 코드 */
    private const DEMO_CODES = [
        'OWN-001', 'OWN-002', 'OWN-003', 'OWN-004',          // 000101 보유자산 데모
        'TOOL-PL1', 'TOOL-EL1', 'TOOL-PW1', 'TOOL-PP1',      // 000103 공구 데모
        'MAT-PP1', 'MAT-EL1',                                 // 000103 자재 데모
    ];

    public function up(): void
    {
        if (! Schema::hasTable('equipments')) {
            return;
        }

        // 데모 장비에 매달린 배정 이력도 함께 제거(FK cascade가 있어도 명시적으로).
        if (Schema::hasTable('equipment_rentals')) {
            $ids = DB::table('equipments')->whereIn('equipment_code', self::DEMO_CODES)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('equipment_rentals')->whereIn('equipment_id', $ids)->delete();
            }
        }

        DB::table('equipments')->whereIn('equipment_code', self::DEMO_CODES)->delete();
    }

    public function down(): void
    {
        // 데모 데이터는 복구하지 않는다(의도적으로 제거된 가짜 데이터).
    }
};
