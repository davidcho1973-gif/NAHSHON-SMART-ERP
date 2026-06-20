<?php

namespace App\Http\Controllers;

use App\Models\MemberRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberRegistrationController extends Controller
{
    public function show(string $token): View
    {
        $registration = MemberRegistration::query()
            ->with(['company', 'site', 'team'])
            ->where('invite_token', $token)
            ->firstOrFail();

        return view('member-registration.show', [
            'registration' => $registration,
            'submitted' => false,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse|View
    {
        $registration = MemberRegistration::query()
            ->with(['company', 'site', 'team'])
            ->where('invite_token', $token)
            ->firstOrFail();

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', 'string', 'max:120'],
            'trade' => ['nullable', 'string', 'max:120'],
            'visa_type' => ['nullable', 'string', 'max:60'],
            'visa_expires_on' => ['nullable', 'date'],
            'safety_training_expires_on' => ['nullable', 'date'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:80'],
            'consent' => ['accepted'],
        ]);

        $payload = array_merge($registration->payload ?? [], [
            'self_registration' => [
                'submitted_ip' => $request->ip(),
                'submitted_user_agent' => $request->userAgent(),
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'consent_accepted_at' => now()->toISOString(),
            ],
        ]);

        unset($data['emergency_contact_name'], $data['emergency_contact_phone'], $data['consent']);

        $registration->fill($data);
        $registration->payload = $payload;
        $registration->submitted_at ??= now();
        $registration->onboarding_status = 'submitted';
        $registration->save();

        return view('member-registration.show', [
            'registration' => $registration->fresh(['company', 'site', 'team']),
            'submitted' => true,
        ]);
    }
}
