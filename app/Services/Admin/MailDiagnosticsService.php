<?php

namespace App\Services\Admin;

use App\Support\MailReady;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 메일 진단 — "설정했다" 와 "실제로 나간다" 사이의 간격을 메운다.
 *
 * 설정 점검만으로는 부족하다. `MailReady::ok()` 가 보는 것은 세 가지뿐이다 —
 * 메일러가 log 가 아닌가, 호스트가 localhost 가 아닌가, 발신 주소가 예제 주소가 아닌가.
 * 비밀번호가 맞는지, 그 포트가 열려 있는지, 발신 도메인이 인증됐는지는 <b>아무도 안 본다.</b>
 *
 * 그래서 초록불인데 한 통도 안 나가는 길이 여럿 있다. 실제로 우리 MAIL_SETUP.md 가
 * `MAIL_SCHEME=tls` 를 넣으라고 적고 있었는데(Symfony 는 smtp/smtps 만 받는다), 그렇게 넣으면
 * 점검은 초록이고 발송은 예외로 죽는다.
 *
 * 이 사이를 메우는 유일한 방법은 <b>한 통을 진짜로 보내 보고 예외를 읽는 것</b>이다.
 *
 * 두 가지를 지킨다.
 *  1. 수신자를 화면에서 받지 않는다. 로그인한 <b>본인 주소로 고정</b>한다 — 주소를 받는 순간
 *     이 기능은 ERP 를 통해 아무 데나 메일을 쏘는 창구가 된다.
 *  2. 예외 메시지를 그대로 보여 주되 <b>비밀번호를 지우고</b> 보여 준다. Symfony 의 전송 예외는
 *     DSN 을 통째로 찍는 경우가 있어서, 원문을 그대로 화면에 올리면 SMTP 비밀번호가 샌다.
 */
class MailDiagnosticsService
{
    /** 조직 설정과 같은 권한을 쓴다 — 새 역할 배열을 만들면 규칙이 갈라진다. */
    public const MANAGE_ROLES = OrgSettingService::MANAGE_ROLES;

    /**
     * 지금 메일 설정이 어떤 상태인가.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '조직 설정 권한이 필요합니다.'];
        }

        $mailer = (string) config('mail.default', 'log');
        $from = trim((string) config('mail.from.address', ''));
        $host = trim((string) config('mail.mailers.smtp.host', ''));
        $scheme = strtolower(trim((string) config('mail.mailers.smtp.scheme', '')));
        $schemeOk = $scheme === '' || in_array($scheme, ['smtp', 'smtps'], true);
        $driverOk = $this->driverInstalled($mailer);
        $ready = MailReady::ok() && $schemeOk && $driverOk;

        return [
            'success' => true,
            'ready' => $ready,
            'canSendTest' => $this->canManage(),
            'testTo' => (string) (Auth::user()?->email ?: ''),
            'rows' => [
                // 로그인한 super_admin 에게만 보이므로 호스트명은 그대로 보여 준다
                // (smtp.gmail.com 은 비밀이 아니고, 오타를 눈으로 잡아야 한다).
                $this->row('메일러', $mailer, $mailer !== 'log' && $mailer !== 'array',
                    $mailer === 'log' ? '설정이 없어 로그 파일에만 쌓입니다 — 아무도 못 받습니다.' : null),
                $this->row('MAIL_SCHEME', $scheme === '' ? '(비어 있음 — 정상)' : $scheme, $schemeOk,
                    $schemeOk ? null : "쓸 수 없는 값입니다. smtp / smtps 만 됩니다. 587 포트면 비워 두세요."),
                $this->row('호스트', $host !== '' ? $host : '(비어 있음)',
                    $host !== '' && ! in_array(strtolower($host), ['127.0.0.1', 'localhost'], true)),
                $this->row('포트', (string) config('mail.mailers.smtp.port', ''), true),
                $this->row('사용자명', $this->maskedUser(), trim((string) config('mail.mailers.smtp.username', '')) !== ''),
                // 비밀번호는 길이도 안 내보낸다 — 별표 개수가 길이를 흘린다.
                $this->row('비밀번호', trim((string) config('mail.mailers.smtp.password', '')) !== '' ? '설정됨' : '비어 있음',
                    trim((string) config('mail.mailers.smtp.password', '')) !== ''),
                $this->row('발신 주소', $this->maskedFrom($from),
                    $from !== '' && ! Str::endsWith(strtolower($from), '@example.com'),
                    Str::endsWith(strtolower($from), '@example.com') ? '라라벨 기본값입니다 — 이 주소로는 거의 모든 서버가 거절합니다.' : null),
                $this->row('드라이버 패키지', $driverOk ? '설치됨' : '없음', $driverOk,
                    $driverOk ? null : "메일러 «{$mailer}» 에 필요한 패키지가 없습니다. SMTP 방식으로 바꾸면 패키지 없이 됩니다."),
                $this->row('설정 캐시', file_exists(base_path('bootstrap/cache/config.php')) ? '캐시됨' : '캐시 없음', true,
                    file_exists(base_path('bootstrap/cache/config.php'))
                        ? '환경변수를 바꿨다면 재배포해야 반영됩니다.' : null),
                $this->row('일일 보고 수신처', $this->recipientCount().'명', ($this->recipientCount() ?? 0) > 0,
                    ($this->recipientCount() ?? 0) > 0 ? null : '받을 사람이 없으면 설정이 맞아도 아무 데도 안 갑니다.'),
            ],
            'message' => $ready
                ? '설정은 정상입니다. 실제로 나가는지는 아래 테스트 발송으로 확인하세요.'
                : ($schemeOk ? MailReady::why() : "MAIL_SCHEME 값 «{$scheme}» 이 잘못됐습니다."),
        ];
    }

    /**
     * 나에게 테스트 메일 한 통 — 이것만이 "진짜 나가는가" 에 답한다.
     *
     * @return array<string, mixed>
     */
    public function sendTest(): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '조직 설정 권한이 필요합니다.'];
        }

        $to = trim((string) (Auth::user()?->email ?: ''));
        if ($to === '') {
            return ['success' => false, 'error' => '내 계정에 이메일 주소가 없습니다. 프로필에서 먼저 등록해 주세요.'];
        }

        // 설정이 없으면 시도조차 하지 않는다. `log` 메일러는 예외 없이 성공해서
        // "보냈다" 는 거짓 결과를 만든다 — 이 도구가 가장 막아야 할 것이 그것이다.
        if (! MailReady::ok()) {
            return ['success' => false, 'error' => MailReady::why().' 이 상태에서는 보내도 로그 파일에만 쌓입니다.'];
        }

        $scheme = strtolower(trim((string) config('mail.mailers.smtp.scheme', '')));
        if ($scheme !== '' && ! in_array($scheme, ['smtp', 'smtps'], true)) {
            return ['success' => false, 'error' => "MAIL_SCHEME 값 «{$scheme}» 은 쓸 수 없습니다(smtp / smtps 만 가능). 이 값을 지우기 전에는 한 통도 못 나갑니다."];
        }

        $stamp = now()->format('Y-m-d H:i:s');

        try {
            Mail::raw(
                "NAHSHON ERP 메일 설정 테스트입니다.\n\n"
                ."이 메일이 도착했다면 발송 설정이 정상입니다.\n"
                ."일일 작업계획서와 마감보고서가 원청에 나갈 수 있습니다.\n\n"
                ."발송 시각: {$stamp}\n"
                ."메일러: ".config('mail.default').' / '.config('mail.mailers.smtp.host'),
                fn ($m) => $m->to($to)->subject('[NAHSHON ERP] 메일 설정 테스트 — '.$stamp),
            );
        } catch (\Throwable $e) {
            report($e);

            return [
                'success' => false,
                'error' => $to.' 로 보내지 못했습니다.',
                'detail' => $this->mask(get_class($e).': '.$e->getMessage()),
                'hint' => $this->hint($e->getMessage()),
            ];
        }

        return [
            'success' => true,
            'message' => $to.' 로 테스트 메일을 보냈습니다. 받은편지함(스팸함도)을 확인해 주세요.',
            'sentAt' => $stamp,
        ];
    }

    /* ── 안전장치 ───────────────────────────────────────────────── */

    /**
     * 예외 메시지에서 비밀값을 지운다.
     *
     * Symfony 의 전송 예외는 DSN 을 통째로 찍는 경우가 있다
     * (`smtp://user:PASSWORD@host:587`). 원문을 그대로 화면에 올리면 그 순간 유출이다.
     */
    private function mask(string $text): string
    {
        $secrets = array_filter([
            (string) config('mail.mailers.smtp.password'),
            (string) config('services.resend.key'),
            (string) config('services.postmark.token'),
        ], fn (string $s): bool => strlen(trim($s)) >= 6);

        foreach ($secrets as $s) {
            $text = str_replace($s, '***', $text);
        }

        // DSN 안의 자격증명(scheme://user:pass@host)은 값을 몰라도 모양으로 지운다.
        $text = (string) preg_replace('#(\w+://)[^:/@\s]+:[^@\s]+@#', '$1***:***@', $text);

        return Str::limit($text, 600);
    }

    /** 오류 메시지 → 사람이 할 일. 원인을 짚어 주지 않으면 화면의 영문 예외는 쓸모가 없다. */
    private function hint(string $message): string
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'scheme is not supported') => 'MAIL_SCHEME 값을 지우세요. 587 포트면 비워 두는 것이 정답입니다.',
            str_contains($m, 'authenticate') || str_contains($m, '535') =>
                '비밀번호가 틀렸습니다. Gmail 이면 일반 비밀번호가 아니라 앱 비밀번호(16자, 공백 제거)여야 합니다.',
            str_contains($m, 'connection') || str_contains($m, 'timed out') || str_contains($m, 'refused') =>
                '서버에 연결하지 못했습니다. 호스트 주소와 포트를 확인하세요(대개 587).',
            str_contains($m, 'not verified') || str_contains($m, 'domain') =>
                '발신 도메인이 인증되지 않았습니다. 제공자에서 SPF/DKIM 인증을 마치세요.',
            str_contains($m, 'class') && str_contains($m, 'not found') =>
                '메일러 드라이버 패키지가 없습니다. SMTP 방식으로 바꾸면 패키지 없이 됩니다.',
            str_contains($m, 'sender') || str_contains($m, '553') || str_contains($m, '550') =>
                '발신 주소가 거절됐습니다. MAIL_FROM_ADDRESS 를 인증된 계정 주소와 같게 맞추세요.',
            default => '위 메시지를 그대로 알려 주시면 원인을 짚어 드리겠습니다.',
        };
    }

    private function canManage(): bool
    {
        return in_array((string) Auth::user()?->access_role, self::MANAGE_ROLES, true);
    }

    private function driverInstalled(string $mailer): bool
    {
        return match ($mailer) {
            'resend' => class_exists(\Resend\Laravel\ResendServiceProvider::class),
            'postmark' => class_exists(\Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport::class),
            'mailgun' => class_exists(\Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport::class),
            'ses', 'ses-v2' => class_exists(\Aws\Ses\SesClient::class),
            default => true,
        };
    }

    /** 계정 아이디는 안 보여 준다 — 도메인만으로도 오타는 잡힌다. */
    private function maskedUser(): string
    {
        $u = trim((string) config('mail.mailers.smtp.username', ''));
        if ($u === '') {
            return '비어 있음';
        }

        return str_contains($u, '@') ? '***@'.substr($u, strpos($u, '@') + 1) : '설정됨';
    }

    private function maskedFrom(string $from): string
    {
        if ($from === '') {
            return '비어 있음';
        }
        // 발신 주소는 받는 사람 모두가 보는 값이라 통째로 보여도 된다.
        return $from;
    }

    private function recipientCount(): ?int
    {
        try {
            return Schema::hasTable('report_recipients')
                ? \App\Models\ReportRecipient::where('active', true)->count()
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function row(string $label, string $value, bool $ok, ?string $note = null): array
    {
        return ['label' => $label, 'value' => $value, 'ok' => $ok, 'note' => $note];
    }
}
