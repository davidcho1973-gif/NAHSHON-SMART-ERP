<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 메일을 진짜로 보낼 수 있는 상태인가 — 한 곳에서만 판정한다.
 *
 * 같은 판정이 이미 두 군데에 복사돼 있었다(제출물 소통, 지원자 초대). 세 번째가
 * 생기면 규칙이 갈라지고, 한쪽만 고친 날 "보냈다는데 안 갔다" 가 시작된다.
 *
 * <b>이 판정이 거짓이면 보낸 척하지 않는다.</b> 라라벨의 기본 메일러는 `log` 라
 * 설정 없이 `Mail::send()` 를 부르면 아무 예외 없이 성공한다 — 로그 파일에만
 * 쌓이고 원청은 영원히 받지 못한다. 그래서 보내기 전에 먼저 묻고, 아니면
 * 사장님 메일앱을 여는 `mailto:` 로 넘긴다(그건 확실히 사람 손을 거친다).
 */
final class MailReady
{
    /** 실제 발송이 가능한 설정인가. */
    public static function ok(): bool
    {
        $mailer = (string) config('mail.default', 'log');

        // log·array 는 보관만 하고 밖으로 나가지 않는다.
        if (in_array($mailer, ['log', 'array'], true)) {
            return false;
        }

        if ($mailer === 'smtp') {
            $host = Str::lower((string) config('mail.mailers.smtp.host', ''));
            if ($host === '' || in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return false;
            }
        }

        // Microsoft 365(Graph) — 세 값이 다 있어야 토큰을 받을 수 있다.
        // 하나라도 비면 발송 순간에 인증 실패로 죽으므로 미리 막는다.
        if ($mailer === 'graph' && ! self::graphConfigured()) {
            return false;
        }

        $from = Str::lower((string) config('mail.from.address', ''));

        // 보내는 주소가 없거나 예제 주소면 대부분의 메일 서버가 거절한다.
        return $from !== '' && ! Str::endsWith($from, '@example.com');
    }

    /** Graph 발송에 필요한 값이 다 있는가. */
    public static function graphConfigured(): bool
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'sender'] as $k) {
            if (trim((string) config("mail.mailers.graph.{$k}", '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 왜 못 보내는지 — 이유만 돌려준다.
     *
     * "대신 메일앱을 엽니다" 같은 뒷말은 붙이지 않는다. 사람이 보는 화면에서는
     * 맞는 말이지만, 정해진 시각에 도는 자동 발송 자리에는 메일앱을 열어 줄
     * 사람이 없어서 거짓말이 된다. 뒷말은 부르는 쪽이 붙인다.
     */
    public static function why(): string
    {
        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array'], true)) {
            return '메일 서버가 아직 설정되지 않았습니다(현재 '.$mailer.' 모드).';
        }
        if ($mailer === 'smtp' && Str::lower((string) config('mail.mailers.smtp.host', '')) === '') {
            return 'SMTP 주소가 비어 있습니다.';
        }
        if ($mailer === 'graph' && ! self::graphConfigured()) {
            $missing = [];
            foreach (['tenant_id' => '테넌트 ID', 'client_id' => '클라이언트 ID',
                'client_secret' => '클라이언트 비밀값', 'sender' => '발신 사서함'] as $k => $label) {
                if (trim((string) config("mail.mailers.graph.{$k}", '')) === '') {
                    $missing[] = $label;
                }
            }

            return 'Microsoft 365 설정이 덜 됐습니다 — '.implode(', ', $missing).'가 비어 있습니다.';
        }

        return '보내는 사람 주소(MAIL_FROM_ADDRESS)가 설정되지 않았습니다.';
    }

    /**
     * 사람이 자기 메일앱으로 보내게 하는 링크.
     *
     * @param  string|list<string>  $to
     */
    public static function mailto(string|array $to, string $subject, string $body, array $cc = []): string
    {
        $query = ['subject' => $subject, 'body' => $body];
        if ($cc !== []) {
            $query['cc'] = implode(',', $cc);
        }

        return 'mailto:'.rawurlencode(is_array($to) ? implode(',', $to) : $to)
            .'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
