<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyCrewReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Team $team;

    private AttendanceQrCode $qrCode;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-25 17:00:00', 'America/Phoenix'));

        $this->company = Company::query()->create([
            'name' => 'External Electrical LLC',
            'code' => 'EXT-ELEC',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Phoenix Test Site',
            'code' => 'PHX-TEST',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $this->team = Team::query()->create([
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'code' => 'ELEC-A',
            'name' => 'Electrical Crew A',
            'status' => 'active',
        ]);
        $this->manager = User::factory()->create([
            'access_role' => 'safety_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $this->site->id,
            'account_status' => 'active',
        ]);
        $this->qrCode = AttendanceQrCode::forTeam($this->team, $this->manager->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_closes_external_headcount_without_creating_employees_or_payroll(): void
    {
        $employeeCount = Employee::query()->count();
        $timesheetCount = \DB::table('payroll_timesheets')->count();

        $response = $this->actingAs($this->manager)
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 12,
                'manual_adjustment' => 0,
                'work_description' => 'Electrical conduit installation',
                'notes' => 'All outside workers attended the toolbox talk.',
            ]);

        $response->assertRedirect(route('attendance-app.crew', ['token' => $this->qrCode->token]));
        $response->assertSessionHas('daily_crew_result.final_headcount', 12);

        $this->assertDatabaseHas('daily_crew_reports', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'work_date' => '2026-07-25',
            'scanned_headcount' => 0,
            'external_headcount' => 12,
            'manual_adjustment' => 0,
            'final_headcount' => 12,
            'status' => 'closed',
            'reported_by_id' => $this->manager->id,
            'closed_by_id' => $this->manager->id,
        ]);
        $this->assertSame($employeeCount, Employee::query()->count());
        $this->assertSame($timesheetCount, \DB::table('payroll_timesheets')->count());
    }

    public function test_daily_close_combines_distinct_registered_workers_with_external_count(): void
    {
        $first = $this->employee('EMP-001', 'First Worker');
        $second = $this->employee('EMP-002', 'Second Worker');

        $this->clockIn($first, '08:00:00');
        $this->clockIn($first, '08:03:00');
        $this->clockIn($second, '08:05:00');

        $response = $this->actingAs($this->manager)
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 8,
                'manual_adjustment' => -1,
                'adjustment_reason' => 'One duplicate visitor was included in the supervisor count.',
                'work_description' => 'Cable tray installation',
            ]);

        $response->assertSessionHas('daily_crew_result.final_headcount', 9);

        $this->assertDatabaseHas('daily_crew_reports', [
            'team_id' => $this->team->id,
            'work_date' => '2026-07-25',
            'scanned_headcount' => 2,
            'external_headcount' => 8,
            'manual_adjustment' => -1,
            'final_headcount' => 9,
        ]);
    }

    public function test_same_team_and_date_updates_one_daily_report(): void
    {
        $this->actingAs($this->manager)
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 10,
                'manual_adjustment' => 0,
            ]);

        $this->actingAs($this->manager)
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 11,
                'manual_adjustment' => 1,
                'adjustment_reason' => 'Late arrival added after the first close.',
            ])
            ->assertSessionHas('daily_crew_result.final_headcount', 12);

        $this->assertDatabaseCount('daily_crew_reports', 1);
        $this->assertDatabaseHas('daily_crew_reports', [
            'external_headcount' => 11,
            'manual_adjustment' => 1,
            'final_headcount' => 12,
        ]);
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $response = $this->actingAs($this->manager)
            ->from(route('attendance-app.crew', ['token' => $this->qrCode->token]))
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 7,
                'manual_adjustment' => -1,
            ]);

        $response->assertRedirect(route('attendance-app.crew', ['token' => $this->qrCode->token]));
        $response->assertSessionHas('attendance_error');
        $this->assertDatabaseCount('daily_crew_reports', 0);
    }

    public function test_crew_screen_shows_separate_daily_close_form(): void
    {
        $this->actingAs($this->manager)
            ->get(route('attendance-app.crew', ['token' => $this->qrCode->token]))
            ->assertOk()
            ->assertSee('일일 인원 마감')
            ->assertSee('미등록 외부 인원')
            ->assertSee('급여에는 반영되지 않습니다.');
    }

    public function test_daily_close_is_recorded_against_the_team_and_site(): void
    {
        // 마감은 그날 그 팀의 기록으로 남아야 한다 — 나중에 "그날 몇 명이었나" 를
        // 되짚는 유일한 근거다.
        $this->actingAs($this->manager)
            ->post(route('attendance-app.crew.daily-close', ['token' => $this->qrCode->token]), [
                'external_headcount' => 6,
                'manual_adjustment' => 0,
            ]);

        $this->assertDatabaseHas('daily_crew_reports', [
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'external_headcount' => 6,
        ]);
    }

    private function employee(string $number, string $name): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'employee_number' => $number,
            'name' => $name,
            'employment_status' => 'active',
        ]);
    }

    private function clockIn(Employee $employee, string $time): void
    {
        AttendanceLog::query()->create([
            'employee_id' => $employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'attendance_qr_code_id' => $this->qrCode->id,
            'recorded_by_id' => $this->manager->id,
            'attendance_date' => '2026-07-25',
            'event_type' => 'clock_in',
            'event_at' => "2026-07-25 {$time}",
            'source' => 'foreman_badge_qr',
            'status' => 'approved',
        ]);
    }
}
