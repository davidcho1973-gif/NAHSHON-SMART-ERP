<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 문서 ↔ 업무 연결 — 문서가 어느 발주/공정/작업자/협력사 것인지 붙인다.
 *
 * 지금까지 문서는 site_id/project_code 만 있어 "이 발주 건 서류 일체", "이 작업자 서류"를
 * 물어볼 수 없었다. 실무에서 문서를 찾는 단위는 폴더가 아니라 '업무 대상'이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->foreignId('procurement_item_id')->nullable()->after('project_code')
                ->constrained('procurement_items')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->after('procurement_item_id')
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->after('employee_id')
                ->constrained('companies')->nullOnDelete();
            $table->string('wbs_code', 60)->nullable()->after('company_id');
            // 사람이 직접 연결했는지(=자동 추정 결과를 덮어쓰지 않기 위함).
            $table->boolean('link_locked')->default(false)->after('wbs_code');

            $table->index('procurement_item_id');
            $table->index('employee_id');
            $table->index('company_id');
            $table->index('wbs_code');
        });
    }

    public function down(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('procurement_item_id');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['wbs_code', 'link_locked']);
        });
    }
};
