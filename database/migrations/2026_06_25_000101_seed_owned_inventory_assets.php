<?php

use App\Models\Equipment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 회사 소유 자산 데모 시드 — 자재/장비 현황판이 비어 보이지 않도록 + 소유/임대 혼합을 보여준다.
 * 렌탈로 등록된 임대 장비는 그대로 두고, 여기서는 '소유' 자산만 추가한다. Idempotent(equipment_code 기준).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Demo data only — never pollute the test database (other suites count `equipments`).
        if (app()->environment('testing') || ! Schema::hasTable('equipments')) {
            return;
        }

        foreach (self::assets() as $a) {
            if (Equipment::query()->where('equipment_code', $a['equipment_code'])->exists()) {
                continue;
            }
            Equipment::query()->create($a);
        }
    }

    public function down(): void
    {
        // Demo data left in place on rollback.
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function assets(): array
    {
        $soon = now()->addDays(12)->toDateString();
        $later = now()->addDays(90)->toDateString();

        return [
            ['equipment_code' => 'OWN-001', 'equipment_type' => 'Heavy Equipment (중장비)', 'model' => 'Genie S-65 Boom Lift', 'vendor' => 'NAHSHON 보유', 'acquisition_type' => '소유', 'asset_value' => 78000, 'status' => '사용중', 'inspection_due_on' => $soon, 'registration_method' => 'manual'],
            ['equipment_code' => 'OWN-002', 'equipment_type' => 'Power Tool (전동공구)', 'model' => 'Hilti TE 70 Core Drill', 'vendor' => 'NAHSHON 보유', 'acquisition_type' => '소유', 'asset_value' => 2400, 'status' => '대기중', 'inspection_due_on' => $later, 'registration_method' => 'manual'],
            ['equipment_code' => 'OWN-003', 'equipment_type' => 'Welding Machine (용접기)', 'model' => 'Miller XMT 350', 'vendor' => 'NAHSHON 보유', 'acquisition_type' => '소유', 'asset_value' => 5200, 'status' => '정비중', 'inspection_due_on' => $soon, 'registration_method' => 'manual'],
            ['equipment_code' => 'OWN-004', 'equipment_type' => 'Generator & Power (발전기/동력원)', 'model' => 'CAT XQ60 Generator', 'vendor' => 'NAHSHON 보유', 'acquisition_type' => '소유', 'asset_value' => 31000, 'status' => '사용중', 'registration_method' => 'manual'],
        ];
    }
};
