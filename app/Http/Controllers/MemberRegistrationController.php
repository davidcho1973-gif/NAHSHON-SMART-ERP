<?php

namespace App\Http\Controllers;

use App\Models\MemberDocument;
use App\Models\MemberRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberRegistrationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $registration = $this->registrationForToken($token);

        return $this->intakeView($registration, false, $this->resolveLanguage(
            $request->query('lang', $registration->preferred_language),
        ));
    }

    public function store(Request $request, string $token): View
    {
        $registration = $this->registrationForToken($token);

        $hasIdentityDocument = $registration->documents()
            ->where('document_type', 'id')
            ->exists();

        $data = $request->validate([
            'preferred_language' => ['required', Rule::in(array_keys(MemberRegistration::languageOptions()))],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_phone' => ['required', 'string', 'max:80'],
            'available_languages' => ['required', 'array', 'min:1'],
            'available_languages.*' => [Rule::in(array_keys(MemberRegistration::availableLanguageOptions()))],
            'available_language_other' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_keys(MemberRegistration::roleOptions()))],
            'role_other' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'desired_site' => ['nullable', 'string', 'max:255'],
            'previous_site_experience' => ['nullable', 'string', 'max:2000'],
            'hoffman_experience' => ['required', Rule::in(['yes', 'no'])],
            'identity_document_type' => ['required', Rule::in(['driver_license', 'passport', 'government_id'])],
            'identity_front' => [
                Rule::requiredIf(! $hasIdentityDocument),
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],
            'identity_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'certifications' => ['nullable', 'array', 'max:10'],
            'certifications.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'work_history' => ['nullable', 'array', 'max:2'],
            'work_history.*.company' => ['nullable', 'string', 'max:255'],
            'work_history.*.role' => ['nullable', 'string', 'max:120'],
            'work_history.*.period' => ['nullable', 'string', 'max:120'],
            'work_history.*.duties' => ['nullable', 'string', 'max:1000'],
            'work_history.*.reason' => ['nullable', 'string', 'max:500'],
            'privacy_consent' => ['accepted'],
            'applicant_signature' => ['required', 'string', 'max:255'],
            'signed_on' => ['required', 'date'],
        ]);

        $language = $this->resolveLanguage($data['preferred_language']);
        $fullName = trim($data['first_name'] . ' ' . $data['last_name']);
        $certificationFiles = $request->file('certifications', []);
        $role = $data['role'] === 'Other' && filled($data['role_other'] ?? null)
            ? 'Other: ' . $data['role_other']
            : $data['role'];
        $availableLanguages = $data['available_languages'];
        if (in_array('Other', $availableLanguages, true) && filled($data['available_language_other'] ?? null)) {
            $availableLanguages[] = 'Other: ' . $data['available_language_other'];
        }

        $payload = array_merge($registration->payload ?? [], [
            'application' => [
                'submitted_ip' => $request->ip(),
                'submitted_user_agent' => $request->userAgent(),
                'language' => $language,
                'available_languages' => array_values(array_unique($availableLanguages)),
                'desired_site' => $data['desired_site'] ?? null,
                'previous_site_experience' => $data['previous_site_experience'] ?? null,
                'hoffman_experience' => $data['hoffman_experience'],
                'identity_document_type' => $data['identity_document_type'],
                'certification_upload_count' => is_array($certificationFiles) ? count($certificationFiles) : 0,
                'work_history' => array_values(array_filter(
                    $data['work_history'] ?? [],
                    fn (array $history): bool => filled($history['company'] ?? null)
                        || filled($history['role'] ?? null)
                        || filled($history['period'] ?? null)
                        || filled($history['duties'] ?? null)
                        || filled($history['reason'] ?? null),
                )),
                'applicant_signature' => $data['applicant_signature'],
                'signed_on' => $data['signed_on'],
                'consent_accepted_at' => now()->toISOString(),
            ],
        ]);

        $registration->fill([
            'preferred_language' => $language,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'full_name' => $fullName,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'address' => $data['address'] ?? null,
            'role' => $role,
            'trade' => null,
            'start_date' => $data['start_date'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'],
            'emergency_contact_phone' => $data['emergency_contact_phone'],
            'identity_status' => 'pending',
            'document_status' => 'pending',
            'onboarding_status' => 'submitted',
            'submitted_at' => $registration->submitted_at ?: now(),
            'privacy_consent_at' => now(),
            'privacy_consent_language' => $language,
            'payload' => $payload,
        ]);
        $registration->save();
        $registration->ensureApplicantCode();

        if ($request->file('identity_front') instanceof UploadedFile) {
            $this->storeUploadedDocument(
                $registration,
                $request->file('identity_front'),
                'id',
                'Government ID - front',
                ['side' => 'front', 'identity_document_type' => $data['identity_document_type']],
            );
        }

        if ($request->file('identity_back') instanceof UploadedFile) {
            $this->storeUploadedDocument(
                $registration,
                $request->file('identity_back'),
                'id_back',
                'Government ID - back',
                ['side' => 'back', 'identity_document_type' => $data['identity_document_type']],
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
            'availableLanguageOptions' => MemberRegistration::availableLanguageOptions(),
        ]);
    }

    private function resolveLanguage(mixed $language): string
    {
        $language = is_string($language) ? $language : 'es';

        return array_key_exists($language, MemberRegistration::languageOptions()) ? $language : 'es';
    }

    private function registrationForToken(string $token): MemberRegistration
    {
        abort_unless(Str::isUuid($token), 404);

        return MemberRegistration::query()
            ->with(['company', 'site', 'team', 'documents'])
            ->where('invite_token', $token)
            ->firstOrFail();
    }

    private function storeUploadedDocument(
        MemberRegistration $registration,
        UploadedFile $file,
        string $type,
        string $title,
        array $extraData = [],
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
            ] + $extraData,
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
