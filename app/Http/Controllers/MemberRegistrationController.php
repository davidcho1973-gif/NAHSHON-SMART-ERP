<?php

namespace App\Http\Controllers;

use App\Models\MemberDocument;
use App\Models\MemberRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberRegistrationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $registration = MemberRegistration::query()
            ->with(['company', 'site', 'team', 'documents'])
            ->where('invite_token', $token)
            ->firstOrFail();

        return $this->intakeView($registration, false, $this->resolveLanguage(
            $request->query('lang', $registration->preferred_language),
        ));
    }

    public function store(Request $request, string $token): View
    {
        $registration = MemberRegistration::query()
            ->with(['company', 'site', 'team', 'documents'])
            ->where('invite_token', $token)
            ->firstOrFail();

        $hasIdentityDocument = $registration->documents()
            ->where('document_type', 'id')
            ->exists();

        $data = $request->validate([
            'preferred_language' => ['required', Rule::in(array_keys(MemberRegistration::languageOptions()))],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date'],
            'nationality' => ['required', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(array_keys(MemberRegistration::roleOptions()))],
            'trade' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:80'],
            'identity_document' => [
                Rule::requiredIf(! $hasIdentityDocument),
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            'certifications' => ['nullable', 'array', 'max:10'],
            'certifications.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'consent' => ['accepted'],
        ]);

        $language = $this->resolveLanguage($data['preferred_language']);
        $fullName = trim($data['first_name'] . ' ' . $data['last_name']);
        $certificationFiles = $request->file('certifications', []);

        $payload = array_merge($registration->payload ?? [], [
            'self_registration' => [
                'submitted_ip' => $request->ip(),
                'submitted_user_agent' => $request->userAgent(),
                'language' => $language,
                'certification_upload_count' => is_array($certificationFiles) ? count($certificationFiles) : 0,
                'consent_accepted_at' => now()->toISOString(),
            ],
        ]);

        $registration->fill([
            'preferred_language' => $language,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $fullName,
            'preferred_name' => $data['preferred_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'nationality' => $data['nationality'],
            'address' => $data['address'] ?? null,
            'role' => $data['role'],
            'trade' => $data['trade'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'identity_status' => 'pending',
            'document_status' => 'pending',
            'onboarding_status' => 'submitted',
            'submitted_at' => $registration->submitted_at ?: now(),
            'privacy_consent_at' => now(),
            'privacy_consent_language' => $language,
            'payload' => $payload,
        ]);
        $registration->save();

        if ($request->file('identity_document') instanceof UploadedFile) {
            $this->storeUploadedDocument(
                $registration,
                $request->file('identity_document'),
                'id',
                'Government ID / Driver License / Passport',
            );
        }

        foreach ($certificationFiles as $file) {
            if ($file instanceof UploadedFile) {
                $this->storeUploadedDocument($registration, $file, 'certification', 'Certification');
            }
        }

        return $this->intakeView($registration->fresh(['company', 'site', 'team', 'documents']), true, $language);
    }

    private function intakeView(MemberRegistration $registration, bool $submitted, string $language): View
    {
        return view('member-registration.show', [
            'registration' => $registration,
            'submitted' => $submitted,
            'language' => $language,
            'languages' => MemberRegistration::languageOptions(),
            'roleOptions' => MemberRegistration::roleOptions(),
        ]);
    }

    private function resolveLanguage(mixed $language): string
    {
        $language = is_string($language) ? $language : 'es';

        return array_key_exists($language, MemberRegistration::languageOptions()) ? $language : 'es';
    }

    private function storeUploadedDocument(
        MemberRegistration $registration,
        UploadedFile $file,
        string $type,
        string $title,
    ): MemberDocument {
        $path = $file->store("member-documents/{$registration->id}", 'public');
        $url = Storage::disk('public')->url($path);
        $data = [
            'title' => $title,
            'status' => 'pending',
            'file_path' => $url,
            'extracted_data' => [
                'storage_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ],
        ];

        if ($type === 'id') {
            return MemberDocument::query()->updateOrCreate(
                [
                    'member_registration_id' => $registration->id,
                    'document_type' => 'id',
                ],
                $data,
            );
        }

        return MemberDocument::query()->create([
            'member_registration_id' => $registration->id,
            'document_type' => $type,
            ...$data,
        ]);
    }
}
