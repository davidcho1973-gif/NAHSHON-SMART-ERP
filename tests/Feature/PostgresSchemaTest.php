<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresSchemaTest extends TestCase
{
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
    }
}
