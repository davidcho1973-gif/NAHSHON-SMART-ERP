<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일일보고의 자리를 공종 밖으로 넓힌다 — 사무·안전·현장관리·공무도 자기 줄을 갖는다.
 *
 * 일일보고는 쓰는 곳이 아니라 모이는 곳이다. 각 관리자가 자기 앱에서 올린 하루가
 * 한 장에 모여 종합되고 마감 보고서가 된다. 그런데 자리가 «현장 + 공종» 뿐이라
 * 공종 없는 관리자의 하루(서류 제출·발주·청구·인허가·안전 점검)는 묶일 곳이 없었다.
 *
 * trade 칸은 그대로 자리의 열쇠로 쓰고(공종 이름 또는 부서 이름), kind 가 그것이
 * 공종인지 부서인지를 말한다. 현황판과 보고서는 이 값으로 둘을 갈라 보여 준다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_trade_reports', function (Blueprint $table): void {
            /** trade | office — 이 줄이 공종 보고인가, 부서(사무 등) 보고인가. */
            $table->string('kind', 10)->default('trade')->after('trade');
        });
    }

    public function down(): void
    {
        Schema::table('daily_trade_reports', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
