<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\AutoClockOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 간접고용(협력사) 퇴근 자동 마감 — 16:00. 직접고용은 임금이 걸려 있어 자동으로 닫지 않는다.
 */
class AutoClockOutServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active']);
    }

    private function worker(string $email, string $type): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => $email,
            'email' => $email,
            'employment_status' => 'active',
            'employment_type' => $type,
        ]);
    }

    private function clockIn(Employee $emp, string $date, string $time = '07:00:00'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $emp->id,
            'company_id' => $emp->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => $date,
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse($date.' '.$time, 'America/Phoenix'),
            'source' => 'gate_qr',
            'status' => 'approved',
        ]);
    }

    public function test_indirect_worker_is_closed_at_16(): void
    {
        $date = '2026-07-28';
        $emp = $this->worker('partner@example.com', Employee::TYPE_INDIRECT);
        $this->clockIn($emp, $date);

        $result = app(AutoClockOutService::class)->run(Carbon::parse($date.' 16:05:00', 'America/Phoenix'));

        $this->assertSame(1, $result['closed']);

        $out = AttendanceLog::where('employee_id', $emp->id)->where('event_type', 'clock_out')->first();
        $this->assertNotNull($out);
        $this->assertSame('auto_clockout', $out->source);
        $this->assertSame('16:00', $out->event_at->setTimezone('America/Phoenix')->format('H:i'));
    }

    public function test_direct_worker_is_left_open_for_review(): void
    {
        $date = '2026-07-28';
        $emp = $this->worker('own@example.com', Employee::TYPE_DIRECT);
        $this->clockIn($emp, $date);

        $result = app(AutoClockOutService::class)->run(Carbon::parse($date.' 16:05:00', 'America/Phoenix'));

        $this->assertSame(0, $result['closed']);
        $this->assertSame(1, $result['pendingDirect']);
        $this->assertSame(0, AttendanceLog::where('employee_id', $emp->id)->where('event_type', 'clock_out')->count());
    }

    public function test_worker_who_already_clocked_out_is_untouched(): void
    {
        $date = '2026-07-28';
        $emp = $this->worker('done@example.com', Employee::TYPE_INDIRECT);
        $this->clockIn($emp, $date);
        AttendanceLog::create([
            'employee_id' => $emp->id,
            'company_id' => $emp->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => $date,
            'event_type' => 'clock_out',
            'event_at' => Carbon::parse($date.' 15:30:00', 'America/Phoenix'),
            'source' => 'gate_qr',
            'status' => 'approved',
        ]);

        $result = app(AutoClockOutService::class)->run(Carbon::parse($date.' 16:05:00', 'America/Phoenix'));

        $this->assertSame(0, $result['closed']);
        $this->assertSame(1, AttendanceLog::where('employee_id', $emp->id)->where('event_type', 'clock_out')->count());
    }

    public function test_command_runs_and_reports(): void
    {
        $date = '2026-07-28';
        $emp = $this->worker('partner2@example.com', Employee::TYPE_INDIRECT);
        $this->clockIn($emp, $date);

        $this->artisan('attendance:auto-clockout', ['date' => $date])->assertExitCode(0);

        $this->assertSame(1, AttendanceLog::where('employee_id', $emp->id)->where('source', 'auto_clockout')->count());
    }
}
