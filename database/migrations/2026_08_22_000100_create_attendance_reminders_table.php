<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 아침 출근 알림을 "오늘 이 사람에게 몇 번 보냈는가" — 두 번 넘게 울리지 않기 위한 장부.
 *
 * 캐시에 두지 않는 이유: 서버리스 배포에서 파일 캐시는 인스턴스가 갈리면 사라진다.
 * 그러면 같은 아침에 같은 사람에게 알림이 두 번 세 번 가고, 사람들은 알림을 꺼 버린다 —
 * 꺼 버린 알림은 영영 다시 못 켠다(브라우저가 다시 묻지 않는다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedTinyInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reminders');
    }
};
