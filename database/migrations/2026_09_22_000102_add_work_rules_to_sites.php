<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장마다 다른 근무 규칙 — 출퇴근 시각, 정규 시간, 무급 휴게.
 *
 * ── 왜 현장에 두는가 ───────────────────────────────────────────────────
 * 나손 현장은 여러 주에 흩어져 있고 프로젝트마다 작업 시간이 다르다. 그런데 지금
 * 그 규칙은 <b>코드 안의 상수</b>였다(`AttendanceTimesheetSync`: 480분·60분, 자동
 * 퇴근 마감: 회사 전체 한 값). 현장이 하나일 때는 맞았지만, 여러 주에 퍼지는 순간
 * 모든 현장이 남의 시간표로 정산된다.
 *
 * 붙이는 자리는 <b>현장</b>이다. 출퇴근은 현장에서 찍히고, 찍히는 그 순간 이 기록이
 * 어느 «프로젝트» 의 것인지는 게이트가 알 수 없다. 한 현장에 시간표가 다른 프로젝트가
 * 둘 있으면 그건 별개의 문제이고, 그때는 «어느 프로젝트로 출근했는가» 를 먼저 물어야 한다.
 *
 * ── 기본값은 지금 동작 그대로 ──────────────────────────────────────────
 * 480분 정규 · 60분 무급 휴게 · 4시간 초과 근무일에 공제 — 지금 급여가 쓰는 값이다.
 * 기본값을 바꾸면 이 마이그레이션 한 줄이 모든 현장의 임금을 조용히 바꾼다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // 현장 시계 기준의 벽시계다(시간대는 sites.timezone).
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();

            // 하루 정규 근무. 이 시간을 넘긴 만큼이 초과근무가 된다.
            $table->unsignedSmallInteger('regular_minutes')->default(480);

            // 급여에서 빼는 휴게(점심). 「몇 분을」 과 「몇 시간 넘게 일한 날에」 를
            // 따로 둔다 — 반나절 일한 사람의 점심까지 빼면 임금이 잘못 깎인다.
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->unsignedSmallInteger('break_after_minutes')->default(240);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['work_start', 'work_end', 'regular_minutes', 'break_minutes', 'break_after_minutes']);
        });
    }
};
