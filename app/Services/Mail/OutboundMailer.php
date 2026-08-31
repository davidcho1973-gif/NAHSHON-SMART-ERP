<?php

namespace App\Services\Mail;

use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\Site;
use App\Support\MailReady;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * 나가는 메일이 지나는 <b>단 하나의 문</b>.
 *
 * 지금까지 발송이 네 군데에 흩어져 있었다(제출물 요청·제출물 전달·일일 보고·지원자 초대).
 * 각자 `Mail::to()` 를 직접 부르고 각자 다른 표에 이력을 남겼고, 본문은 어디에도 안 남았다.
 * 그래서 "8월 30일에 뭐라고 보냈나" 에 답할 수 없었다.
 *
 * 문을 하나로 모으는 이유는 기록 때문이다. <b>채우는 주체가 하나면 원장이 비어 갈 수 없다.</b>
 * 새 발송 기능이 생겨도 이 문을 지나면 저절로 원장에 남는다.
 *
 * 세 가지를 지킨다.
 *
 *  1. <b>보낸 척하지 않는다.</b> 라라벨 기본 메일러는 `log` 라 설정이 없어도 발송이 예외 없이
 *     "성공" 한다. 먼저 묻고, 아니면 채널을 `mailto` 로 정직하게 적는다.
 *  2. <b>실패도 남긴다.</b> 성공만 기록하는 원장은 분쟁에서 아무 힘이 없다.
 *  3. <b>Message-ID 를 우리가 발급한다.</b> 이게 없으면 회신이 와도 어느 서신의 답인지
 *     이어 붙일 열쇠가 없다 — 2단계(수신)가 성립하지 않는다.
 */
class OutboundMailer
{
    /**
     * 메일 한 통을 보내고 원장에 남긴다.
     *
     * @param  array<int, array{email: string, name?: string|null}>  $to
     * @param  array<int, array{email: string, name?: string|null}>  $cc
     * @param  callable(string $messageId, array<int,string> $references): Mailable  $build
     *         Mailable 을 만드는 함수. Message-ID 를 우리가 정하므로 만드는 쪽에 넘겨준다.
     * @param  array<int, int>  $documentIds  이 메일에 실린 문서함 문서
     * @return array<string, mixed>
     */
    public function send(
        MailThread $thread,
        array $to,
        array $cc,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        callable $build,
        array $documentIds = [],
        int $attachmentCount = 0,
    ): array {
        $to = $this->clean($to);
        $cc = $this->clean($cc);

        if ($to === []) {
            return ['success' => false, 'error' => '받는 사람이 없습니다.'];
        }

        $messageId = $this->newMessageId();
        $references = $thread->messages()->whereNotNull('rfc_message_id')
            ->orderBy('occurred_at')->pluck('rfc_message_id')->all();
        $inReplyTo = $references === [] ? null : end($references);

        // 먼저 원장에 적고 보낸다. 반대로 하면 발송 도중 프로세스가 죽었을 때
        // 나갔는지 안 나갔는지 아무도 모르는 메일이 생긴다.
        $message = MailMessage::create([
            'mail_thread_id' => $thread->id,
            'company_id' => $thread->company_id,
            'site_id' => $thread->site_id,
            'direction' => 'outgoing',
            'channel' => MailReady::ok() ? 'mail' : 'mailto',
            'status' => 'queued',
            'rfc_message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references_raw' => $references === [] ? null : implode(' ', array_map(fn ($r) => "<{$r}>", $references)),
            'from_address' => (string) config('mail.from.address'),
            'from_name' => (string) config('mail.from.name'),
            'to_addresses' => array_column($to, 'email'),
            'cc_addresses' => array_column($cc, 'email'),
            'subject' => Str::limit($subject, 250, ''),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'snippet' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($bodyText ?: $bodyHtml)) ?? ''), 280, ''),
            'attachment_count' => $attachmentCount,
            'occurred_at' => now(),
            'created_by_id' => Auth::id(),
        ]);

        if ($documentIds !== []) {
            $message->documents()->syncWithoutDetaching(
                array_fill_keys(array_unique($documentIds), ['kind' => 'attachment'])
            );
        }

        // ── 메일 설정이 없다: 사람 메일앱으로 넘긴다.
        if (! MailReady::ok()) {
            $message->update(['status' => MailMessage::SKIPPED, 'error' => MailReady::why()]);
            $this->touchThread($thread, $message, sent: false);

            return [
                'success' => true,
                'sent' => 0,
                'channel' => 'mailto',
                'messageId' => $message->id,
                'refCode' => $thread->ref_code,
                'mailto' => MailReady::mailto(
                    array_column($to, 'email'), $subject, $bodyText, array_column($cc, 'email'),
                ),
                'message' => MailReady::why().' 대신 메일앱을 엽니다 — 내용이 채워지면 확인하고 보내 주세요.',
            ];
        }

        // ── 실제 발송.
        $ccEmails = array_column($cc, 'email');
        $sent = 0;
        $failed = [];
        $firstError = null;

        foreach ($to as $r) {
            try {
                $mailable = $build($messageId, $references);
                $pending = Mail::to($r['email'], $r['name'] ?? null);
                if ($ccEmails !== []) {
                    $pending->cc($ccEmails);
                }
                $pending->send($mailable);
                $sent++;
                // 참조는 첫 통에만. 안 그러면 참조자가 수신자 수만큼 같은 메일을 받는다.
                $ccEmails = [];
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $r['email'];
                $firstError ??= $this->mask($e->getMessage());
            }
        }

        $message->update([
            'status' => $sent > 0 ? MailMessage::SENT : MailMessage::FAILED,
            'error' => $failed === [] ? null : '실패 '.implode(', ', $failed).' — '.$firstError,
        ]);
        $this->touchThread($thread, $message, sent: $sent > 0);

        return [
            'success' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'channel' => 'mail',
            'messageId' => $message->id,
            'refCode' => $thread->ref_code,
            'error' => $sent > 0 ? null : ('발송 실패: '.implode(', ', $failed).' — '.$firstError),
            'message' => $sent > 0
                ? sprintf('%d명에게 발송했습니다 (%s).', $sent, $thread->ref_code)
                : '발송에 실패했습니다.',
        ];
    }

    /**
     * 이 업무 대상의 실타래를 가져오거나 연다.
     *
     * 같은 대상·같은 상대에게 다시 보내면 <b>같은 실타래에 이어 붙인다.</b> 매번 새 실타래를
     * 만들면 한 사안의 서신이 여러 줄로 흩어져서, 나중에 "이 건으로 몇 번 주고받았나" 를
     * 셀 수 없다.
     */
    public function threadFor(
        ?Model $related,
        string $subject,
        ?string $counterpartyEmail = null,
        ?string $counterpartyName = null,
        ?int $siteId = null,
        ?int $companyId = null,
        ?int $projectId = null,
    ): MailThread {
        $existing = null;

        if ($related) {
            $existing = MailThread::query()
                ->where('related_type', $related::class)
                ->where('related_id', $related->getKey())
                ->when($counterpartyEmail, fn ($q) => $q->where('counterparty_email', Str::lower($counterpartyEmail)))
                ->whereNull('revoked_at')
                ->where('status', '<>', 'closed')
                ->latest('id')->first();
        }

        if ($existing) {
            return $existing;
        }

        return MailThread::open([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'project_id' => $projectId,
            'related_type' => $related ? $related::class : null,
            'related_id' => $related?->getKey(),
            'subject' => Str::limit($subject, 250, ''),
            'counterparty_email' => $counterpartyEmail ? Str::lower($counterpartyEmail) : null,
            'counterparty_name' => $counterpartyName,
            'created_by_id' => Auth::id(),
        ]);
    }

    /**
     * 우리가 발급하는 RFC Message-ID.
     *
     * 회신의 In-Reply-To 헤더가 이 값을 그대로 되돌려 준다 — 2단계에서 회신을 실타래에
     * 이어 붙이는 열쇠다. 도메인은 발신 주소의 도메인을 쓴다(없으면 앱 주소).
     */
    private function newMessageId(): string
    {
        $from = (string) config('mail.from.address', '');
        $domain = str_contains($from, '@')
            ? substr($from, strpos($from, '@') + 1)
            : (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'erp.local');

        return sprintf('%s.%s@%s', now()->format('YmdHis'), Str::lower(Str::random(12)), $domain);
    }

    /** 실타래의 최근 상태를 갱신한다 — 목록이 이 값으로 정렬된다. */
    private function touchThread(MailThread $thread, MailMessage $message, bool $sent): void
    {
        $thread->forceFill([
            'last_message_at' => $message->occurred_at,
            'message_count' => $thread->messages()->count(),
            'first_sent_at' => $thread->first_sent_at ?: ($sent ? $message->occurred_at : null),
            'status' => $sent ? 'awaiting_reply' : $thread->status,
        ])->save();
    }

    /**
     * @param  array<int, array{email?: string|null, name?: string|null}>  $rows
     * @return array<int, array{email: string, name: string|null}>
     */
    private function clean(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $email = Str::lower(trim((string) ($r['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = ['email' => $email, 'name' => $r['name'] ?? null];
        }

        return $out;
    }

    /** 예외 메시지에 자격증명이 섞여 나올 수 있다 — 원장에 그대로 남기면 그게 곧 유출이다. */
    private function mask(string $text): string
    {
        $secret = (string) config('mail.mailers.smtp.password');
        if (strlen(trim($secret)) >= 6) {
            $text = str_replace($secret, '***', $text);
        }

        return Str::limit((string) preg_replace('#(\w+://)[^:/@\s]+:[^@\s]+@#', '$1***:***@', $text), 400);
    }
}
