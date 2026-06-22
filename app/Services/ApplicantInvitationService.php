<?php

namespace App\Services;

use App\Models\MemberRegistration;
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
                ->subject('NAHSHON MEP application link');
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
            'subject' => 'NAHSHON MEP application link',
            'body' => $this->emailBody($registration),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws RuntimeException
     */
    public function ensureRealMailerConfigured(): void
    {
        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array'], true)) {
            throw new RuntimeException(
                '실제 메일 발송 설정이 없습니다. Laravel Cloud 환경변수에 MAIL_MAILER=smtp 와 SMTP 계정 정보를 먼저 설정해야 합니다.',
            );
        }

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host', '');

            if ($host === '' || in_array(Str::lower($host), ['127.0.0.1', 'localhost'], true)) {
                throw new RuntimeException(
                    'SMTP 서버가 설정되지 않았습니다. MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD를 Laravel Cloud 환경변수에 넣어 주세요.',
                );
            }
        }

        $fromAddress = (string) config('mail.from.address', '');

        if ($fromAddress === '' || Str::endsWith(Str::lower($fromAddress), '@example.com')) {
            throw new RuntimeException(
                '발신 이메일 주소가 설정되지 않았습니다. MAIL_FROM_ADDRESS를 실제 발신 주소로 설정해 주세요.',
            );
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

    private function emailBody(MemberRegistration $registration): string
    {
        $url = $registration->intakeUrl();

        return <<<TEXT
NAHSHON MEP 입사지원서 작성 링크입니다.

아래 링크를 열고 입사지원서를 작성해 주세요.
{$url}

Please open the link above and complete your job application.

Abra el enlace de arriba y complete su solicitud de empleo.

Thank you,
NAHSHON MEP
TEXT;
    }
}
