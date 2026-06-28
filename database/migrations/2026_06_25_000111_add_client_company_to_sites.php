<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 원청사(client) ↔ 소속사(employer) 2차원 분리.
 *
 *  - 원청사(client) = 그 현장의 발주처/원청 (LG에너지·SK온 …) → 현장 속성.
 *  - 소속사(employer) = 직원을 고용한 회사 (NAHSHON·협력사) → employees.company_id (기존).
 *
 * 지금까지 현황은 직원의 소속사만 봤다. 현장에 원청사를 두어 "어느 발주처 현장에서
 * 일하나"(원청사)와 "누가 월급 주나"(소속사)를 둘 다 집계할 수 있게 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('client_company_id')->nullable()->after('company_id')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_company_id');
        });
    }
};
