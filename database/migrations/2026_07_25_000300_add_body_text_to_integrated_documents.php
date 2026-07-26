<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 문서 본문 전문검색 — 분석 때 이미 뽑던 원문 텍스트를 버리지 않고 저장한다.
 * (지금까지는 요약·필드만 검색돼 "PDF 안의 문구"로는 찾을 수 없었다.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->text('body_text')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->dropColumn('body_text');
        });
    }
};
