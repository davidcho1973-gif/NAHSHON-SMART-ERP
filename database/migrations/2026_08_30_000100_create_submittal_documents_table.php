<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제출물 ↔ 문서함 연결.
 *
 * 조항의 출처(source_document_id)는 시방서 한 권이지만, 그 조항을 <b>채우는</b> 자료
 * (제품자료·시험성적·승인본)는 여러 개다. 어느 자료가 어느 제출물의 것인지 잇는
 * 표가 없으면, 자료는 문서함에 있는데 대장은 여전히 "미착수" 라고 말한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submittal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submittal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligent_document_id')->constrained()->cascadeOnDelete();
            // received: 업체·조사로 받은 자료 / approval: 승인본
            $table->string('kind', 20)->default('received');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['submittal_id', 'intelligent_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submittal_documents');
    }
};
