<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 문서통합관리 — 직원·협력사·현장 작업자가 올린 모든 서류를 한 곳에 모아
 * AI 가 종류를 판별하고 9개 폴더로 자동 분류·저장하는 통합 문서 저장소.
 * (계약 전용인 project_contract_documents 와 달리, 공정관리 아래 SPA 에서 쓰는 전사 문서함)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrated_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('project_code', 80)->nullable();

            // AI 분류 결과
            $table->string('folder_code', 4)->default('03');      // 01~09
            $table->string('document_type', 60)->nullable();       // AI 판별 문서 종류 키
            $table->unsignedTinyInteger('type_confidence')->default(0);
            $table->unsignedTinyInteger('folder_confidence')->default(0);

            // 문서 식별
            $table->string('title', 255);
            $table->string('document_number', 120)->nullable();
            $table->string('issuer', 180)->nullable();
            $table->string('counterparty', 180)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 8)->nullable();

            // AI 추출 본문
            $table->json('summary')->nullable();   // 요약 줄 배열
            $table->json('fields')->nullable();     // 추출 메타데이터 k=>v
            $table->json('tags')->nullable();       // 자동 태그 배열
            $table->text('duplicate_note')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('integrated_documents')->nullOnDelete();

            // 파일
            $table->string('disk', 40)->default('public');
            $table->string('path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // 업로드/검토 상태
            $table->string('status', 20)->default('analyzing'); // analyzing / needs_review / confirmed / failed
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_label', 120)->nullable(); // 협력사/현장 작업자 등 비-계정 업로더 표기
            $table->string('engine', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['folder_code', 'status']);
            $table->index(['site_id', 'created_at']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrated_documents');
    }
};
