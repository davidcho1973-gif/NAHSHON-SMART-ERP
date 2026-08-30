<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 호출 장부 — 어디에 얼마를 쓰는가.
 *
 * 지금까지 이 앱은 AI 를 아홉 군데서 부르면서 <b>비용을 한 번도 세지 않았다</b>.
 * 모델이 셋이 되면 요금은 세 배가 될 수 있는데, 어느 기능이 그 돈을 쓰는지 모르면
 * 줄일 곳도 고를 수 없다. 그래서 엔진을 늘리기 전에 계량기부터 단다.
 *
 * 토큰 단가는 모델마다 다르고 자주 바뀌므로 금액은 config 의 단가표로 계산해 함께
 * 적어 둔다 — 나중에 단가가 바뀌어도 과거 기록의 금액은 그때의 값으로 남는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('engine', 20);              // gemini | claude | openai
            $table->string('model', 60)->nullable();
            $table->string('feature', 60);             // document_analysis, cross_check, chat, drawing …
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('subject_type', 60)->nullable();  // 어떤 대상에 쓴 호출인가
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);

            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('ok')->default(true);
            $table->string('error', 255)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['engine', 'occurred_at']);
            $table->index(['feature', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
