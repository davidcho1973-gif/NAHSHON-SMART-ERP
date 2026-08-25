<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 돈 흐름 R2 — 시스템에 존재하지 않던 두 칸.
 *
 * 1. vehicles.monthly_rate: 차량 리스료가 어디에도 저장되지 않아 매달 수기 입력하거나
 *    누락됐다(점검 F). 장비(daily_rate)·숙소(monthly_rent)는 자동 계상이 되는데 차량만
 *    요율 칼럼 자체가 없었다.
 * 2. mobile_expenses.vendor_id: 경비에 벤더가 문자열(설명 속)로만 남아 AP·1099 산출이
 *    불가능했다(점검 I). 연말 1099 를 수기로 만들던 원인.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('monthly_rate', 12, 2)->nullable()->after('vendor')
                ->comment('월 리스/렌트료 — 있으면 매월 자동 계상(pending)');
        });

        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->after('company_id')
                ->constrained('vendors')->nullOnDelete();
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('monthly_rate');
        });
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
