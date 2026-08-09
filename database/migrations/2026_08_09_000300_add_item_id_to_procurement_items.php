<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 조달 ↔ 품목 마스터 연결.
 *
 * 품목 마스터(items)는 등록만 되고 아무도 읽지 않는 고립 모듈이었다 — 발주 시
 * "무엇을 사는가"는 WBS 작업명 문자열과 메모 서술뿐이라, 표준단가 대비 실발주가
 * 비교도 품목별 소요량 집계도 불가능했다. 첫 단추로 조달 행이 품목을 가리키게 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->foreignId('item_id')->nullable()->after('wbs_item_id')->constrained('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('item_id');
        });
    }
};
