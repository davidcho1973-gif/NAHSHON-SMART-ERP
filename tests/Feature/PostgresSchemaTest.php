<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_postgresql_schema_foundation_exists(): void
    {
        $this->assertSame('pgsql', config('database.default'));

        foreach ([
            'companies',
            'sites',
            'site_contractors',
            'teams',
            'employees',
            'projects',
            'attendance_qr_codes',
            'employee_badge_qr_tokens',
            'daily_work_assignments',
            'photo_uploads',
            'ocr_results',
            'attendance_logs',
            'approval_histories',
            'payroll_timesheets',
            'ai_jobs',
            'ai_outputs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        foreach ([
            'first_name',
            'last_name',
            'badge_printed_number',
            'badge_company_name',
            'badge_issued_on',
            'badge_photo_path',
            'badge_analysis_model',
            'badge_analyzed_at',
            'badge_analysis_payload',
            'start_date',
            'attendance_app_role',
            'attendance_app_scope',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('employees', $column), "Missing employees column: {$column}");
        }

        foreach ([
            'daily_work_assignment_id',
            'attendance_qr_code_id',
            'employee_badge_qr_token_id',
            'site_contractor_id',
            'employer_company_id',
            'recorded_by_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('attendance_logs', $column), "Missing attendance_logs column: {$column}");
        }

        foreach ([
            'employer_company_id',
            'site_contractor_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('payroll_timesheets', $column), "Missing payroll_timesheets column: {$column}");
        }

        foreach ([
            'preferred_language',
            'applicant_code',
            'first_name',
            'last_name',
            'interview_status',
            'safety_training_status',
            'badge_registration_status',
            'nfc_raw_uid',
            'badge_photo_path',
            'badge_printed_number',
            'badge_company_name',
            'badge_first_name',
            'badge_last_name',
            'badge_role',
            'badge_issued_on',
            'privacy_consent_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('member_registrations', $column), "Missing member_registrations column: {$column}");
        }

        foreach ([
            'site_id',
            'company_id',
            'company_name',
            'contract_role',
            'contract_number',
            'scope_of_work',
            'primary_contact_name',
            'primary_contact_phone',
            'starts_on',
            'ends_on',
            'status',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('site_contractors', $column), "Missing site_contractors column: {$column}");
        }

        foreach ([
            'company_id',
            'contract_company_name',
            'trade_type',
            'responsible_manager_name',
            'supervisor_name',
            'supervisor_phone',
            'planned_headcount',
            'notes',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('teams', $column), "Missing teams column: {$column}");
        }

        foreach ([
            'company_id',
            'site_id',
            'team_id',
            'employee_id',
            'project_code',
            'construction_type',
            'end_client_company_id',
            'project_stage',
            'vendor_tier',
            'upper_contractor_company_id',
            'epc_company_id',
            'contract_amount',
            'currency',
            'prevailing_wage_required',
            'certified_payroll_required',
            'milestone_plan',
            'workforce_plan',
            'korean_dispatch_plan',
            'equipment_plan',
            'subcontractor_plan',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('projects', $column), "Missing projects column: {$column}");
        }
    }
}
