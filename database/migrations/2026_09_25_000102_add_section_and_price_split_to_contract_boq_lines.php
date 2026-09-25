<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 계약 행(기성 근거 대장)을 계약서의 공정 묶음에 매단다 — 사장 지시 2026-09-25
 * «기성관리가 공정관리다».
 *
 * ── 왜 이 칸들인가 ────────────────────────────────────────────────────
 * work_section_id: 계약서의 줄은 섹션(「3) DRYWALL」) 아래에 있다. 공정별 도면 화면과
 *   기성 대장이 같은 줄을 보려면 줄이 자기 공정을 알아야 한다. 공정을 따로 세는 표를
 *   또 만들지 않고, 줄에 공정을 적는다.
 * group_label: 섹션 안의 작은 제목(「Secondary Containment System」「Demolition Work」).
 *   같은 「Demolition of Concrete」 가 어느 일의 철거인지는 이 제목이 말해 준다.
 * spec: 계약서 D열(규격). 같은 이름(「REGISTER」 6줄)의 줄을 가르는 것이 이 칸이다.
 *   이름에 이어 붙이면 이름으로 찾을 수 없게 된다.
 * material/labor/expense_price: 계약서 G·H·I열. 원청이 설치 전 반입 자재도 기성으로
 *   인정하므로(사장 확인 2026-09-25), 반입분은 자재 단가만큼, 설치분은 나머지만큼 받는다.
 *   단계별 인정 비율(stage_weights)은 이 세 값에서 나온다. 청구서의 «반입 자재» 칸도
 *   이 값으로 계산한다 — 비율만 남기면 원래 단가를 되살릴 수 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_boq_lines', function (Blueprint $table): void {
            $table->foreignId('work_section_id')->nullable()->after('project_contract_id')->constrained('work_sections')->nullOnDelete();
            $table->string('group_label', 255)->nullable()->after('description');
            $table->text('spec')->nullable()->after('group_label');
            $table->decimal('material_price', 16, 4)->nullable()->after('unit_price');
            $table->decimal('labor_price', 16, 4)->nullable()->after('material_price');
            $table->decimal('expense_price', 16, 4)->nullable()->after('labor_price');
        });
    }

    public function down(): void
    {
        Schema::table('contract_boq_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_section_id');
            $table->dropColumn(['group_label', 'spec', 'material_price', 'labor_price', 'expense_price']);
        });
    }
};
