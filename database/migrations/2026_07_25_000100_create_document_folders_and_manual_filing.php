<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 문서통합관리 — 사용자 정의 폴더 + 수동 폴더 지정.
 *
 *  - document_folders: 기본 9개 폴더(코드 01~09, 상수) 외에 관리자가 직접 만든 폴더를 담는다.
 *  - integrated_documents.folder_locked: 업로드할 때 사람이 폴더를 직접 고른 경우 true.
 *    AI 분석이 끝나도 이 폴더를 덮어쓰지 않는다(사람 지정이 AI 추측보다 우선).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();     // 사용자 폴더는 '10' 부터 부여.
            $table->string('name', 80);
            $table->string('color', 16)->default('#64748b');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->boolean('folder_locked')->default(false)->after('folder_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->dropColumn('folder_locked');
        });
        Schema::dropIfExists('document_folders');
    }
};
