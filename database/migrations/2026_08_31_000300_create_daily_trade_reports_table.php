<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공종별 일일보고 — 반장의 "오늘 내 몫".
 *
 * 지금까지 현장 상황실은 <b>누가 올려도 같은 한 더미</b>였다. 그래서 저녁에
 * 소장이 "덕트 반장이 오늘 올렸나" 를 알 방법이 없었고, 안 올린 날의 마감보고서는
 * 덕트 얘기 없이 원청에 나갔다 — 빠진 줄을 아무도 몰랐다.
 *
 * 공종당 하루 한 장으로 묶는다(반장이 둘이어도 보고는 하나 — 현장에서 공종 하나에
 * 보고 하나가 맞다). 올린 글·사진은 그 장에 묶이고, 「제출」로 확정된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_trade_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            /** 공종(Trade) — employees.role 과 같은 문자열을 쓴다(WBS 이름으로 정규화됨). */
            $table->string('trade', 60);
            $table->string('status', 20)->default('open');   // open | submitted
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            /** 소장이 되돌린 이유 — 되돌림은 사람 사이의 일이라 까닭이 남아야 한다. */
            $table->string('reopen_reason', 250)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'work_date', 'trade']);
            $table->index(['site_id', 'work_date', 'status']);
        });

        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->foreignId('daily_trade_report_id')->nullable()
                ->constrained('daily_trade_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('daily_trade_report_id');
        });
        Schema::dropIfExists('daily_trade_reports');
    }
};
