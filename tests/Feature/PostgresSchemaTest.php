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
            'teams',
            'employees',
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
            'badge_company_name',
            'badge_issued_on',
            'badge_photo_path',
            'badge_analysis_model',
            'badge_analyzed_at',
            'badge_analysis_payload',
            'start_date',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('employees', $column), "Missing employees column: {$column}");
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
            'badge_company_name',
            'badge_first_name',
            'badge_last_name',
            'badge_role',
            'badge_issued_on',
            'privacy_consent_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('member_registrations', $column), "Missing member_registrations column: {$column}");
        }
    }
}
