<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 조달 관리 — 발주·조달성 WBS 공정의 "납기 추적 상태"를 보관한다.
 *
 * 조달 대상은 WBS 공정(subtask)에서 자동 추출하고, 그 위에 얹는 발주/ETA/벤더/금액/상태를
 * 여기에 (project_code, wbs_code) 로 upsert 한다. 납기(need-by)는 WBS 종료일에서 파생하므로
 * 저장하지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_items', function (Blueprint $table): void {
            $table->id();
            $table->string('project_code', 80);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('wbs_code', 120);
            $table->foreignId('wbs_item_id')->nullable()->constrained('wbs_items')->nullOnDelete();

            $table->string('status', 20)->default('발주대기'); // 발주대기/발주완료/생산중/선적중/통관중/입고완료
            $table->string('vendor', 180)->nullable();
            $table->string('po_no', 120)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->date('ordered_on')->nullable();
            $table->date('eta')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_code', 'wbs_code']);
            $table->index(['project_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_items');
    }
};
