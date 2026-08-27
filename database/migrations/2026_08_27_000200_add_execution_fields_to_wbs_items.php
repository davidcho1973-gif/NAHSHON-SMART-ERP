<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WBS 실행 필드 — 계획(예정)만 있던 공정표에 "실제로 벌어진 일"과 "검측 게이트"를 붙인다.
 *
 * - actual_start/actual_end: 실적 일자. 지연 분석과 기성 근거는 예정이 아니라 실적에서 나온다.
 * - hold_point: 검측·시험을 통과해야 완료할 수 있는 작업(그리스덕트 누기시험, 바닥 수분시험 등).
 *   hold_released 전에는 상태를 '완료'로 못 바꾼다 — 서비스 게이트가 막는다.
 * - submittal_seqs: 이 작업과 얽힌 제출물 대장 번호들(예: 양생 → 수분시험 성적서).
 * - boq_items.wbs_activity_id: 물량 라인을 공정 액티비티에 귀속 — 기성 SOV 분해의 뿌리.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->date('actual_start')->nullable()->after('planned_end');
            $table->date('actual_end')->nullable()->after('actual_start');
            $table->boolean('hold_point')->default(false)->after('progress');
            $table->boolean('hold_released')->default(false)->after('hold_point');
            $table->string('hold_note', 200)->nullable()->after('hold_released');
            $table->json('submittal_seqs')->nullable()->after('hold_note');
        });

        Schema::table('boq_items', function (Blueprint $table): void {
            $table->string('wbs_activity_id', 40)->nullable()->index()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table): void {
            $table->dropColumn('wbs_activity_id');
        });
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->dropColumn(['actual_start', 'actual_end', 'hold_point', 'hold_released', 'hold_note', 'submittal_seqs']);
        });
    }
};
