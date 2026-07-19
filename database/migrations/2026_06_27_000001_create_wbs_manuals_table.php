<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공정관리 AI 매뉴얼 분석 이력.
 * 업로드된 매뉴얼(PDF/이미지)과 그 분석 결과(엔진·생성된 WBS 수량)를 기록해
 * "분석된 파일 리스트"에서 다시 선택·적용할 수 있게 한다. 실제 WBS 트리는 wbs_items 에 있다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wbs_manuals', function (Blueprint $table): void {
            $table->id();
            $table->string('project_code', 80)->index();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('disk', 40)->default('public');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('engine', 20)->nullable();          // gemini | claude
            $table->string('status', 20)->default('completed'); // analyzing | completed | failed
            $table->unsignedInteger('stages')->default(0);
            $table->unsignedInteger('tasks')->default(0);
            $table->unsignedInteger('subtasks')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('analyzed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['project_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wbs_manuals');
    }
};
