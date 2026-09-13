<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_georgia_and_arizona_events_run_at_their_own_local_time(): void
    {
        foreach (['2026-09-12 20:05:00', '2026-12-12 21:05:00'] as $instant) {
            Carbon::setTestNow(Carbon::parse($instant, 'UTC'));
            $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'attendance:auto-clockout') && $e->isDue(app()));
            $this->assertCount(1, $events);
            $this->assertStringContainsString('America/New_York', $events->first()->command);
        }
        Carbon::setTestNow(Carbon::parse('2026-09-12 23:05:00', 'UTC'));
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'attendance:auto-clockout') && $e->isDue(app()));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('America/Phoenix', $events->first()->command);
        Carbon::setTestNow();
    }

    public function test_georgia_clockout_does_not_close_arizona_workers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 20:05:00', 'UTC'));
        $employees = [];
        foreach (['GA' => 'America/New_York', 'AZ' => 'America/Phoenix'] as $code => $tz) {
            $site = Site::create(['code' => $code, 'name' => $code, 'timezone' => $tz, 'status' => 'active']);
            $employee = Employee::create(['site_id' => $site->id, 'first_name' => $code, 'employment_status' => 'active', 'employment_type' => Employee::TYPE_INDIRECT]);
            $employees[$code] = $employee;
            AttendanceLog::create(['site_id' => $site->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-09-12', 'event_type' => 'clock_in', 'event_at' => Carbon::parse('2026-09-12 07:00:00', $tz), 'source' => 'gate_qr', 'status' => 'approved']);
        }
        $this->artisan('attendance:auto-clockout', ['--timezone' => 'America/New_York'])->assertSuccessful();
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employees['GA']->id, 'event_type' => 'clock_out']);
        $this->assertDatabaseMissing('attendance_logs', ['employee_id' => $employees['AZ']->id, 'event_type' => 'clock_out']);
        $this->artisan('attendance:auto-clockout', ['--timezone' => 'America/New_York'])->assertSuccessful();
        $this->assertSame(1, AttendanceLog::where('event_type', 'clock_out')->count());
        Carbon::setTestNow();
    }
}
