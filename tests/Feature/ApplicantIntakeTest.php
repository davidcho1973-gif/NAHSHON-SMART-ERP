<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MemberRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantIntakeTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('Safety', $registration->role);
        $this->assertNotNull($registration->applicant_code);
        $this->assertNotNull($registration->privacy_consent_at);
        $this->assertSame(['Spanish', 'English'], data_get($registration->payload, 'application.available_languages'));
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
}
