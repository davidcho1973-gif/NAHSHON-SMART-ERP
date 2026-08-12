<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 직원 전화번호.
 *
 * 왜 이제야 — 간편 등록 폼은 처음부터 번호를 받아 왔는데(MemberRegistration.phone),
 * 직원 표로 옮길 때 빠뜨렸다. 그래서 앱 링크를 보낼 때 번호를 아는데도 손으로 다시
 * 골라야 했다. 한 번 받은 것을 두 번 다루지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->after('email');
        });

        // 이미 등록된 사람들의 번호를 지원서에서 끌어온다 — 새로 물어볼 이유가 없다.
        if (Schema::hasTable('member_registrations')) {
            \Illuminate\Support\Facades\DB::statement(<<<'SQL'
                UPDATE employees e
                   SET phone = r.phone
                  FROM member_registrations r
                 WHERE r.employee_id = e.id
                   AND r.phone IS NOT NULL
                   AND r.phone <> ''
                   AND (e.phone IS NULL OR e.phone = '')
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
