<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Davis-Bacon fringe(복리후생비) 시간당 요율.
 *
 * 인증임금(prevailing wage) 현장은 기본 시급 외에 fringe 를 시간당으로 지급·신고해야
 * 한다. payslips.fringe_pay 칼럼은 있는데 채우는 요율이 어디에도 없어 항상 0 이었고,
 * WH-347 에 Fringe 열도 없었다 — 소급 정산 리스크(연계 점검 돈-B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table): void {
            $table->decimal('fringe_rate', 10, 4)->default(0)->after('per_diem_rate')
                ->comment('시간당 fringe(복리후생) 요율 — Davis-Bacon 대상 인원용');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table): void {
            $table->dropColumn('fringe_rate');
        });
    }
};
