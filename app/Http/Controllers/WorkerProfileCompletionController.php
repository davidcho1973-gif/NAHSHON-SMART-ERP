<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Support\WorkerLang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerProfileCompletionController extends Controller
{
    public function show(Request $request, Employee $employee, MemberRegistration $registration): View
    {
        $this->assertPair($employee, $registration);

        return $this->view($employee, $registration, false);
    }

    public function store(Request $request, Employee $employee, MemberRegistration $registration): View
    {
        $this->assertPair($employee, $registration);

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee->id)],
            'address' => ['required', 'string', 'max:255'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_phone' => ['required', 'string', 'max:80'],
        ]);

        $registration->forceFill([
            'email' => $data['email'] ?: $registration->email,
            'address' => $data['address'],
            'emergency_contact_name' => $data['emergency_contact_name'],
            'emergency_contact_phone' => $data['emergency_contact_phone'],
            'payload' => array_merge($registration->payload ?? [], [
                'profile_completed_at' => now()->toISOString(),
            ]),
        ])->save();

        $employee->forceFill([
            'email' => $data['email'] ?: $employee->email,
            'payload' => array_merge($employee->payload ?? [], [
                'profile_completed_at' => now()->toISOString(),
            ]),
        ])->save();

        return $this->view($employee->fresh(), $registration->fresh(), true);
    }

    private function assertPair(Employee $employee, MemberRegistration $registration): void
    {
        abort_unless((int) $registration->employee_id === (int) $employee->id, 404);
    }

    private function view(Employee $employee, MemberRegistration $registration, bool $saved): View
    {
        return view('worker-profile.form', [
            'employee' => $employee,
            'registration' => $registration,
            'saved' => $saved,
            'lang' => WorkerLang::resolve($employee->preferred_language),
            'w9Url' => URL::temporarySignedRoute('w9.show', now()->addDays(14), ['employee' => $employee->id]),
        ]);
    }
}
