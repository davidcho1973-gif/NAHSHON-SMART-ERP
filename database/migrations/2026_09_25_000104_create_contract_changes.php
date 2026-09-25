<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFI — 계약을 바꾸는 유일한 길(사장 지시 2026-09-25: «추가되면 RFI 제출해서 승인받고 뺄건 빼고»).
 *
 * ── 왜 이 모양인가 ────────────────────────────────────────────────────
 * 계약 줄(contract_boq_lines)이 곧 일의 단위다. RFI 로 더하는 일도 계약 줄로 더한다 — 따로 세는 표를
 * 두면 공정 진행률과 청구가 두 벌이 된다. 다만 원청이 승인하기 전의 줄은 «검토 중(draft)» 이라
 * 청구에 들어가지 않는다. 기성 근거 대장이 이미 그렇게 막는다.
 *
 * contract_changes: RFI 한 건 — 번호, 추가/감액, 제출·승인·반려, 요청 문서와 승인 문서.
 * contract_change_lines: 그 RFI 가 건드린 줄과 수량 변화. 추가면 새 줄(+수량), 감액이면 기존 줄(−수량).
 *   감액은 승인되는 순간 기존 줄의 계약 수량을 줄이고, 줄이기 전 수량을 여기 남긴다 — 원래 계약이
 *   얼마였는지는 지워지면 안 되는 사실이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_contract_id')->constrained('project_contracts')->restrictOnDelete();
            $table->foreignId('work_section_id')->nullable()->constrained('work_sections')->nullOnDelete();
            $table->string('rfi_no', 40);
            $table->string('kind', 10);          // add · deduct
            $table->string('title', 255);
            $table->string('status', 12)->default('submitted')->index();   // submitted · approved · rejected
            $table->date('submitted_on')->nullable();
            $table->date('decided_on')->nullable();
            $table->decimal('amount', 15, 2)->default(0);   // 부호 있는 금액: 추가 +, 감액 −
            $table->foreignId('request_document_id')->nullable()->constrained('intelligent_documents')->nullOnDelete();
            $table->foreignId('approval_document_id')->nullable()->constrained('intelligent_documents')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['project_contract_id', 'rfi_no', 'kind']);
        });

        Schema::create('contract_change_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_change_id')->constrained('contract_changes')->cascadeOnDelete();
            $table->foreignId('contract_boq_line_id')->constrained('contract_boq_lines')->restrictOnDelete();
            $table->decimal('qty_delta', 16, 4);          // 추가 +, 감액 −
            $table->decimal('qty_before', 16, 4)->nullable();   // 감액 승인 때 줄이기 전 계약 수량
            $table->decimal('amount', 15, 2);
            $table->timestampsTz();
            $table->unique(['contract_change_id', 'contract_boq_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_change_lines');
        Schema::dropIfExists('contract_changes');
    }
};
