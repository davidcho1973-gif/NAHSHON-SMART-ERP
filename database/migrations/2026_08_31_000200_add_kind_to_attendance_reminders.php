<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 원장에 종류를 붙인다 — 출근 알림과 퇴근 알림이 서로의 몫을 먹지 않게.
 *
 * 하루 상한(2회)이 한 칸에 묶여 있으면, 아침에 출근 알림 두 번을 보낸 사람에게는
 * 저녁 퇴근 알림이 한 번도 못 간다. 정작 돈이 걸린 쪽은 퇴근이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_reminders', function (Blueprint $table): void {
            $table->string('kind', 20)->default('clock_in')->after('work_date');
            $table->dropUnique(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'work_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_reminders', function (Blueprint $table): void {
            $table->dropUnique(['employee_id', 'work_date', 'kind']);
            $table->unique(['employee_id', 'work_date']);
            $table->dropColumn('kind');
        });
    }
};
