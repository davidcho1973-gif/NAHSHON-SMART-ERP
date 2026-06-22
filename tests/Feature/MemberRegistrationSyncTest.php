<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MemberRegistrationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_registration_syncs_employee_user_and_documents(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'David Cho',
            'email' => 'davidcho@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
            'document_status' => 'verified',
            'visa_type' => 'E-2',
            'visa_expires_on' => now()->addYear()->toDateString(),
            'safety_training_expires_on' => now()->addMonths(6)->toDateString(),
        ]);

        $this->assertDatabaseHas('employees', [
            'name' => 'David Cho',
            'email' => 'davidcho@example.com',
            'employment_status' => 'active',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'davidcho@example.com',
            'access_role' => 'worker',
            'account_status' => 'active',
        ]);

        // id + safety_training + visa = 3건
        $this->assertSame(3, $registration->documents()->count());
        $this->assertTrue($registration->hasAccessAccount());
    }

    public function test_registration_without_email_creates_no_access_account(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'No Email Worker',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
        ]);

        $this->assertDatabaseHas('employees', ['name' => 'No Email Worker']);
        $this->assertSame(0, User::query()->where('name', 'No Email Worker')->count());
        $this->assertFalse($registration->hasAccessAccount());

        // visa 정보 없음 → id + safety_training = 2건
        $this->assertSame(2, $registration->documents()->count());
    }

    public function test_draft_registration_does_not_sync(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'Draft Person',
            'email' => 'draft@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'draft',
        ]);

        $this->assertSame(0, Employee::query()->count());
        $this->assertSame(0, $registration->documents()->count());
    }

    public function test_approve_syncs_downstream(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'Approve Me',
            'email' => 'approve@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'draft',
            'safety_training_status' => 'completed',
            'nfc_raw_uid' => '90227842853E04',
            'badge_photo_path' => 'member-badges/approve.jpg',
        ]);

        $this->assertSame(0, Employee::query()->count());

        $employee = $registration->approve();

        $this->assertSame('Approve Me', $employee->name);
        $this->assertSame('N-842853E04', $employee->badge_number);
        $this->assertDatabaseHas('users', ['email' => 'approve@example.com']);
        $this->assertSame('active', $registration->fresh()->onboarding_status);
    }

    public function test_nfc_uid_is_normalized_to_n_prefix_and_last_nine_characters(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'Badge Worker',
            'member_type' => 'worker',
            'nfc_raw_uid' => '90227842853E04',
        ]);

        $this->assertSame('N-842853E04', $registration->fresh()->badge_number);
        $this->assertSame('N-842853E04', MemberRegistration::normalizeNfcUid('90227842853E04'));
    }

    public function test_activation_requires_safety_training_badge_photo_and_nfc_id(): void
    {
        $registration = MemberRegistration::create([
            'full_name' => 'Not Ready',
            'member_type' => 'worker',
        ]);

        $this->expectException(ValidationException::class);

        $registration->activateAsEmployee();
    }

    public function test_activation_copies_hoffman_badge_fields_to_employee(): void
    {
        $registration = MemberRegistration::create([
            'first_name' => 'Sekon',
            'last_name' => 'Kim',
            'full_name' => 'Sekon Kim',
            'email' => 'sekon@example.com',
            'member_type' => 'worker',
            'safety_training_status' => 'completed',
            'nfc_raw_uid' => '90227842853E04',
            'badge_photo_path' => 'member-badges/sekon.jpg',
            'badge_company_name' => 'AUTORICA LLC',
            'badge_first_name' => 'SEKON',
            'badge_last_name' => 'KIM',
            'badge_role' => 'ENGINEER',
            'badge_issued_on' => '2026-03-29',
            'badge_analysis_model' => 'gemini-3.5-flash',
            'badge_analysis_payload' => ['confidence' => 93],
        ]);

        $employee = $registration->activateAsEmployee();

        $this->assertSame('N-842853E04', $employee->badge_number);
        $this->assertSame('SEKON', $employee->first_name);
        $this->assertSame('KIM', $employee->last_name);
        $this->assertSame('AUTORICA LLC', $employee->badge_company_name);
        $this->assertSame('ENGINEER', $employee->role);
        $this->assertSame('2026-03-29', $employee->start_date?->toDateString());
        $this->assertSame('member-badges/sekon.jpg', $employee->badge_photo_path);
    }

    public function test_active_registration_updates_linked_employee_without_duplicates(): void
    {
        $registration = MemberRegistration::create([
            'employee_number' => 'EMP-100',
            'full_name' => 'Original Name',
            'email' => 'original@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
        ])->fresh();

        $employeeId = $registration->employee_id;
        $this->assertNotNull($employeeId);
        $this->assertSame(1, Employee::query()->count());
        $this->assertSame(1, User::query()->where('employee_id', $employeeId)->count());

        $registration->update([
            'employee_number' => 'EMP-101',
            'full_name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => 'Foreman',
        ]);

        $this->assertSame(1, Employee::query()->count());
        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'employee_number' => 'EMP-101',
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => 'Foreman',
        ]);
        $this->assertSame(1, User::query()->where('employee_id', $employeeId)->count());
        $this->assertDatabaseHas('users', [
            'employee_id' => $employeeId,
            'email' => 'updated@example.com',
            'name' => 'Updated Name',
        ]);
    }

    public function test_sync_downstream_backfills_existing_active_registration(): void
    {
        $registration = MemberRegistration::withoutEvents(fn () => MemberRegistration::create([
            'registration_number' => 'MR-BACKFILL-001',
            'employee_number' => 'EMP-BACKFILL-001',
            'full_name' => 'Backfill Person',
            'email' => 'backfill@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
            'invite_token' => '00000000-0000-0000-0000-000000000001',
        ]));

        $this->assertNull($registration->employee_id);
        $this->assertSame(0, Employee::query()->count());

        $employee = $registration->syncDownstream();

        $this->assertSame('Backfill Person', $employee->name);
        $this->assertSame($employee->id, $registration->fresh()->employee_id);
        $this->assertDatabaseHas('users', [
            'employee_id' => $employee->id,
            'email' => 'backfill@example.com',
        ]);
    }

    public function test_sync_downstream_repairs_registration_linked_to_another_employee(): void
    {
        $firstRegistration = MemberRegistration::create([
            'employee_number' => 'EMP-DAVID-001',
            'full_name' => 'David Cho',
            'email' => 'davidcho@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
        ])->fresh();

        $secondRegistration = MemberRegistration::withoutEvents(fn () => MemberRegistration::create([
            'registration_number' => 'MR-HYUNSUK-001',
            'employee_number' => 'EMP-HYUNSUK-001',
            'full_name' => 'HYUNSUK',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
            'invite_token' => '00000000-0000-0000-0000-000000000002',
        ]));

        $secondRegistration->forceFill(['employee_id' => $firstRegistration->employee_id])->saveQuietly();

        $employee = $secondRegistration->syncDownstream();

        $this->assertNotSame($firstRegistration->employee_id, $employee->id);
        $this->assertSame($employee->id, $secondRegistration->fresh()->employee_id);
        $this->assertDatabaseHas('employees', [
            'id' => $firstRegistration->employee_id,
            'employee_number' => 'EMP-DAVID-001',
            'name' => 'David Cho',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_number' => 'EMP-HYUNSUK-001',
            'name' => 'HYUNSUK',
        ]);
        $this->assertSame(2, Employee::query()->count());
    }

    public function test_purge_demo_command_removes_demo_records(): void
    {
        $registration = MemberRegistration::create([
            'registration_number' => 'MR-DEMO-999',
            'full_name' => 'Demo Person',
            'email' => 'demo@example.com',
            'member_type' => 'worker',
            'onboarding_status' => 'active',
        ]);

        $this->assertDatabaseHas('employees', ['name' => 'Demo Person']);
        $this->assertDatabaseHas('users', ['email' => 'demo@example.com']);

        $this->artisan('smart:purge-demo', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('member_registrations', ['registration_number' => 'MR-DEMO-999']);
        $this->assertDatabaseMissing('employees', ['name' => 'Demo Person']);
        $this->assertDatabaseMissing('users', ['email' => 'demo@example.com']);
        $this->assertSame(0, $registration->documents()->count());
    }
}
