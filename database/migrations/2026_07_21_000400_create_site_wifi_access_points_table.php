<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장 WiFi AP 화이트리스트 — 하이브리드 자동 출퇴근에서 "현장 내(실내 포함)" 확인용.
 *
 * 작업자 앱이 보낸 연결 BSSID 가 이 목록에 있으면 현장에 있는 것으로 판정한다(GPS 가 안 잡히는
 * 철골 실내 보완). 이름(SSID)이 아니라 장비 고유 MAC(BSSID)으로 검증해 가짜 핫스팟을 막는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_wifi_access_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('bssid', 20);              // AP MAC, 소문자 정규화 (aa:bb:cc:dd:ee:ff)
            $table->string('ssid', 64)->nullable();   // 참고용 WiFi 이름
            $table->string('label', 120)->nullable(); // 설치 위치 메모(예: 정문, B동 2층)
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'bssid']);
            $table->index(['bssid', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_wifi_access_points');
    }
};
