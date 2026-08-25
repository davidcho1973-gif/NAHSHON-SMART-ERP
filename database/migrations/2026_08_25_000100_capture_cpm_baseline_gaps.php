<?php

use App\Models\WbsItem;
use App\Services\Wbs\CpmEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * CPM 엔진 도입 시점의 기준 간격(선행 종료 → 후속 시작) 최초 포착.
 *
 * 엔진은 "처음 저장된 날짜"를 공정 논리의 기준선으로 삼는다. 이 마이그레이션이 없으면
 * 배포 전에 수입된 공정표는 첫 편집 순간에야 기준선을 포착하는데, 그때는 이미 편집된
 * 날짜가 섞여 있어 기준선이 오염된다. 배포 시점(아직 아무도 안 고친 상태)에 한 번
 * 돌려 두면 그 문제가 원천적으로 없다. 스키마 변경 없음 — 데이터 백필이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $codes = WbsItem::query()->whereNotNull('project_code')->distinct()->pluck('project_code');
            $engine = app(CpmEngine::class);

            foreach ($codes as $code) {
                try {
                    $engine->recompute((string) $code);
                } catch (Throwable $e) {
                    Log::warning("CPM 기준선 포착 실패({$code}): ".$e->getMessage());
                }
            }
        } catch (Throwable $e) {
            // 백필 실패가 배포를 막으면 안 된다 — 매일 새벽 wbs:recompute-cpm 이 다시 시도한다.
            Log::warning('CPM 기준선 포착 건너뜀: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        // 데이터 백필 — 되돌릴 것 없음.
    }
};
