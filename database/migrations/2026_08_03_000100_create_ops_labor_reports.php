<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장 보고 인원 — "오늘 몇 명 나와서 일했나".
 *
 * 실제 출퇴근 기록(attendance_logs)과 **일부러 분리한다.**
 * 출퇴근 기록은 급여의 근거라 게이트 QR·GPS 로 본인이 찍은 것만 들어가야 한다.
 * 반면 "한빛전기 3명 왔습니다" 같은 현장 보고는 근거가 사람의 말이라, 그대로 근태에
 * 밀어 넣으면 오지 않은 사람이 근무한 것으로 남고 임금 분쟁의 씨앗이 된다.
 *
 * 그래서 두 값을 나란히 두고 차이를 보여준다:
 *   보고 3명 vs QR 2명 → "1명 미확인" 이 곧 관리 포인트다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_labor_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('ops_intake_batch_id')->nullable()->constrained('ops_intake_batches')->nullOnDelete();
            $table->foreignId('ops_intake_item_id')->nullable()->constrained('ops_intake_items')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->date('work_date')->index();
            $table->string('company_label', 120)->nullable();   // AI 가 읽은 업체명 원문(미등록 업체 대비)
            $table->string('trade', 80)->nullable();            // 공종(전기/배관 등)
            $table->unsignedSmallInteger('headcount');
            $table->text('note')->nullable();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_labor_reports');
    }
};
