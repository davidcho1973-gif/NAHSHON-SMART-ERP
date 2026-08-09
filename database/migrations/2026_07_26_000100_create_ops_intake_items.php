<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장 상황실 판독 결과 — 올라온 글 한 줄(또는 붙여넣은 대화 한 건)에서 뽑아낸 "반영 제안".
 *
 * 사람이 형식 없이 쓴 말을 AI 가 분류(마감/계획/조달/인력/지출/이슈/잡담)하고, 공정표·조달의
 * 어느 대상인지 찾아 변경안을 만들어 둔다. 실제 반영은 2단계에서 이 제안을 적용한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_intake_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('project_code', 60)->nullable();

            // 출처 — 상황실 메시지 / 붙여넣기 / 직접 입력
            $table->string('source', 20)->default('paste');
            $table->foreignId('communication_message_id')->nullable()
                ->constrained('communication_messages')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('raw_text');                       // 원문(근거)
            $table->string('speaker', 80)->nullable();      // 카톡 붙여넣기의 발신자명
            $table->date('occurred_on')->nullable();        // 문맥상 해당 날짜

            $table->string('category', 24)->default('noise'); // progress/plan/procurement/labor/expense/issue/noise
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('summary', 300)->nullable();       // 사람이 읽는 한 줄 요약

            // 반영 대상(공정/조달) — 못 찾으면 null
            $table->string('target_type', 20)->nullable();    // wbs / procurement
            $table->string('target_code', 80)->nullable();    // wbs_code / po_no
            $table->string('target_name', 200)->nullable();

            $table->json('proposed')->nullable();             // 적용할 변경안(필드 => 값)
            $table->string('question', 300)->nullable();      // 모호할 때 되물을 내용

            // pending(확인대기) / applied(반영됨) / dismissed(무시) / needs_input(되물음) / ignored(잡담)
            $table->string('status', 16)->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result_note', 300)->nullable();

            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['category', 'status']);
            $table->index('occurred_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_intake_items');
    }
};
