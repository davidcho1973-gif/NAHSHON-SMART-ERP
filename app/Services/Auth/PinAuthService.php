<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\AuthSetupToken;
use App\Models\LoginDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * PIN 로그인의 규칙이 모이는 한 곳.
 *
 * 구글 로그인과 <b>같은 판정</b>을 써야 한다 — "정지된 계정인가", "이 사람이 들어와도
 * 되는가" 를 두 벌로 두면 언젠가 한쪽만 고쳐지고, 그 순간 막아 둔 줄 알았던 문이 열린다.
 * 그래서 계정 상태 검사는 canSignIn() 하나로 모았다.
 *
 * ── 지키는 것 ─────────────────────────────────────────────────────────
 * 1. <b>관리자는 남의 PIN 을 정하지도 보지도 못한다.</b> 이 클래스에 "관리자가 PIN 을
 *    지정한다" 는 메서드가 없는 이유다. 발급되는 것은 링크뿐이고, 값은 본인만 넣는다.
 * 2. <b>4자리는 그것만으로 열쇠가 아니다.</b> 로그인은 기억된 기기 토큰이 있을 때만
 *    시도할 수 있다(가진 것 + 아는 것). 기기가 없으면 PIN 입력창 자체를 띄우지 않는다.
 * 3. <b>틀리면 느려진다.</b> 5회 틀리면 15분 잠근다 — 4자리는 만 가지뿐이라 잠금이 없으면
 *    기계가 몇 분 만에 다 시도한다.
 */
class PinAuthService
{
    public const PIN_LENGTH = 4;

    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    /** PIN 을 쓸 수 있는 역할 — 현장 인력용 문이다. 돈·인사 화면을 여는 계정은 구글로. */
    public const PIN_ROLES = ['worker', 'foreman'];

    /** 이 계정이 지금 로그인해도 되는가 — 구글·PIN 두 문이 함께 쓰는 판정. */
    public function canSignIn(User $user): bool
    {
        return $user->account_status === 'active';
    }

    public function eligibleForPin(User $user): bool
    {
        return in_array($user->access_role, self::PIN_ROLES, true);
    }

    /** 관리자가 초대·재설정 링크를 발급한다 — 값이 아니라 링크다. */
    public function issueSetupLink(User $user, string $purpose, ?User $actor = null): string
    {
        $token = AuthSetupToken::issue($user, $purpose, $actor?->id);

        AuthEvent::record(
            $purpose === AuthSetupToken::PURPOSE_RESET ? 'reset_issued' : 'invite_issued',
            user: $user,
            actor: $actor,
            method: 'pin',
        );

        return url('/auth/pin/setup/'.$token);
    }

    /**
     * 본인이 링크를 열고 PIN 을 정한다. 성공하면 이 기기를 기억하고 바로 로그인시킨다.
     *
     * @return array{success: bool, error?: string, device_token?: string, user?: User}
     */
    public function completeSetup(string $token, string $pin, Request $request): array
    {
        $row = AuthSetupToken::findUsable($token);
        if (! $row) {
            return ['success' => false, 'error' => '링크가 만료되었거나 이미 사용되었습니다. 관리자에게 새 링크를 요청하세요.'];
        }

        $user = $row->user;
        if (! $user || ! $this->canSignIn($user)) {
            return ['success' => false, 'error' => '사용할 수 없는 계정입니다. 관리자에게 문의하세요.'];
        }

        $bad = $this->rejectWeakPin($pin, $user);
        if ($bad !== null) {
            return ['success' => false, 'error' => $bad];
        }

        $user->forceFill([
            'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(),
            'pin_failed_count' => 0,
            'pin_locked_until' => null,
        ])->save();

        $row->consume();

        $deviceToken = LoginDevice::issueFor($user, $request->userAgent());

        AuthEvent::record('pin_set', user: $user, method: 'pin', request: $request);
        Auth::login($user);
        AuthEvent::record('login_ok', user: $user, method: 'pin', request: $request, note: '설정 직후 자동 로그인');

        return ['success' => true, 'device_token' => $deviceToken, 'user' => $user];
    }

    /**
     * 기억된 기기 + PIN 으로 로그인한다.
     *
     * @return array{success: bool, error?: string, user?: User}
     */
    public function attempt(string $deviceToken, string $pin, Request $request): array
    {
        $user = LoginDevice::resolve($deviceToken);
        if (! $user) {
            // 기기를 모른다 — 여기서 PIN 을 검사하면 4자리 단독 인증이 되어 버린다.
            return ['success' => false, 'error' => '이 휴대폰은 등록되어 있지 않습니다. 관리자에게 링크를 요청하세요.'];
        }

        if (! $this->canSignIn($user) || ! $user->pin_hash) {
            AuthEvent::record('login_fail', user: $user, method: 'pin', request: $request, note: '계정 비활성 또는 PIN 미설정');

            return ['success' => false, 'error' => '사용할 수 없는 계정입니다. 관리자에게 문의하세요.'];
        }

        if ($user->pin_locked_until && $user->pin_locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->pin_locked_until) / 60));

            return ['success' => false, 'error' => "여러 번 틀려 잠겼습니다. {$minutes}분 뒤에 다시 시도하세요."];
        }

        if (! Hash::check($pin, (string) $user->pin_hash)) {
            $count = (int) $user->pin_failed_count + 1;
            $locked = $count >= self::MAX_ATTEMPTS;

            $user->forceFill([
                'pin_failed_count' => $locked ? 0 : $count,
                'pin_locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();

            AuthEvent::record($locked ? 'locked' : 'login_fail', user: $user, method: 'pin', request: $request);

            return ['success' => false, 'error' => $locked
                ? self::LOCK_MINUTES.'분 동안 잠겼습니다. 기억나지 않으면 관리자에게 재설정을 요청하세요.'
                : '번호가 맞지 않습니다. ('.(self::MAX_ATTEMPTS - $count).'회 남음)'];
        }

        $user->forceFill(['pin_failed_count' => 0, 'pin_locked_until' => null])->save();

        Auth::login($user, remember: true);
        AuthEvent::record('login_ok', user: $user, method: 'pin', request: $request);

        return ['success' => true, 'user' => $user];
    }

    /** 너무 쉬운 PIN 은 거절한다 — 1111·1234·전화 뒷자리는 사실상 공개된 값이다. */
    private function rejectWeakPin(string $pin, User $user): ?string
    {
        if (! preg_match('/^\d{'.self::PIN_LENGTH.'}$/', $pin)) {
            return '숫자 '.self::PIN_LENGTH.'자리를 넣어 주세요.';
        }

        if (preg_match('/^(\d)\1+$/', $pin)) {
            return '같은 숫자만 쓸 수 없습니다. 다른 번호를 정해 주세요.';
        }

        $digits = str_split($pin);
        $ascending = true;
        $descending = true;
        for ($i = 1; $i < count($digits); $i++) {
            $ascending = $ascending && ((int) $digits[$i] === (int) $digits[$i - 1] + 1);
            $descending = $descending && ((int) $digits[$i] === (int) $digits[$i - 1] - 1);
        }
        if ($ascending || $descending) {
            return '1234 처럼 이어지는 번호는 쓸 수 없습니다.';
        }

        $phone = preg_replace('/\D/', '', (string) $user->employee?->phone);
        if ($phone !== null && $phone !== '' && str_ends_with($phone, $pin)) {
            return '전화번호 뒷자리는 쓸 수 없습니다. 다른 사람이 쉽게 압니다.';
        }

        return null;
    }
}
