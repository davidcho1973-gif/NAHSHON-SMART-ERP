<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 손님 전용 링크 — 로그인 없이 특정 현장의 공정 현황만 보는 열람 창구.
 *
 * 발주처·방문객에게 계정을 만들어 주지 않고도 "지금 얼마나 왔나"를 보여 주기 위한
 * 토큰이다. 서명 URL 이 아니라 표에 두는 이유는 회수(revoke) 때문이다 — 링크는
 * 전달되는 순간 복제되므로, 잘못 퍼졌을 때 서버에서 끊을 수 있어야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // 누구에게 준 링크인지 적는 메모("발주처 GC", "시청 감리"). 회수할 때 이걸 보고 고른다.
            $table->string('label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_links');
    }
};
