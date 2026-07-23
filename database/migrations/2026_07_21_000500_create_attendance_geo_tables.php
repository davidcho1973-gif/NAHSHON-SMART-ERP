<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 하이브리드 자동 출퇴근 — 원시 위치 이벤트 로그 + 하루 세션 요약.
 *
 * 판정: GPS 부지 반경 안 OR 현장 WiFi(BSSID) 연결 = 현장 내. 진입/이탈을 이벤트로 쌓고,
 * 세션이 "근무중 구간의 합"(이탈시간 차감)을 누적한다. 자정에 마지막 이탈을 퇴근으로 확정.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_geo_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('kind', 12);            // enter / exit / ping
            $table->string('source', 12);          // gps / wifi / both / manual
            $table->boolean('on_site')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('accuracy')->nullable();   // meters
            $table->string('bssid', 20)->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'occurred_at']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->date('work_date');
            $table->string('status', 12)->default('on_site'); // on_site / left / finalized
            $table->timestamp('first_enter_at')->nullable();
            $table->timestamp('last_enter_at')->nullable();
            $table->timestamp('pending_exit_at')->nullable();  // 이탈 후보(체류시간 확정 전)
            $table->timestamp('last_exit_at')->nullable();
            $table->unsignedInteger('on_site_seconds')->default(0); // 근무중 구간 합(이탈 차감)
            $table->boolean('needs_review')->default(false);   // 자정에 아직 현장 안 → 미마감
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_geo_events');
        Schema::dropIfExists('attendance_sessions');
    }
};
