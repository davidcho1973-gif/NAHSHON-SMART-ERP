<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공종(trade) 과 협력사(company) 분리.
 *
 * 그동안 company 하나가 "공종"과 "협력사"를 겸했다(공종 컬럼에 회사명이 표시됨). 이제:
 *   - trade   = 공종(전기/배관/기계설치 …) — AI 가 분류하거나 CPM 공정표에서 온다.
 *   - company = 담당 협력사 — 실제 계약사에서 "사람"이 배정한다(AI 가 임의로 만들지 않는다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wbs_items', 'trade')) {
                $table->string('trade', 60)->nullable()->after('company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wbs_items', function (Blueprint $table): void {
            if (Schema::hasColumn('wbs_items', 'trade')) {
                $table->dropColumn('trade');
            }
        });
    }
};
