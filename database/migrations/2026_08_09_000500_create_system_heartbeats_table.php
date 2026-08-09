<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "이 부품이 마지막으로 살아 있던 시각".
 *
 * 스케줄러가 살아 있는지 알려면 어딘가에 시각을 남겨야 하는데, 캐시에 두면 안 된다.
 * 캐시를 파일로 돌렸기 때문이다 — 파일 캐시는 컨테이너마다 따로라, 스케줄러가 쓴 값을
 * 웹 화면이 읽지 못한다. 그래서 컨테이너가 달라도 같은 것을 보는 데이터베이스에 둔다.
 *
 * 표를 따로 판 이유는 캐시 표를 빌려 쓰면 캐시를 비울 때 같이 날아가기 때문이다.
 * 이건 캐시가 아니라 기록이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();   // scheduler 등
            // 이 프로젝트의 다른 시각 칸과 같이 timestamp 를 쓴다. timestampTz 로 두면
            // 앱 타임존(America/Phoenix)과 어긋나 읽을 때 7시간이 밀린다.
            $table->timestamp('beat_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
