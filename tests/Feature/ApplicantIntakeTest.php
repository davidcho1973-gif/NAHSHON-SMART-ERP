<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Services\ApplicantInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_invite_token_returns_not_found(): void
    {
        $this->get('/member/register/%7Binvite_token%7D')->assertNotFound();
        $this->get('/member/register/%7Binvite_token%7D/qr')->assertNotFound();
        $this->post('/member/register/%7Binvite_token%7D')->assertNotFound();
    }

    public function test_qr_page_shows_scannable_application_link(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'QR Applicant',
            'member_type' => 'worker',
            'preferred_language' => 'es',
            'onboarding_status' => 'invited',
        ]);

        $this->get(route('member-registration.qr', $registration->invite_token))
            ->assertOk()
            ->assertSee('입사지원서 QR 코드')
            ->assertSee($registration->intakeUrl());
    }

    public function test_applicant_email_can_fall_back_to_mailto_when_real_mailer_is_missing(): void
    {
        config(['mail.default' => 'log']);

        $registration = MemberRegistration::create([
            'full_name' => 'Email Applicant',
            'email' => 'applicant@example.com',
            'member_type' => 'worker',
            'preferred_language' => 'es',
            'onboarding_status' => 'invited',
        ]);

        $service = app(ApplicantInvitationService::class);
        $mailtoUrl = $service->mailtoUrl($registration);

        $this->assertFalse($service->hasRealMailerConfigured());
        $this->assertStringStartsWith('mailto:applicant%40example.com?', $mailtoUrl);
        $this->assertStringContainsString(rawurlencode($registration->intakeUrl()), $mailtoUrl);
    }

    public function test_admin_invitation_creates_only_an_applicant_link(): void
    {
        $service = app(ApplicantInvitationService::class);

        $registration = $service->createInvitation([
            'full_name' => 'Invited Worker',
            'email' => 'worker@example.com',
            'preferred_language' => 'es',
        ], 'admin-link', 123);

        $this->assertSame('invited', $registration->onboarding_status);
        $this->assertSame('Invited Worker', $registration->full_name);
        $this->assertSame('worker@example.com', $registration->email);
        $this->assertNull($registration->applicant_code);
        $this->assertNull($registration->submitted_at);
        $this->assertNull($registration->employee_id);
        $this->assertSame('admin-link', data_get($registration->payload, 'invite.source'));
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_applicant_can_submit_multilingual_intake_with_id_and_certifications(): void
    {
        Storage::fake('public');

        $registration = MemberRegistration::create([
            'full_name' => 'Pending Applicant',
            'member_type' => 'worker',
            'preferred_language' => 'es',
            'onboarding_status' => 'invited',
        ]);

        $response = $this->post(route('member-registration.store', $registration->invite_token), [
            'preferred_language' => 'es',
            'first_name' => 'Sekon',
            'last_name' => 'Kim',
            'nationality' => 'Korea',
            'phone' => '555-0101',
            'email' => 'sekon@example.com',
            'emergency_contact_name' => 'Emergency Contact',
            'emergency_contact_phone' => '555-0202',
            'available_languages' => ['Spanish', 'English'],
            'role' => 'Safety',
            'desired_site' => 'Hoffman Project A',
            'previous_site_experience' => 'Two years of commercial construction.',
            'hoffman_experience' => 'no',
            'identity_document_type' => 'driver_license',
            'identity_front' => UploadedFile::fake()->create('license-front.jpg', 64, 'image/jpeg'),
            'identity_back' => UploadedFile::fake()->create('license-back.jpg', 64, 'image/jpeg'),
            'certifications' => [
                UploadedFile::fake()->create('osha-card.pdf', 64, 'application/pdf'),
            ],
            'work_history' => [
                [
                    'company' => 'Previous Company',
                    'role' => 'Spotter',
                    'period' => '2024-2025',
                    'duties' => 'Site support',
                    'reason' => 'Project completed',
                ],
            ],
            'privacy_consent' => '1',
            'applicant_signature' => 'Sekon Kim',
            'signed_on' => '2026-06-22',
        ]);

        $response->assertOk();

        $registration->refresh();

        $this->assertSame('submitted', $registration->onboarding_status);
        $this->assertSame('es', $registration->preferred_language);
        $this->assertSame('Sekon', $registration->first_name);
        $this->assertSame('Kim', $registration->last_name);
        $this->assertSame('Sekon Kim', $registration->full_name);
        $this->assertSame('Korea', $registration->nationality);
        $this->assertSame('Safety', $registration->role);
        $this->assertNotNull($registration->applicant_code);
        $this->assertNotNull($registration->privacy_consent_at);
        $this->assertSame(['Spanish', 'English'], data_get($registration->payload, 'application.available_languages'));
        $this->assertSame('Korea', data_get($registration->payload, 'application.nationality'));
        $this->assertSame('no', data_get($registration->payload, 'application.hoffman_experience'));
        $this->assertSame(0, Employee::query()->count());

        $this->assertDatabaseHas('member_documents', [
            'member_registration_id' => $registration->id,
            'document_type' => 'id',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('member_documents', [
            'member_registration_id' => $registration->id,
            'document_type' => 'id_back',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('member_documents', [
            'member_registration_id' => $registration->id,
            'document_type' => 'certification',
            'status' => 'pending',
        ]);
    }

    public function test_submitted_application_is_locked_from_public_resubmission(): void
    {
        Storage::fake('public');

        $registration = MemberRegistration::create([
            'full_name' => 'Original Applicant',
            'member_type' => 'worker',
            'preferred_language' => 'en',
            'onboarding_status' => 'submitted',
            'submitted_at' => now(),
            'first_name' => 'Original',
            'last_name' => 'Applicant',
            'email' => 'original@example.com',
        ]);

        $this->get(route('member-registration.show', $registration->invite_token))
            ->assertOk()
            ->assertSee('Application submitted')
            ->assertDontSee('Submit application');

        $this->post(route('member-registration.store', $registration->invite_token), [
            'preferred_language' => 'en',
            'first_name' => 'Changed',
            'last_name' => 'Name',
            'email' => 'changed@example.com',
            'phone' => '555-0101',
            'emergency_contact_name' => 'Emergency Contact',
            'emergency_contact_phone' => '555-0202',
            'available_languages' => ['Spanish'],
            'role' => 'Safety',
            'hoffman_experience' => 'no',
            'identity_document_type' => 'driver_license',
            'identity_front' => UploadedFile::fake()->create('license-front.jpg', 64, 'image/jpeg'),
            'privacy_consent' => '1',
            'applicant_signature' => 'Changed Name',
            'signed_on' => '2026-06-22',
        ])->assertOk();

        $registration->refresh();

        $this->assertSame('Original', $registration->first_name);
        $this->assertSame('Applicant', $registration->last_name);
        $this->assertSame('original@example.com', $registration->email);
        $this->assertSame(0, $registration->documents()->count());
    }
}
