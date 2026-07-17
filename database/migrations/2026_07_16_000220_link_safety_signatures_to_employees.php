<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 안전 작업카드 서명자 → 실제 직원 레코드 연결. 공정↔급여 사슬의 마지막 고리.
 *
 * 이전에는 서명자가 자유 텍스트 이름뿐이라 "이 작업에 김철수 3명 투입"이 인원관리/급여와
 * 이어지지 않았다. 안전카드에는 work_date 가 있고 attendance_logs · payroll_timesheets 는
 * (employee_id, 날짜)로 묶이므로, 서명자에 employee_id 만 붙으면 그 둘이 조인된다:
 *
 *   WBS SubTask ← 안전카드(work_date) ← 서명(employee_id) → 출퇴근/타임시트(employee_id, 같은 날)
 *
 * name 은 그대로 남긴다 — 서명은 법적 기록이라 당시 표기를 보존해야 하고,
 * 직원 레코드가 나중에 삭제/개명돼도 서명 기록 자체는 변하면 안 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safety_work_signatures') || Schema::hasColumn('safety_work_signatures', 'employee_id')) {
            return;
        }

        Schema::table('safety_work_signatures', function (Blueprint $table): void {
            // 직원이 지워져도 서명 기록(이름)은 남아야 하므로 nullOnDelete.
            $table->foreignId('employee_id')->nullable()->after('safety_work_item_id')
                ->constrained('employees')->nullOnDelete();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('safety_work_signatures') || ! Schema::hasColumn('safety_work_signatures', 'employee_id')) {
            return;
        }

        Schema::table('safety_work_signatures', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->dropIndex(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
