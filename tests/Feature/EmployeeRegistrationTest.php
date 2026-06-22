<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_be_saved_without_manual_employee_id_or_full_name(): void
    {
        $employee = Employee::query()->create([
            'first_name' => 'Sekon',
            'last_name' => 'Kim',
            'email' => '',
            'badge_number' => '',
            'employment_status' => 'active',
        ]);

        $this->assertStringStartsWith('EMP-', $employee->employee_number);
        $this->assertSame('Sekon Kim', $employee->name);
        $this->assertNull($employee->email);
        $this->assertNull($employee->badge_number);
    }

    public function test_multiple_employees_can_have_blank_optional_unique_fields(): void
    {
        $first = Employee::query()->create([
            'name' => 'First Worker',
            'email' => '',
            'badge_number' => '',
        ]);

        $second = Employee::query()->create([
            'name' => 'Second Worker',
            'email' => '',
            'badge_number' => '',
        ]);

        $this->assertNotSame($first->employee_number, $second->employee_number);
        $this->assertNull($first->email);
        $this->assertNull($second->email);
        $this->assertNull($first->badge_number);
        $this->assertNull($second->badge_number);
    }
}
