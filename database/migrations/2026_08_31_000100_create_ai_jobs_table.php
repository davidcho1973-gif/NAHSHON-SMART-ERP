<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 오래 걸리는 AI 작업의 진행 상태 — 화면이 기다리지 않게 하는 그릇.
 *
 * 도면 한 장을 판독하는 데 수십 초에서 몇 분이 걸린다. 그런데 웹 요청은 그렇게
 * 오래 붙잡고 있을 수 없다 — 게이트웨이가 먼저 끊고 사용자에게는 504 만 남는다.
 * 실제로 그 사고가 났다(제출물 AI 자료 조사).
 *
 * 그래서 요청은 <b>접수만 하고 즉시 응답</b>하고, 실제 판독은 뒤에서 돌린다.
 * 화면은 이 표를 물어보며 기다린다. 이 앱에 이미 같은 방식이 있다(상황실 판독) —
 * 그 패턴을 AI 작업 전체가 쓰도록 공용으로 뺀 것이다.
 *
 * 표 이름이 ai_task_jobs 인 이유 — ai_jobs 는 2026-06 출퇴근 AI 묶음에서 만들어진 뒤
 * 아무도 쓰지 않는 빈 표로 남아 있다. 남의 표를 덮어쓰기보다 이름을 비켜 갔다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('kind', 40)->index();          // takeoff | spec_submittals | vendor_request | research
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('params')->nullable();
            $table->string('status', 20)->default('queued')->index();   // queued | running | done | failed
            $table->string('label')->nullable();          // 화면에 보여 줄 한 줄("도면 물량 뽑기 — A01-01")
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_jobs');
    }
};
