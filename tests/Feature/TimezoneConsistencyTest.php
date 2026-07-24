<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 타임존 7시간 오차 버그 회귀 방지.
 *
 * app.timezone 이 UTC 가 아닐 때(예: Phoenix), timestamptz 컬럼은 Laravel 이 보낸 앱-타임존
 * 벽시계를 Postgres(UTC 세션)가 UTC 로 저장해 실제 instant 가 어긋났다. 전 컬럼을 naive
 * timestamp 로 통일해 왕복이 정확해야 한다.
 */
class TimezoneConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_former_timestamptz_columns_are_now_naive(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgsql 전용 이슈.');
        }

        $samples = [
            ['attendance_logs', 'event_at'],
            ['attendance_logs', 'approved_at'],
            ['payroll_timesheets', 'check_in_at'],
            ['payroll_timesheets', 'check_out_at'],
            ['safety_work_signatures', 'signed_at'],
            ['communication_messages', 'sent_at'],
            ['photo_uploads', 'captured_at'],
        ];

        foreach ($samples as [$table, $col]) {
            $type = DB::selectOne(
                'select data_type from information_schema.columns where table_schema=current_schema() and table_name=? and column_name=?',
                [$table, $col],
            );
            $this->assertNotNull($type, "$table.$col 이 존재해야 한다.");
            $this->assertSame('timestamp without time zone', $type->data_type, "$table.$col 은 naive timestamp 여야 한다.");
        }
    }

    public function test_attendance_event_time_roundtrips_to_the_correct_instant(): void
    {
        $company = Company::create(['code' => 'C1', 'name' => 'Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'S1', 'name' => 'Site', 'status' => 'active']);
        $emp = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => 'A', 'last_name' => 'K', 'email' => 'a@x.com', 'employment_status' => 'active',
        ]);

        $when = Carbon::now();
        $log = AttendanceLog::create([
            'employee_id' => $emp->id, 'company_id' => $company->id, 'site_id' => $site->id,
            'event_type' => 'clock_in', 'event_at' => $when, 'source' => 'gate_qr', 'status' => 'approved',
        ]);

        // DB 왕복 후 instant 가 그대로여야 한다(7시간 어긋나면 안 됨).
        $reread = AttendanceLog::findOrFail($log->id);
        $this->assertLessThanOrEqual(2, abs($reread->event_at->diffInSeconds($when)), '저장된 시각이 실제 시각과 어긋나면 안 된다.');
    }
}
