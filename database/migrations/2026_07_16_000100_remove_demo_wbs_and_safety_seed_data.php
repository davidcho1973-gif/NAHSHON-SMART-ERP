<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 공정관리(WBS) 데모 데이터 정리 — 실 프로젝트 운영 준비.
 *
 * 삭제 대상:
 * - HFF-02 데모 WBS 트리 전체 (2026_06_24_000021 시드 + 그 위에서 AI 재생성된 파생 행 포함).
 *   HFF-02 는 시연용 가짜 프로젝트 코드로, projects 테이블의 실제 프로젝트 코드는
 *   모델이 "{CLIENT}-{STATE}-{YEAR}-{NNN}" 형식으로 자동 생성하므로 충돌하지 않는다.
 * - 데모 안전 작업카드 3건 (WRK-2605-001~003, 2026_06_24_000011 시드) 및 그 서명/이슈.
 *   사용자가 만든 실제 카드는 'WRK-<timestamp>' 코드라 건드리지 않는다.
 *
 * Idempotent — 이미 없으면 0건 삭제. down()은 no-op (가짜 데이터는 복원하지 않는다).
 */
return new class extends Migration
{
    /** @var array<int, string> 시드가 생성한 데모 안전 작업카드 코드 */
    private const DEMO_SAFETY_CODES = ['WRK-2605-001', 'WRK-2605-002', 'WRK-2605-003'];

    public function up(): void
    {
        if (Schema::hasTable('wbs_items')) {
            DB::table('wbs_items')->where('project_code', 'HFF-02')->delete();

            // 다른 프로젝트 WBS 가 삭제될 데모 안전카드를 가리키고 있으면 링크만 해제.
            DB::table('wbs_items')
                ->whereIn('safety_work_code', self::DEMO_SAFETY_CODES)
                ->update(['safety_work_code' => null]);
        }

        if (Schema::hasTable('safety_work_items')) {
            $ids = DB::table('safety_work_items')
                ->whereIn('work_code', self::DEMO_SAFETY_CODES)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                // FK cascade 가 있어도 명시적으로 자식부터 제거 (inventory 정리 선례와 동일).
                if (Schema::hasTable('safety_work_signatures')) {
                    DB::table('safety_work_signatures')->whereIn('safety_work_item_id', $ids)->delete();
                }
                if (Schema::hasTable('safety_work_issues')) {
                    DB::table('safety_work_issues')->whereIn('safety_work_item_id', $ids)->delete();
                }
                DB::table('safety_work_items')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // 데모 데이터는 복구하지 않는다(의도적으로 제거된 가짜 데이터).
    }
};
