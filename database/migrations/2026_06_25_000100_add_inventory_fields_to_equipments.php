<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자재/장비(Inventory)를 위한 소유 구분 + 자산 관리 필드.
 *
 * 같은 `equipments` 레지스트리를 소유/임대 두 화면이 공유한다("통합 백본 + 역할별 렌즈"):
 *  - 장비 렌탈 관리 = acquisition_type '임대'분 (계약·반납·비용)
 *  - 자재/장비 현황판 = 전체(소유+임대), 임대분은 뱃지로 표시
 * 기존 행은 모두 렌탈 스캐너로 들어온 것이라 '임대'로 백필한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->string('acquisition_type', 20)->default('임대')->index()->after('vendor'); // 소유 | 임대 | 리스
            $table->decimal('asset_value', 12, 2)->nullable()->after('acquisition_type');       // 자산가치(소유분)
            $table->date('inspection_due_on')->nullable()->index()->after('asset_value');        // 점검/캘리브레이션 예정일
        });

        // 기존 데이터는 전부 렌탈 등록분 → 명시적으로 '임대'.
        Schema::table('equipments', function (): void {
            \Illuminate\Support\Facades\DB::table('equipments')->update(['acquisition_type' => '임대']);
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropColumn(['acquisition_type', 'asset_value', 'inspection_due_on']);
        });
    }
};
