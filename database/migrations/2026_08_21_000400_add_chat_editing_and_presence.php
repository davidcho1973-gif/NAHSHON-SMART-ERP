<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 잘못 쓴 글을 고치고 지울 수 있게, 그리고 지금 누가 방에 있는지 보이게.
 *
 * <b>지운 글을 진짜로 지우지 않는 이유</b>: 현장 지시는 나중에 분쟁의 증거가 된다.
 * 카카오톡처럼 "삭제된 메시지입니다" 만 남기고 내용은 감춘다 — 쓴 사람에게는 지워진
 * 것이고, 기록으로는 남는다. 방 삭제를 막아 둔 것과 같은 이유다.
 *
 * <b>고친 글에 흔적을 남기는 이유</b>: 조용히 바뀌면 "분명히 다르게 봤는데" 가 된다.
 * (수정됨) 한 마디가 그 다툼을 없앤다.
 *
 * <b>last_seen_at</b>: 지금 이 방을 보고 있는 사람. 대화 상대가 화면 앞에 있는지
 * 아는 것만으로 "왜 답이 없지" 가 사라진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('sent_at');
            $table->timestamp('removed_at')->nullable()->after('edited_at');
            $table->unsignedBigInteger('removed_by_user_id')->nullable()->after('removed_at');
        });

        Schema::table('communication_room_members', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->dropColumn(['edited_at', 'removed_at', 'removed_by_user_id']);
        });

        Schema::table('communication_room_members', function (Blueprint $table): void {
            $table->dropColumn('last_seen_at');
        });
    }
};
