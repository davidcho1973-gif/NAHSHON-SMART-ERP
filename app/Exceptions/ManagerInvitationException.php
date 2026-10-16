<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Invalid enrollment credentials never authenticate; old shortcuts recover via normal login. */
class ManagerInvitationException extends HttpException
{
    public function __construct(private readonly bool $verificationExpired = false, private readonly ?string $restartToken = null)
    {
        parent::__construct(410, $verificationExpired
            ? '전화번호 확인 시간이 지났거나 확인 정보가 없습니다. 초대 링크에서 전화번호를 다시 확인하세요.'
            : '이 초대 링크로는 등록을 진행할 수 없습니다.');
    }

    public static function unavailable(): self
    {
        return new self(false);
    }

    public static function verificationExpired(?string $token = null): self
    {
        return new self(true, is_string($token) && preg_match('/\\A[A-Za-z0-9]{64}\\z/', $token) ? $token : null);
    }

    public function render(Request $request): Response
    {
        // A phone shortcut can retain an already-used invitation URL. Browsing it
        // goes to ordinary login, without redeeming the token or selecting its user.
        if ($request->isMethod('GET') && ! $request->expectsJson() && ! $this->verificationExpired) {
            return redirect()->route('login', ['app' => 1])
                ->with('status', '이 가입 링크로는 등록을 진행할 수 없습니다. 이미 가입했다면 기존 계정으로 로그인하세요. 처음 가입하는 분은 인사담당자에게 새 초대 링크를 요청하세요.')
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
        // No account details or private invalidation reasons enter the public error.
        // A restart URL only reopens the normal token validation and phone-verification flow.
        $response = $request->expectsJson()
            ? response()->json(['message' => $this->getMessage(), 'code' => $this->verificationExpired
                ? 'invitation_verification_required' : 'invitation_unavailable'], 410)
            : response()->view('auth.manager-invitation-unavailable', [
                'verificationExpired' => $this->verificationExpired,
                'restartUrl' => $this->restartToken ? route('manager-invitation.show', ['token' => $this->restartToken]) : null,
            ], 410);

        return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
