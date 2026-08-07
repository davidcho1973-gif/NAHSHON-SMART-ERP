<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "안전 없이 공정 없다" 를 매일 단위로 성립시킨다.
 *
 * 이전 구조: wbs_items.safety_work_code 문자열 하나 → SubTask 하나당 안전카드 하나(1:1).
 * 문제: 안전(TBM/서명)은 "하루 단위" 행위다. 3일짜리 작업은 카드가 3장 필요한데 표현할 수 없었고,
 *       카드에 날짜 컬럼조차 없어(due_label 이 "오늘 17:00" 같은 자유 텍스트) 출퇴근/급여와 맞출 수 없었다.
 *
 * 새 구조: 안전카드가 자신이 속한 공정을 가리킨다(safety_work_items.wbs_code) + work_date.
 *          → SubTask 1 : 안전카드 N (하루 한 장). 날짜가 생기니 출퇴근·급여와 맞물린다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safety_work_items')) {
            Schema::table('safety_work_items', function (Blueprint $table): void {
                // 작업 실행일. 기존 카드는 날짜를 알 수 없어 null 로 남는다(due_label 자유텍스트만 있었음).
                $table->date('work_date')->nullable()->after('title');
                // 공정 역참조 — wbs_items.wbs_code 를 가리키는 느슨한 링크(FK 없음, 기존 관례 유지).
                $table->string('wbs_code', 80)->nullable()->after('work_date');

                $table->index('work_date');
                $table->index('wbs_code');
                $table->index(['wbs_code', 'work_date']);
            });
        }

        // 기존 1:1 링크를 새 방향으로 이관 (한 건도 잃지 않는다).
        if (Schema::hasTable('wbs_items') && Schema::hasTable('safety_work_items')
            && Schema::hasColumn('wbs_items', 'safety_work_code')) {
            DB::table('wbs_items')
                ->whereNotNull('safety_work_code')
                ->orderBy('id')
                ->chunkById(200, function ($items): void {
                    foreach ($items as $item) {
                        DB::table('safety_work_items')
                            ->where('work_code', $item->safety_work_code)
                            ->whereNull('wbs_code')
                            ->update(['wbs_code' => $item->wbs_code]);
                    }
                });

            Schema::table('wbs_items', function (Blueprint $table): void {
                $table->dropIndex(['safety_work_code']);
                $table->dropColumn('safety_work_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wbs_items') && ! Schema::hasColumn('wbs_items', 'safety_work_code')) {
            Schema::table('wbs_items', function (Blueprint $table): void {
                $table->string('safety_work_code', 80)->nullable()->index()->after('site_id');
            });

            // 역이관: 공정당 가장 이른 카드 하나만 되돌릴 수 있다(1:N → 1:1 은 본질적으로 손실).
            if (Schema::hasTable('safety_work_items')) {
                $links = DB::table('safety_work_items')
                    ->whereNotNull('wbs_code')
                    ->orderBy('work_date')
                    ->get(['work_code', 'wbs_code']);

                $seen = [];
                foreach ($links as $link) {
                    if (isset($seen[$link->wbs_code])) {
                        continue;
                    }
                    $seen[$link->wbs_code] = true;
                    DB::table('wbs_items')->where('wbs_code', $link->wbs_code)
                        ->update(['safety_work_code' => $link->work_code]);
                }
            }
        }

        if (Schema::hasTable('safety_work_items')) {
            Schema::table('safety_work_items', function (Blueprint $table): void {
                $table->dropIndex(['wbs_code', 'work_date']);
                $table->dropIndex(['wbs_code']);
                $table->dropIndex(['work_date']);
                $table->dropColumn(['work_date', 'wbs_code']);
            });
        }
    }
};
