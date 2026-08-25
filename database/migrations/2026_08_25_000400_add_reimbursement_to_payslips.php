<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 개인카드 환급 → 급여 동승 (연계 점검 돈-J).
 *
 * 개인카드 경비는 승인까지만 되고 급여로 안 넘어가, 사장이 따로 송금해야 했다.
 * 이제 급여 지급 시 그 기간까지 승인된 개인 경비가 명세서에 환급(비과세) 줄로
 * 실리고, 해당 경비는 그 급여 대장 번호로 지급 처리된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->decimal('reimbursement', 12, 2)->default(0)->after('per_diem')
                ->comment('개인카드 경비 환급(비과세) — 급여와 함께 지급');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn('reimbursement');
        });
    }
};
