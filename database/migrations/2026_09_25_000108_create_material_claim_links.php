<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 송장 품목 ↔ 계약 줄 연결의 기억 — 사장 지시(2026-09-25) «송장 사진 올리면 반입 기록 자동으로».
 *
 * 송장의 «1/2" EMT 10FT» 가 계약서의 «#322 ELECTRICAL METALLIC TUBING (EMT)» 이고 한 개가 10 LF 라는
 * 것은 한 번 사람이 정하면 그 현장에서 계속 참이다. 매번 AI 에게 다시 묻지 않고 여기서 찾는다 — 같은
 * 품목이 송장마다 다른 줄로 가면 반입 수량이 여러 줄에 흩어진다.
 *
 * factor = 송장 단위 1 이 계약 단위로 얼마인가(10FT 한 개 → 10 LF 면 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_claim_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name_key', 255);   // 송장 품목 이름을 소문자·기호 없이
            $table->foreignId('contract_boq_line_id')->constrained('contract_boq_lines')->cascadeOnDelete();
            $table->decimal('factor', 14, 6)->default(1);
            $table->string('receipt_unit', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestampsTz();
            $table->unique(['site_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_claim_links');
    }
};
