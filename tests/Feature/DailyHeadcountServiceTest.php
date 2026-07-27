<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\DailyHeadcountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 오늘 출역 현황 — 직접고용은 근무시간, 협력사는 인원 관점.
 */
class DailyHeadcountServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function worker(string $email, string $type, Company $company): Employee
    {
        return Employee::create([
            'company_id' => $company->id,
            'site_id' => $this->site->id,
            'name' => $email,
            'email' => $email,
            'role' => 'Electrician',
            'employment_status' => 'active',
            'employment_type' => $type,
        ]);
    }

    private function log(Employee $emp, string $date, string $type, string $time): void
    {
        AttendanceLog::create([
            'employee_id' => $emp->id,
            'company_id' => $emp->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => $date,
            'event_type' => $type,
            'event_at' => Carbon::parse($date.' '.$time, 'America/Phoenix'),
            'source' => 'gate_qr',
            'status' => 'approved',
        ]);
    }

    public function test_splits_hours_for_direct_and_headcount_for_indirect(): void
    {
        $date = '2026-07-28';
        $own = Company::create(['code' => 'NAH', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $sub = Company::create(['code' => 'SUB', 'name' => '한빛전기', 'status' => 'active']);

        $d1 = $this->worker('d1@example.com', Employee::TYPE_DIRECT, $own);
        $d2 = $this->worker('d2@example.com', Employee::TYPE_DIRECT, $own);
        $i1 = $this->worker('i1@example.com', Employee::TYPE_INDIRECT, $sub);
        $i2 = $this->worker('i2@example.com', Employee::TYPE_INDIRECT, $sub);

        $this->log($d1, $date, 'clock_in', '07:00:00');
        $this->log($d1, $date, 'clock_out', '15:00:00'); // 8h
        $this->log($d2, $date, 'clock_in', '08:00:00');
        $this->log($d2, $date, 'clock_out', '14:00:00'); // 6h
        $this->log($i1, $date, 'clock_in', '07:00:00');
        $this->log($i1, $date, 'clock_out', '16:00:00');
        $this->log($i2, $date, 'clock_in', '07:30:00'); // 퇴근 미기록

        $d = app(DailyHeadcountService::class)->today($this->site->id, $date);

        $this->assertSame(2, $d['direct']['count']);
        $this->assertSame(14.0, $d['direct']['workedHours']);
        $this->assertSame(7.0, $d['direct']['avgHours']);
        $this->assertSame(0, $d['direct']['open']);

        $this->assertSame(2, $d['indirect']['count']);
        $this->assertSame(1, $d['indirect']['open']);

        // 협력사가 먼저 나온다 — "오늘 몇 명 왔나" 가 이 화면의 첫 질문이라서.
        $this->assertSame('한빛전기', $d['companies'][0]['company']);
        $this->assertSame(Employee::TYPE_INDIRECT, $d['companies'][0]['type']);
        $this->assertSame(2, $d['companies'][0]['count']);
        $this->assertSame('DASOL PRISM', $d['companies'][1]['company']);

        $this->assertCount(4, $d['workers']);
    }

    public function test_open_worker_is_flagged_and_has_no_out_time(): void
    {
        $date = '2026-07-28';
        $sub = Company::create(['code' => 'SUB', 'name' => '한빛전기', 'status' => 'active']);
        $emp = $this->worker('open@example.com', Employee::TYPE_INDIRECT, $sub);
        $this->log($emp, $date, 'clock_in', '07:30:00');

        $d = app(DailyHeadcountService::class)->today($this->site->id, $date);

        $this->assertTrue($d['workers'][0]['open']);
        $this->assertNull($d['workers'][0]['out']);
        $this->assertSame('07:30', $d['workers'][0]['in']);
    }

    public function test_empty_day_returns_zeroed_shape(): void
    {
        $d = app(DailyHeadcountService::class)->today($this->site->id, '2026-07-28');

        $this->assertSame(0, $d['direct']['count']);
        $this->assertSame(0, $d['indirect']['count']);
        $this->assertSame([], $d['companies']);
        $this->assertSame([], $d['workers']);
    }

    public function test_rejected_logs_are_ignored(): void
    {
        $date = '2026-07-28';
        $sub = Company::create(['code' => 'SUB', 'name' => '한빛전기', 'status' => 'active']);
        $emp = $this->worker('rej@example.com', Employee::TYPE_INDIRECT, $sub);
        AttendanceLog::create([
            'employee_id' => $emp->id,
            'company_id' => $emp->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => $date,
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse($date.' 07:00:00', 'America/Phoenix'),
            'source' => 'gate_qr',
            'status' => 'rejected',
        ]);

        $d = app(DailyHeadcountService::class)->today($this->site->id, $date);

        $this->assertSame(0, $d['indirect']['count']);
    }
}
