<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 경비를 프로젝트 원가에 귀속시킨다.
 *
 * 지금까지 경비의 귀속 최소 단위는 현장(site_id)이었다. 그래서 WBS 의 계획원가
 * (planned_cost)와 계약금액(current_amount)은 있는데, 대응하는 "실제 얼마 썼나"를
 * 프로젝트 단위로 모을 방법이 없었다 — 실행예산 대비 실적은 엑셀에서 다시 만들었다.
 *
 * source_ref 는 자동 생성 경비의 멱등 키다. 장비 임대료·숙소 월세를 매월 자동으로
 * 원장에 넣을 때(RentalExpenseConnector) 같은 달을 두 번 만들지 않기 위한 표식:
 * "equipment:{id}:2026-08" / "housing:{id}:2026-08".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('site_id')->constrained('projects')->nullOnDelete();
            $table->string('wbs_code', 120)->nullable()->after('project_id')->index();
            $table->string('source_ref', 120)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['wbs_code', 'source_ref']);
        });
    }
};
