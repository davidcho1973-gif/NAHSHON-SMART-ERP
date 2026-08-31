<?php

namespace App\Services;

use App\Models\MemberRegistration;
use App\Support\MailReady;
use App\Support\Org;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class ApplicantInvitationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createInvitation(array $data, string $source = 'admin', ?int $createdById = null): MemberRegistration
    {
        $email = filled($data['email'] ?? null)
            ? Str::lower(trim((string) $data['email']))
            : null;
        $fullName = trim((string) ($data['full_name'] ?? ''));

        if ($fullName === '') {
            $fullName = $email
                ? Str::headline((string) Str::of($email)->before('@')->replace(['.', '_', '-'], ' '))
                : 'QR Applicant';
        }

        return MemberRegistration::query()->create([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'preferred_language' => filled($data['preferred_language'] ?? null) ? (string) $data['preferred_language'] : 'es',
            'member_type' => 'worker',
            'company_id' => $data['company_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'identity_status' => 'pending',
            'document_status' => 'missing',
            'onboarding_status' => 'invited',
            'payload' => [
                'invite' => [
                    'source' => $source,
                    'created_by_id' => $createdById,
                    'created_at' => now()->toISOString(),
                ],
            ],
        ]);
    }

    public function sendEmail(MemberRegistration $registration, ?string $recipientEmail = null): void
    {
        $this->ensureRealMailerConfigured();

        $recipientEmail = $this->normalizeRecipientEmail($registration, $recipientEmail);

        Mail::raw($this->emailBody($registration), function ($message) use ($registration, $recipientEmail): void {
            $message
                ->to($recipientEmail, $registration->full_name)
                ->subject($this->subject());
        });
    }

    public function hasRealMailerConfigured(): bool
    {
        try {
            $this->ensureRealMailerConfigured();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    public function mailtoUrl(MemberRegistration $registration, ?string $recipientEmail = null): string
    {
        $recipientEmail = $this->normalizeRecipientEmail($registration, $recipientEmail);

        return 'mailto:' . rawurlencode($recipientEmail) . '?' . http_build_query([
            'subject' => $this->subject(),
            'body' => $this->emailBody($registration),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 메일이 실제로 나갈 수 있는 상태인가 — 판정은 {@see MailReady} 한 곳에서만 한다.
     *
     * 예전에는 이 메서드가 같은 규칙을 <b>자기만의 사본</b>으로 갖고 있었다(log/array 거부,
     * smtp 호스트 확인, 발신 주소 확인). 사본이 위험한 이유는 원본이 자라도 사본은 그대로라서다 —
     * 실제로 2026-08 에 Microsoft 365(Graph) 발송이 들어왔을 때, 이 사본만 `graph` 를 몰라서
     * <b>Graph 설정이 반쯤 비어 있어도 «설정 완료» 로 판정</b>하고 발송 순간 인증 실패로 죽는
     * 상태가 됐다. MailReady 는 그 경우 네 값(tenant/client/secret/sender)을 다 확인한다.
     *
     * 그래서 규칙을 지우고 정본에 위임한다. 던지는 동작은 그대로 둔다 — 부르는 쪽이 예외를
     * 기대하고 있고, 그쪽 계약까지 바꾸면 이 수술의 범위가 넘친다.
     *
     * @throws RuntimeException
     */
    public function ensureRealMailerConfigured(): void
    {
        if (! MailReady::ok()) {
            throw new RuntimeException(MailReady::why());
        }
    }

    private function normalizeRecipientEmail(MemberRegistration $registration, ?string $recipientEmail = null): string
    {
        $recipientEmail = $recipientEmail
            ? Str::lower(trim($recipientEmail))
            : Str::lower((string) $registration->email);

        if (blank($recipientEmail)) {
            throw new \InvalidArgumentException('Recipient email is required.');
        }

        return $recipientEmail;
    }

    private function subject(): string
    {
        return Org::name().' application link';
    }

    private function emailBody(MemberRegistration $registration): string
    {
        $url = $registration->intakeUrl();
        $org = Org::name();

        return <<<TEXT
{$org} 입사지원서 작성 링크입니다.

아래 링크를 열고 입사지원서를 작성해 주세요.
{$url}

Please open the link above and complete your job application.

Abra el enlace de arriba y complete su solicitud de empleo.

Thank you,
{$org}
TEXT;
    }
}
