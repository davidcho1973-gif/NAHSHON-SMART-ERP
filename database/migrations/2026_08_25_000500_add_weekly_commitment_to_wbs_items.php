<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 주간 리듬(Last Planner System) — 이번 주 약속과 미완료 사유.
 *
 * committed_week: 'YYYY-Www' — 이 작업을 그 주에 끝내기로 약속했다는 표시.
 *   주말에 약속 이행률(PPC)이 자동 계산되는 근거다.
 * incomplete_reason: 약속을 못 지킨 표준 사유 코드(LCI Reasons for Variance).
 *   자유 텍스트로 받으면 통계가 안 되고, 통계가 안 되면 "왜 매주 늦는가"에 답할 수 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->string('committed_week', 10)->nullable()->index()->after('progress');
            $table->string('incomplete_reason', 30)->nullable()->after('committed_week');
        });
    }

    public function down(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->dropColumn(['committed_week', 'incomplete_reason']);
        });
    }
};
