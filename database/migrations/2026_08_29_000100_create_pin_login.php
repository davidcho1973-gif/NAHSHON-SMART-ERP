<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN 로그인 — 구글 계정이 없는 현장 인력이 자기 폰으로 들어오는 두 번째 문.
 *
 * 구조: 관리자가 초대 링크를 발급 → 본인이 링크를 열어 4자리 PIN 을 직접 정한다 →
 * 그 순간 폰이 기억된다 → 다음부터는 기억된 폰에서 PIN 4자리만 넣으면 들어온다.
 *
 * 왜 이렇게 하나:
 *  - <b>관리자는 남의 PIN 을 모른다.</b> 출퇴근이 급여의 근거인 이상, 관리자가 남의
 *    열쇠를 알면 그 기록은 임금 분쟁에서 회사를 방어하지 못한다. 그래서 관리자 화면에는
 *    값을 넣는 칸이 없고 상태와 [재발급] 버튼만 둔다.
 *  - <b>4자리만으로는 못 들어온다.</b> 기억된 기기(가진 것) + PIN(아는 것) 두 가지가
 *    맞아야 한다. 새 폰에서는 초대·재설정 링크가 다시 필요하다.
 *
 * auth_events 는 이 앱에 처음 생기는 인증 감사 기록이다 — 누가 언제 어떤 문으로
 * 들어왔고 누가 링크를 발급했는지가 남지 않으면 사고가 나도 되짚을 수가 없다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pin_hash')->nullable()->after('password');
            $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
            $table->unsignedSmallInteger('pin_failed_count')->default(0)->after('pin_set_at');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_count');
        });

        // 로그인용 기기 기억 — 게이트 출퇴근용 worker_devices 와 목적이 다르다.
        // 저쪽은 "이 폰의 주인이 누구인가"(출퇴근), 이쪽은 "이 폰에서 로그인해도 되는가".
        // 섞으면 출퇴근 기기를 지웠을 때 로그인이 끊기는 식으로 서로를 망가뜨린다.
        Schema::create('login_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();   // 원문은 기기에만 남는다
            $table->string('label', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });

        // 초대·재설정 링크. 원문 토큰은 링크에만 있고 서버는 해시만 갖는다.
        Schema::create('auth_setup_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 20)->default('invite');  // invite | reset
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create('auth_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);          // login_ok, login_fail, locked, invite_issued, pin_set …
            $table->string('method', 20)->nullable();  // google | pin
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('auth_setup_tokens');
        Schema::dropIfExists('login_devices');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['pin_hash', 'pin_set_at', 'pin_failed_count', 'pin_locked_until']);
        });
    }
};
