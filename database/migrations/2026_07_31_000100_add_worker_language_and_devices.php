<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작업자 언어 + 기기 기억.
 *
 *  preferred_language : 등록할 때 고른 언어(ko/en/es). 출퇴근 화면이 이 언어로 열린다.
 *  worker_devices     : 휴대폰 1대 = 토큰 1개. 게이트 QR 을 스캔하면 이름을 다시 찾지 않고
 *                       바로 본인 화면이 뜬다. 로그인이 아니라 "이 기기를 기억"하는 방식이다.
 *                       (현장에서 로그인·앱 설치를 강요할 수 없어 선택한 절충안)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('preferred_language', 5)->default('ko')->after('nationality');
        });

        Schema::create('worker_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // 토큰은 기기에만 저장된다. 유출 위험을 줄이려 해시로 조회한다.
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();   // 기기 식별용 메모(User-Agent 요약)
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_devices');

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('preferred_language');
        });
    }
};
