<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 현장 네트워크 항목에 종류를 붙인다.
 *
 * 지금까지는 AP 의 MAC(BSSID)만 등록할 수 있었다. 그런데 웹 브라우저는 접속한 WiFi 의
 * BSSID 를 읽을 방법이 없다 — 표준 API 자체가 없다. 그래서 BSSID 를 아무리 등록해도
 * 앱(네이티브)이 나오기 전까지는 아무 일도 일어나지 않았다.
 *
 * 브라우저에서도 통하는 신호가 하나 있다: 현장 WiFi 를 타고 나온 요청의 공인 IP.
 * 폰이 알려 주는 게 아니라 서버가 직접 보는 값이라 브라우저 제약을 받지 않는다.
 * 그래서 같은 표에 종류를 나눠 담는다 — 'bssid' 는 앱용, 'network' 는 지금 당장용.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_wifi_access_points', function (Blueprint $table): void {
            // bssid 칸이 값을 담는다. 종류에 따라 MAC 이거나 IP/CIDR 이다.
            $table->string('kind', 16)->default('bssid')->after('site_id');
        });

        // IPv6 CIDR 은 20자를 넘는다. 값 칸을 넓힌다.
        Schema::table('site_wifi_access_points', function (Blueprint $table): void {
            $table->string('bssid', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_wifi_access_points', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
