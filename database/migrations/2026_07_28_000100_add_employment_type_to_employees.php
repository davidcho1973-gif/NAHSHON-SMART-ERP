<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 고용 형태 — 관리 방식을 가르는 축.
 *
 *  direct   직접고용   : 우리 회사가 시급을 지급 → 시간(분) 관리가 핵심. 퇴근 누락 시 자동 마감하지 않는다
 *                       (임금이 틀어지므로 관리자 확인 대상으로 남긴다).
 *  indirect 간접고용   : 하청업체 직원 → "오늘 몇 명 왔나"가 핵심. 퇴근 누락 시 16:00 자동 마감.
 *  staff    관리직     : 현장/시스템 관리자.
 *  client   원청       : 열람 위주, 출퇴근 대상 아님.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employment_type', 12)->default('direct')->after('employment_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('employment_type');
        });
    }
};
