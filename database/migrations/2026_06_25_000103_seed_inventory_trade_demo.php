<?php

use App\Models\Equipment;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 기능 분류(플러밍/전기/파워툴/배관 자재) 데모 시드 — 재설계된 자재/장비 화면이
 * 2단계 분류·취득 목적(프로젝트/현장)·구매/임대 구분을 실제로 보여주도록 한다.
 * Idempotent(equipment_code 기준). 테스트 DB는 건드리지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('equipments')) {
            return;
        }

        $siteId = Site::query()->orderBy('id')->value('id');
        $projectId = Schema::hasTable('projects') ? Project::query()->orderBy('id')->value('id') : null;
        $soon = now()->addDays(18)->toDateString();

        foreach (self::assets($siteId, $projectId, $soon) as $a) {
            if (Equipment::query()->where('equipment_code', $a['equipment_code'])->exists()) {
                continue;
            }
            Equipment::query()->create($a);
        }
    }

    public function down(): void
    {
        // 데모 데이터는 롤백 시 그대로 둔다.
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function assets(?int $siteId, ?int $projectId, string $soon): array
    {
        $base = [
            'acquisition_type' => '소유',
            'status' => '대기중',
            'registration_method' => 'manual',
            'project_id' => $projectId,
            'purchased_for_site_id' => $siteId,
        ];

        return [
            $base + ['equipment_code' => 'TOOL-PL1', 'equipment_type' => 'Plumbing Tool (플러밍 공구)', 'category_group' => 'tool', 'trade' => 'plumbing', 'model' => 'RIDGID 300 Threader', 'vendor' => 'NAHSHON 보유', 'asset_value' => 3200, 'site_id' => $siteId, 'status' => '사용중'],
            $base + ['equipment_code' => 'TOOL-EL1', 'equipment_type' => 'Electrical Tool (전기 공구)', 'category_group' => 'tool', 'trade' => 'electrical', 'model' => 'Klein Crimper Set', 'vendor' => 'NAHSHON 보유', 'asset_value' => 850],
            $base + ['equipment_code' => 'TOOL-PW1', 'equipment_type' => 'Power Tool (전동공구)', 'category_group' => 'tool', 'trade' => 'power_tool', 'model' => 'Milwaukee M18 Impact', 'vendor' => 'NAHSHON 보유', 'asset_value' => 480, 'inspection_due_on' => $soon],
            $base + ['equipment_code' => 'TOOL-PP1', 'equipment_type' => 'Piping Tool (배관 공구)', 'category_group' => 'tool', 'trade' => 'piping', 'model' => 'Victaulic Groover', 'vendor' => 'NAHSHON 보유', 'asset_value' => 5600, 'site_id' => $siteId, 'status' => '사용중'],
            $base + ['equipment_code' => 'MAT-PP1', 'equipment_type' => 'Pipes & Fittings (배관 자재)', 'category_group' => 'material', 'trade' => 'piping', 'model' => 'CS Pipe 6" SCH40', 'vendor' => '-', 'is_bulk' => true, 'quantity' => 120, 'site_id' => $siteId],
            $base + ['equipment_code' => 'MAT-EL1', 'equipment_type' => 'Conduit & Electrical (전선관/전기 자재)', 'category_group' => 'material', 'trade' => 'electrical', 'model' => 'EMT Conduit 3/4"', 'vendor' => '-', 'is_bulk' => true, 'quantity' => 240],
        ];
    }
};
