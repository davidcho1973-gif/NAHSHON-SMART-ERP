<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 회사 구분 — 작업자의 고용 형태를 회사로부터 자동으로 정하기 위한 축.
 *
 *  own      자사   → 소속 작업자는 직접고용(시급 관리)
 *  partner  협력사 → 소속 작업자는 간접고용(출역 인원 관리, 16:00 자동 퇴근)
 *  client   원청   → 발주처. 출퇴근 대상 아님
 *  unknown  미지정 → 아직 분류 안 함. 등록 폼에서 작업자에게 한 번 물어본다
 *
 * 기본값을 unknown 으로 두는 이유: 잘못 추측해 협력사 직원을 직접고용으로 등록하면
 * 급여 계산이 틀어진다. 모르면 모른다고 두고 물어보는 편이 안전하다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('company_type', 16)->default('unknown')->after('status')->index();
        });

        // 시드로 만들어지는 자사(DASOL PRISM)는 확실하므로 미리 분류해 둔다.
        DB::table('companies')->where('code', 'DASOL-PRISM')->update(['company_type' => 'own']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('company_type');
        });
    }
};
