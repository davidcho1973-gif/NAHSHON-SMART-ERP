<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PIN 을 거둔다 — 현장에서 외울 것을 없앤다.
 *
 * <b>사장님 지시(2026-09-23):</b> «핀번호 설정을 지워라. 사람들이 자꾸 잊어버린다.
 * 이미 등록된 사람들은 전화번호 뒷 4자리만으로 들어오게 하라.»
 *
 * 남아 있는 pin_hash 가 왜 문제인가: 지워진 기능의 흔적일 뿐인데도 <b>문을 닫는
 * 조건</b>으로 쓰이고 있었다. 이메일 로그인의 «전화 뒷 4자리» 는 PIN 이 있는 계정에는
 * 안 통하게 돼 있었으므로, 값만 남겨 두면 예전에 PIN 을 정해 둔 사람만 조용히 막힌다.
 * 본인은 이유를 모르고 화면도 아무 말을 안 한다 — 그래서 값을 비운다.
 *
 * 칸(컬럼)은 남긴다. 되돌릴 여지를 한 배포만큼 남겨 두는 것이고, 지우는 일은
 * 되돌릴 수 없기 때문이다. 다음 배포에서 login_devices·auth_setup_tokens 와 함께 정리한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'pin_hash')) {
            return;
        }

        DB::table('users')->whereNotNull('pin_hash')->update([
            'pin_hash' => null,
            'pin_set_at' => null,
            'pin_failed_count' => 0,
            'pin_locked_until' => null,
        ]);
    }

    public function down(): void
    {
        // 해시는 되돌릴 수 없다 — 되돌리려면 본인이 다시 정하는 수밖에 없다.
        // 그 사실을 남겨 두는 것이 이 빈 메서드의 뜻이다.
    }
};
