<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 지식 창고 — 문서 분석이 뽑아낸 "반드시 기억해야 하는 사실"을 낱개 카드로 축적한다.
 *
 * 문서마다 봉투(key_facts JSON) 안에 갇혀 있던 지식을 공용 서랍으로 꺼내,
 * 채팅 AI 가 문서를 뒤지기 전에 먼저 검색한다. 개정 문서가 오면 옛 카드는
 * retired_at 으로 은퇴한다 — 지우지 않는다(왜 그때 그렇게 답했는지가 증거다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('site_id')->nullable()->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->foreignId('intelligent_document_id')->index();

            // 출처 스냅샷 — 문서가 지워져도 카드만으로 어디서 온 지식인지 안다.
            $table->string('doc_title')->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('document_number')->nullable()->index();
            $table->string('revision', 50)->nullable();
            $table->date('document_date')->nullable();

            $table->text('fact');

            // 의미 검색용 임베딩 — float32 768개를 pack('g*')+base64 로 압축(약 4KB).
            // pgvector 확장 없이도 돌게 하기 위한 선택이다. 카드가 수천 장을 넘으면
            // 그때 vector 컬럼으로 옮긴다(코사인 계산은 GeminiEmbedder 한 곳에 있다).
            $table->text('embedding')->nullable();

            $table->timestamp('retired_at')->nullable()->index();
            $table->foreignId('retired_by_document_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_facts');
    }
};
