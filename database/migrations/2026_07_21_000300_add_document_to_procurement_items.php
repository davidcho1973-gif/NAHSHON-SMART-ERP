<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 조달 항목에 근거 서류(발주서·선적서 등) 링크를 붙인다 — AI 분석으로 자동 기입 시 원본 보관.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->string('document_disk', 40)->nullable()->after('note');
            $table->string('document_path', 255)->nullable()->after('document_disk');
            $table->string('document_name', 255)->nullable()->after('document_path');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table): void {
            $table->dropColumn(['document_disk', 'document_path', 'document_name']);
        });
    }
};
