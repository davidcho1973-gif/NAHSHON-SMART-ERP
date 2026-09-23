<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이번 주 작업판 — 현장이 <b>자기 말로</b> 적는 한 주의 일.
 *
 * ── 왜 공정표(wbs_items)에 넣지 않는가 ──────────────────────────────────
 * 공정표는 원청에 내는 정식 문서다. 액티비티 80개, 한 줄에 칸 40개, 코드(A030)와
 * 선행·여유·임계경로가 붙어 있다. 서류로는 맞지만 현장이 아침에 보는 물건이 아니다 —
 * 사장 말로 「내용도 틀리고, 너무 전문적이고, 너무 잘게 쪼개져 있다」.
 *
 * 현장이 한 주를 잡는 단위는 <b>공종 × 하는 일 × 인원</b>이다. 「배관 2명 — 급탕 배관」.
 * 그 단위가 시스템에 없어서, 사람은 머릿속에서 매번 공정표를 번역해야 했다. 번역이
 * 필요한 화면은 아무도 안 본다. 그래서 그 단위를 그대로 담는 표를 둔다.
 *
 * ── 공정표와의 관계 ────────────────────────────────────────────────────
 * 작업판 줄은 공정표 액티비티에 <b>붙을 수도 있고 안 붙을 수도 있다</b>(wbs_codes).
 * 붙어 있으면 줄이 끝날 때 그 액티비티가 완료로 넘어가 진척률이 올라간다. 안 붙어
 * 있으면 아무 일도 없다. 둘을 맞추는 동기화 층은 두지 않는다 — 작업판은 현장의
 * 사실이고 공정표는 원청의 문서이며, 둘이 다른 것은 정상이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('week_board_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // 그 주의 월요일(현장 시계 기준). 주는 월요일로 시작한다.
            $table->date('week_start');

            // 공종 — 직원의 공종(employees.role)과 같은 낱말을 쓴다(일일보고와 한 어휘).
            $table->string('trade', 60);

            // 하는 일 — 현장이 부르는 이름 그대로. 코드가 아니다.
            $table->string('task', 255);

            $table->decimal('headcount', 4, 1)->nullable();

            // planned(예정) · doing(진행중) · done(완료) · blocked(못함)
            $table->string('status', 16)->default('planned');
            // 못한 이유 — 자유 텍스트. 통계보다 다음 주 월요일에 읽는 용도다.
            $table->string('reason', 255)->nullable();
            $table->text('note')->nullable();

            // 정식 공정표 액티비티 코드들(선택). 붙어 있으면 완료 시 그쪽도 완료로.
            $table->json('wbs_codes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // 이월 추적 — 어느 줄에서 넘어왔는가. 같은 일이 3주째 넘어오면 그게 문제다.
            $table->foreignId('carried_from_id')->nullable()->constrained('week_board_lines')->nullOnDelete();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('week_board_lines');
    }
};
