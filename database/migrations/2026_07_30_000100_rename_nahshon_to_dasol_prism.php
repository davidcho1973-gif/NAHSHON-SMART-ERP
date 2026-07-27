<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 사명 변경: NAHSHON MEP → DASOL PRISM.
 *
 * 소스의 문구는 코드에서 바꿨지만, 이미 저장된 데이터에는 옛 이름이 그대로 남아 있다.
 * 회사 마스터와 회사명을 문자열로 들고 있는 컬럼(배지 인식 결과·WBS 담당사)을 함께 옮긴다.
 *
 * 로그인 이메일(@nahshonmep.com)은 건드리지 않는다 — 계정 식별자라 바꾸면 로그인이 끊긴다.
 */
return new class extends Migration
{
    /** [테이블 => 회사명을 문자열로 저장하는 컬럼들] */
    private const NAME_COLUMNS = [
        'companies' => ['name', 'legal_name'],
        'employees' => ['badge_company_name'],
        'wbs_items' => ['company'],
    ];

    public function up(): void
    {
        // 회사 코드도 함께 옮긴다 — 시드/마이그레이션이 이 코드로 자사를 찾는다.
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'code')) {
            DB::table('companies')->where('code', 'NAHSHON-MEP')->update(['code' => 'DASOL-PRISM']);

            // 회사 구분 마이그레이션이 옛 코드를 못 찾고 지나갔을 수 있다 — 여기서 자사로 확정한다.
            if (Schema::hasColumn('companies', 'company_type')) {
                DB::table('companies')->where('code', 'DASOL-PRISM')
                    ->whereIn('company_type', ['unknown', ''])
                    ->update(['company_type' => 'own']);
            }
        }

        $this->replaceNames('NAHSHON MEP', 'DASOL PRISM');
        // 'NAHSHON MEP' 을 먼저 바꾼 뒤라, 남은 단독 'NAHSHON' 만 정리된다.
        $this->replaceNames('NAHSHON', 'DASOL PRISM');
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'code')) {
            DB::table('companies')->where('code', 'DASOL-PRISM')->update(['code' => 'NAHSHON-MEP']);
        }

        $this->replaceNames('DASOL PRISM', 'NAHSHON MEP');
    }

    private function replaceNames(string $from, string $to): void
    {
        foreach (self::NAME_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, 'like', '%'.$from.'%')
                    ->update([$column => DB::raw("replace({$column}, '{$from}', '{$to}')")]);
            }
        }
    }
};
