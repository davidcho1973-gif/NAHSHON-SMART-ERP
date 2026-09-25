<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 도면 위 표시 — 사장 지시(2026-09-25): «각 줄에 사진이나 증거 자료를 넣었을 때 도면에 직접 내용이
 * 넣어지게», «세련된 표시 기능».
 *
 * ── 무엇을 적고 무엇을 적지 않는가 ────────────────────────────────────
 *  - 도면 <b>번호</b>(sheet_no)에 적는다. 쪽·파일에 적으면 개정판이 올라올 때 표시가 끊긴다 —
 *    공정별 도면 선택과 같은 이유다.
 *  - 좌표는 쪽 크기에 대한 비율(0~1)이다. 화면 크기·확대율·렌더 해상도와 무관하게 같은 자리를 가리킨다.
 *  - 색(확인됨·대기·반입)은 적지 않는다. 연결된 현장 기록(claim_work_records)의 상태에서 매번 나온다 —
 *    색을 따로 적으면 기록이 확인돼도 도면은 노란색으로 남는다.
 *  - 기록 없이 계약 줄만 가리키는 표시(계획), 줄도 없는 글 메모도 된다(«도면 위에 그리거나 글을 적는»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('sheet_no', 40);
            $table->foreignId('work_section_id')->nullable()->constrained('work_sections')->nullOnDelete();
            $table->foreignId('contract_boq_line_id')->nullable()->constrained('contract_boq_lines')->cascadeOnDelete();
            $table->foreignId('claim_work_record_id')->nullable()->constrained('claim_work_records')->nullOnDelete();
            $table->string('shape', 10);   // point · line · area · note
            $table->json('points');        // [[x, y], …] 쪽 크기 대비 0~1
            $table->string('label', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['site_id', 'sheet_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_marks');
    }
};
