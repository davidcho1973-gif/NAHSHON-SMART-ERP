<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 앱의 «물어보기» — 관리자가 도면·서류에 대고 물은 것과 그 답.
 *
 * 대화방의 @AI 는 답이 방에 공개로 남는다. 관리자가 혼자 확인하고 싶은 질문
 * («에폭시 양생 며칠이야») 까지 모두에게 보일 이유가 없고, 방 안에서만 물을 수
 * 있으면 앱 첫 화면에서 바로 물을 길이 없다. 그래서 물어본 사람만 보는 자리를
 * 따로 둔다. 답에 근거 문서 번호를 남겨 «왜 그렇게 답했나» 를 되짚을 수 있게 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            /** AI 가 «자료에 있다» 고 본 질문인가. false 면 «확인되지 않습니다» 류의 답이다. */
            $table->boolean('found')->default(false);
            /** [{document_id, title, note, can_open}] — 답의 근거 문서. */
            $table->json('sources')->nullable();
            /** 권한이 없어 조회하지 못한 것들 — 답이 왜 비었는지의 기록. */
            $table->json('denied')->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_questions');
    }
};
