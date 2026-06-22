<?php

namespace App\Services;

use App\Models\MemberRegistration;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        $recipientEmail = $recipientEmail
            ? Str::lower(trim($recipientEmail))
            : Str::lower((string) $registration->email);

        if (blank($recipientEmail)) {
            throw new \InvalidArgumentException('Recipient email is required.');
        }

        Mail::raw($this->emailBody($registration), function ($message) use ($registration, $recipientEmail): void {
            $message
                ->to($recipientEmail, $registration->full_name)
                ->subject('NAHSHON MEP application link');
        });
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
