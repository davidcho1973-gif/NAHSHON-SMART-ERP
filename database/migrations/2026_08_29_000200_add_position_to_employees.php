<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 직책(position)을 공정(role)에서 떼어낸다.
 *
 * 지금까지 employees.role 한 칸이 두 가지를 겸했다 — 화면에서는 "공정(Trade)"으로
 * 받으면서, 급여 계산은 그 글자에서 'foreman'·'manager' 같은 낱말을 찾아 관리자
 * 구분을 정했다. 그래서 "Piping" 이라고 적은 반장은 작업자로 계산됐고, 공정 이름에
 * 우연히 그 낱말이 들어가면 반대로 관리자가 됐다.
 *
 * 두 칸으로 나눈다: role = 무슨 일을 하는가(공정), position = 어떤 자리인가(직책).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('position', 40)->nullable()->after('role');
        });

        Schema::table('member_registrations', function (Blueprint $table) {
            $table->string('position', 40)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('position'));
        Schema::table('member_registrations', fn (Blueprint $t) => $t->dropColumn('position'));
    }
};
