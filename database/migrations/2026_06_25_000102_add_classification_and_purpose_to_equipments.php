<?php

use App\Models\Equipment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 자재/장비 재설계:
 *  - 기능별 2단계 분류: category_group(자재/공구/장비/안전/가설) + trade(플러밍/전기/파워툴/배관 …)
 *  - 취득 목적 추적: project_id(왜 샀나) + purchased_for_site_id(취득 목적 현장) — 현 위치(site_id)와 분리
 *
 * '총 자산가치' 중심에서 '어느 프로젝트/현장용으로 구매·임대했고, 지금 누가·어디서 쓰는가' 중심으로 전환한다.
 * 기존 행은 라벨(equipment_type)에서 분류를 백필하고, 취득 목적 현장은 현 위치로 1회 추정해 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->string('category_group', 20)->nullable()->index()->after('equipment_type'); // 자재 | 공구 | 장비 | 안전 | 가설
            $table->string('trade', 30)->nullable()->index()->after('category_group');           // plumbing | electrical | power_tool …
            $table->foreignId('project_id')->nullable()->after('site_id')
                ->constrained('projects')->nullOnDelete();                                        // 취득 목적 프로젝트
            $table->foreignId('purchased_for_site_id')->nullable()->after('project_id')
                ->constrained('sites')->nullOnDelete();                                           // 취득 목적 현장
        });

        // 기존 데이터 백필 — 데모/운영 행만(테스트 DB는 비어 있어 영향 없음).
        Equipment::query()->chunkById(200, function ($rows): void {
            foreach ($rows as $e) {
                $c = Equipment::classify($e->equipment_type);
                DB::table('equipments')->where('id', $e->id)->update([
                    'category_group' => $c['group'],
                    'trade' => $c['trade'],
                    // 취득 목적 현장 미상 → 현 위치로 1회 추정(이후 등록분은 명시 입력).
                    'purchased_for_site_id' => $e->purchased_for_site_id ?? $e->site_id,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('purchased_for_site_id');
            $table->dropColumn(['category_group', 'trade']);
        });
    }
};
