<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 방마다 "언제 내 폰을 울릴지" 를 사람이 고른다.
 *
 * 그동안은 방에 글이 올라오면 그 방 사람 <b>전원</b>의 폰이 울렸다. 현장방에 30명이면
 * 글 하나에 29대가 울린다. 그러면 사람들은 앱 알림을 통째로 끄고, 정작 급한 지시가
 * 묻힌다 — 알림이 소음이 된 원인은 "고를 수 없음" 이었다.
 *
 * 값은 셋: all(모든 글) · mentions(나를 부를 때만) · none(끄기).
 * 비워 두면(null) 방 종류의 기본값을 따른다 — 기본값을 나중에 바꿔도 사람마다
 * 다시 저장할 필요가 없게.
 *
 * 긴급(🚨) 글은 이 설정과 무관하게 울린다. 끄는 것이 안전하려면 그 예외가 있어야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_room_members', function (Blueprint $table): void {
            $table->string('notify_level', 20)->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_room_members', function (Blueprint $table): void {
            $table->dropColumn('notify_level');
        });
    }
};
