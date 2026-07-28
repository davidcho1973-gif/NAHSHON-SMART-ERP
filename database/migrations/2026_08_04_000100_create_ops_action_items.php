<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장 액션 아이템 — "반영할 모듈이 없는" 내용의 종착지.
 *
 * 실제 현장 대화의 절반은 공정·자재·인원 어디에도 안 들어간다:
 *   "화기작업 승인 받으세요"          원청 지시
 *   "연장작업 신청합니다" → "네 그러시죠"  승인 요청·결과
 *   "29,000불 네고하고 오케이하시죠"     의사결정 대기
 *   "보안경 2-3 bag 준비"              준비물
 *   "스파이더 위치 알려주세요"          회신 요청
 *
 * 이것들을 억지로 공정표에 밀어 넣으면 데이터가 왜곡된다. 대신 공통 구조 하나로 받는다:
 *   누가(requester) → 누구에게(assignee) → 언제까지(due_on) → 됐나(status)
 *
 * 이 표가 곧 "오늘 한 일 / 내일 할 일" 의 원천이 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_action_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('ops_intake_batch_id')->nullable()->constrained('ops_intake_batches')->nullOnDelete();
            $table->foreignId('ops_intake_item_id')->nullable()->constrained('ops_intake_items')->nullOnDelete();

            // request 지시·요청 / approval 승인 / decision 의사결정 / todo 준비물·할일 / info 공유사항
            $table->string('kind', 12)->default('todo')->index();

            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('requester', 120)->nullable();   // 요청한 사람(원청 담당자 등)
            $table->string('assignee', 120)->nullable();    // 처리할 사람
            $table->date('due_on')->nullable()->index();    // 기한(내일 할 일이면 내일 날짜)
            $table->date('occurred_on')->nullable();        // 이 내용이 나온 날

            // open 대기 / done 완료 / cancelled 취소
            $table->string('status', 12)->default('open')->index();
            $table->boolean('is_blocker')->default(false);  // 이게 안 되면 다른 작업이 막히는가
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_action_items');
    }
};
