<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검측 멈춤점 메모(hold_note)를 200자에서 text 로.
 *
 * 멈춤점 메모는 «무슨 검사를, 누가, 언제까지 통보하고, 무엇이 확인돼야 완료로 넘길 수 있는가»
 * 를 적는 자리다. 원청 공정표 대조로 검측 멈춤점을 넣다 보니 한 줄이 200자를 쉽게 넘었다 —
 * 예: «질소 가압 누설시험·진공 500미크론 유지 기록·소유주 사전 통지 확인 전 냉매 충전·완료 금지;
 * 기동 후 단계 강하 기록 …». 잘라 쓰면 정작 검사관 앞에서 필요한 조건이 빠진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->text('hold_note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->string('hold_note', 200)->nullable()->change();
        });
    }
};
