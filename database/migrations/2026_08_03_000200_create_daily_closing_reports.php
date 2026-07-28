<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일일 마감 보고서 — 마감 버튼 한 번으로 그날 상황실에 올라온 모든 것을 정리한다.
 *
 * 집계(인원·진도·자재·지출·안전)는 DB 에서 정확히 뽑고, AI 는 그 숫자를 바탕으로
 * 문장을 쓴다. 숫자를 AI 에게 세게 하지 않는 이유는 명확하다 — 보고서의 숫자가 틀리면
 * 보고서 전체를 못 믿는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_closing_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->date('report_date');

            $table->string('status', 12)->default('writing');   // writing / done / failed
            $table->text('error')->nullable();

            // DB 에서 뽑은 확정 집계 — 보고서를 다시 열어도 그때 숫자가 그대로 남는다.
            $table->json('metrics')->nullable();
            // AI 가 쓴 서술(총평·이슈·내일 계획 등)
            $table->json('narrative')->nullable();

            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closing_reports');
    }
};
