<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 글에 다는 짧은 반응 — 그중에서도 ✅ "확인했습니다".
 *
 * 현장 지시에 "네 확인했습니다" 답글이 스무 개 달리면 정작 할 이야기가 묻힌다.
 * 그렇다고 아무 말도 안 하면 지시한 사람은 누가 봤는지 모른다. 읽음 표시(reads)는
 * 화면에 떴다는 기계의 기록이고, ✅ 는 <b>사람이 확인했다고 누른</b> 기록이다 —
 * 둘은 다른 사실이라 다른 표에 둔다.
 *
 * 누른 사람은 계정(user_id)으로 센다. 한 사람이 같은 반응을 두 번 누를 수 없다
 * (두 번 누르면 취소).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_message_id')->constrained('communication_messages')->cascadeOnDelete();
            $table->foreignId('communication_room_id')->constrained('communication_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['communication_message_id', 'user_id', 'emoji'], 'comm_reactions_message_user_emoji_unique');
            $table->index(['communication_room_id', 'communication_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_message_reactions');
    }
};
