<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 마지막으로 "현장 안"이 확인된 시각. 이탈 신호(핑)를 못 받고 앱이 닫혀도,
 * 자정 마감에서 이 값을 퇴근 시각으로 추정해 자동 퇴근을 확정할 수 있게 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table): void {
            $table->timestamp('last_onsite_at')->nullable()->after('last_exit_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table): void {
            $table->dropColumn('last_onsite_at');
        });
    }
};
