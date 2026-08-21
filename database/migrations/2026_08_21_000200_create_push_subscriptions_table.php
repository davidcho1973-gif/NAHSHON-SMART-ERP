<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 이 사람의 이 기기로 알림을 보내는 주소.
 *
 * 현장 작업자는 화면을 계속 보고 있지 않다. 긴급 지시가 방에 올라와도 폰이 주머니에
 * 있으면 아무 일도 일어나지 않는다 — 실시간 수신보다 <b>푸시가 먼저</b>인 이유다.
 *
 * endpoint 는 브라우저가 발급하는 그 기기 전용 주소다. 사람 하나가 폰·태블릿·PC 를
 * 쓰면 줄이 셋 생긴다(그래서 user 마다 여러 줄). endpoint 는 유일해야 한다 —
 * 같은 기기가 두 번 등록되면 알림이 두 번 온다.
 *
 * 구독은 사용자가 앱을 지우거나 브라우저 데이터를 지우면 죽는다. 죽은 주소로 보내면
 * 404/410 이 오고, 그때 이 줄을 지운다(WebPushSender).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // 브라우저가 준 공개키와 인증 비밀 — 이 둘이 있어야 내용을 암호화해 보낼 수 있다.
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 40)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // endpoint 는 길어서 통째로 유니크 인덱스를 걸 수 없다 — 해시로 건다.
            $table->string('endpoint_hash', 64)->unique();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
