<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Microsoft 365 로 메일을 보낸다 — SMTP 가 아니라 Graph API 로.
 *
 * <b>왜 SMTP 를 안 쓰는가.</b> 마이크로소프트는 SMTP 클라이언트 제출의 기본 인증(아이디·비밀번호)을
 * 폐지하는 중이다. 2026-04-30 부터 일부 발송이 거절되기 시작했고, 2026년 12월 말에는 기존
 * 테넌트에서 기본값으로 꺼진다. 그 위에 지으면 <b>지금은 간헐적으로 실패하고 연말에는 통째로
 * 멈춘다.</b> 원청에 매일 나가야 하는 보고서를 그런 토대에 올릴 수 없다.
 *
 * Graph 는 OAuth 클라이언트 자격증명으로 토큰을 받아 쓴다. 만료되는 비밀번호가 없고, 고정 IP 도
 * 필요 없어(Laravel Cloud 는 서버리스라 IP 가 매번 바뀐다) 릴레이 커넥터 방식도 쓸 수 없는
 * 우리 환경에 맞는 유일한 길이다.
 *
 * <b>MIME 를 통째로 보낸다.</b> Graph 는 JSON 으로 제목·본문·수신자를 따로 받는 방식도 있지만,
 * 그러면 우리가 발급한 Message-ID 와 References 헤더가 사라진다 — 그 두 헤더가 서신 원장의
 * 열쇠이고 2단계(회신 수신)의 전제다. Symfony 가 이미 만든 완성된 MIME 를 base64 로 그대로
 * 실어 보내면 헤더가 하나도 손상되지 않는다.
 *
 * 보낸 메일은 그 사서함의 «보낸 편지함» 에도 남는다 — 사람이 아웃룩에서도 확인할 수 있다.
 */
class GraphTransport extends AbstractTransport
{
    /**
     * Graph 의 sendMail 요청 본문 상한.
     *
     * 마이크로소프트가 문서에 숫자를 명시하지 않아 보수적으로 잡는다. base64 는 원본보다
     * 약 1.37배 커지므로 실제로 실을 수 있는 첨부는 이보다 훨씬 작다. 넘으면 <b>조용히
     * 실패시키지 않고</b> 무엇을 해야 하는지 말해 준다.
     */
    private const MAX_MIME_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        /** 이 사서함의 이름으로 나간다. 예: erp@nahshonmep.com */
        private readonly string $sender,
        private readonly bool $saveToSentItems = true,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $mime = $message->toString();

        if (strlen($mime) > self::MAX_MIME_BYTES) {
            throw new TransportException(sprintf(
                'Graph 로 보내기에 메일이 너무 큽니다 (%.1fMB). 첨부를 줄이거나 문서함 링크로 대체하세요. '
                .'(Microsoft Graph sendMail 은 큰 첨부를 받지 않습니다)',
                strlen($mime) / 1024 / 1024,
            ));
        }

        $response = Http::withToken($this->token())
            ->withBody(base64_encode($mime), 'text/plain')
            ->timeout(60)
            ->post(sprintf(
                'https://graph.microsoft.com/v1.0/users/%s/sendMail',
                rawurlencode($this->sender),
            ));

        if ($response->successful()) {
            return;
        }

        // 오류를 그대로 올린다 — 무엇이 잘못됐는지 화면에서 읽혀야 고칠 수 있다.
        $error = $response->json('error.message') ?: $response->body();
        $code = $response->json('error.code') ?: $response->status();

        throw new TransportException($this->explain((string) $code, (string) $error, $response->status()));
    }

    /**
     * 토큰을 받아 둔다.
     *
     * 매번 받으면 발송마다 왕복이 하나 더 는다. 만료 1분 전까지만 재사용한다 —
     * 정확히 만료 시각까지 쓰면 경계에서 401 이 난다.
     */
    private function token(): string
    {
        $key = 'graph-mail-token:'.md5($this->tenantId.$this->clientId);

        return Cache::remember($key, now()->addMinutes(50), function (): string {
            $res = Http::asForm()->timeout(30)->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            );

            $token = $res->json('access_token');
            if (! is_string($token) || $token === '') {
                // 자격증명 오류는 여기서 잡힌다. 응답 본문에 비밀값은 없지만
                // 혹시 몰라 설명만 올린다.
                throw new TransportException(
                    'Microsoft 인증에 실패했습니다: '
                    .($res->json('error_description') ? explode("\r\n", (string) $res->json('error_description'))[0] : $res->status())
                );
            }

            return $token;
        });
    }

    /** Graph 오류 코드 → 사람이 할 일. 영문 코드만 보여 주면 아무도 못 고친다. */
    private function explain(string $code, string $message, int $status): string
    {
        $hint = match (true) {
            $status === 401 => '토큰이 거절됐습니다. 클라이언트 비밀값이 만료됐는지 확인하세요.',
            $status === 403 || str_contains($code, 'ErrorAccessDenied') =>
                'Mail.Send 응용 프로그램 권한과 관리자 동의가 필요합니다. Entra ID > 앱 등록 > API 권한에서 확인하세요.',
            str_contains($code, 'MailboxNotEnabled') || str_contains($message, 'not found') =>
                "발신 사서함({$this->sender})을 찾을 수 없습니다. Exchange Online 라이선스가 붙은 실제 사서함이어야 합니다.",
            $status === 429 => '보내는 속도가 제한에 걸렸습니다. 잠시 뒤 다시 시도하세요.',
            default => '',
        };

        return trim("Graph 발송 실패 [{$code}] {$message} {$hint}");
    }

    public function __toString(): string
    {
        return 'graph://'.$this->sender;
    }
}
