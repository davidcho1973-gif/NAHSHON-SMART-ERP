<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1 직접 메시지(DM)와 알림 벨 피드 지원을 추가한다.
 * - communication_rooms.dm_key: 두 직원 id를 정렬한 "작은id-큰id" 키로 DM 방을 유일하게 식별한다.
 * - communication_notifications: 공지 등록 시 직원별로 뿌려지는 알림 피드(상단 벨)를 저장한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_rooms', function (Blueprint $table): void {
            $table->string('dm_key')->nullable()->unique()->after('type');
        });

        Schema::create('communication_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('communication_room_id')->nullable()->constrained('communication_rooms')->cascadeOnDelete();
            $table->foreignId('communication_message_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->string('type', 40)->default('announcement')->index();
            $table->string('title');
            $table->string('body')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'read_at']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_notifications');

        Schema::table('communication_rooms', function (Blueprint $table): void {
            $table->dropUnique(['dm_key']);
            $table->dropColumn('dm_key');
        });
    }
};
