<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollTimesheet;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\AttendanceLogAdminService;
use App\Services\Admin\SiteAdminService;
use App\Services\Payroll\AttendanceTimesheetSync;
use App\Support\SiteClock;
use App\Support\WorkRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 근무 규칙은 <b>현장마다</b> 다르고, 그 규칙은 <b>한 곳</b>에만 있다.
 *
 * 나손 현장은 여러 주에 흩어져 있고 프로젝트마다 작업 시간이 다르다. 그런데 규칙이
 * 코드 상수면 모든 현장이 남의 시간표로 정산된다.
 *
 * 그리고 같은 하루가 화면과 급여에서 다른 숫자로 보이면 안 된다. 화면은 점심을
 * 빼지 않고 급여는 빼던 때가 있었다 — 9시간 30분과 8시간 30분, 둘 다 맞아 보이지만
 * 하나는 틀렸고 그 차이가 임금이라 언젠가 반드시 다툼이 된다.
 */
class WorkRulesPerSiteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $savannah;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        WorkRules::forget();

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->savannah = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        $this->worker = Employee::create([
            'name' => 'Kelvis Quevedo', 'company_id' => $this->company->id,
            'site_id' => $this->savannah->id, 'employment_status' => 'active',
        ]);
    }

    private function punch(string $siteWallClock, string $type, ?Site $site = null): AttendanceLog
    {
        $site ??= $this->savannah;
        $moment = Carbon::parse($siteWallClock, $site->timezone);

        return AttendanceLog::create([
            'employee_id' => $this->worker->id,
            'company_id' => $this->company->id,
            'site_id' => $site->id,
            'attendance_date' => $moment->copy()->timezone($site->timezone)->toDateString(),
            'event_type' => $type,
            'event_at' => $moment->copy()->setTimezone(config('app.timezone')),
            'source' => 'gate_qr',
            'status' => 'approved',
        ]);
    }

    private function timesheet(): ?PayrollTimesheet
    {
        app(AttendanceTimesheetSync::class)->syncDay($this->worker->id, '2026-09-22');

        return PayrollTimesheet::query()->where('employee_id', $this->worker->id)->first();
    }

    // ── 규칙 자체 ───────────────────────────────────────────────────────

    public function test_eight_hours_is_regular_and_lunch_is_unpaid(): void
    {
        // 07:00 에 와서 16:30 에 갔다 = 9시간 30분 현장에 있었고, 점심 1시간은 무급.
        $split = WorkRules::forSite($this->savannah)->split(570);

        $this->assertSame(60, $split['break']);
        $this->assertSame(510, $split['payable'], '점심을 뺀 8시간 30분이 급여 시간이다.');
        $this->assertSame(480, $split['regular']);
        $this->assertSame(30, $split['overtime']);
    }

    public function test_a_half_day_keeps_its_lunch(): void
    {
        // 반나절 일한 사람의 점심까지 빼면, 쉬지도 않은 시간을 근거로 임금을 깎는 것이다.
        $split = WorkRules::forSite($this->savannah)->split(180);

        $this->assertSame(0, $split['break']);
        $this->assertSame(180, $split['payable']);
        $this->assertSame(0, $split['overtime']);
    }

    public function test_a_site_can_have_its_own_hours(): void
    {
        $this->savannah->forceFill([
            'regular_minutes' => 600,     // 하루 10시간 현장
            'break_minutes' => 30,        // 점심 30분
            'break_after_minutes' => 300,
        ])->save();
        WorkRules::forget();

        $split = WorkRules::forSite($this->savannah)->split(660);   // 11시간 체류

        $this->assertSame(30, $split['break']);
        $this->assertSame(630, $split['payable']);
        $this->assertSame(600, $split['regular']);
        $this->assertSame(30, $split['overtime']);
    }

    public function test_two_sites_in_different_states_keep_their_own_rules(): void
    {
        // 이 시험이 이 작업의 이유다 — 현장이 여러 주에 있고 시간표가 다르다.
        $phoenix = Site::create([
            'code' => 'PHX1', 'name' => 'Phoenix', 'country' => 'US',
            'timezone' => 'America/Phoenix', 'status' => 'active', 'company_id' => $this->company->id,
            'work_start' => '06:00:00', 'work_end' => '14:30:00', 'regular_minutes' => 450, 'break_minutes' => 30,
        ]);
        WorkRules::forget();

        $this->assertSame(480, WorkRules::forSite($this->savannah)->regularMinutes);
        $this->assertSame(450, WorkRules::forSite($phoenix)->regularMinutes);
        $this->assertSame('06:00', WorkRules::forSite($phoenix)->start);
        $this->assertSame('14:30', WorkRules::forSite($phoenix)->end);
    }

    public function test_an_unconfigured_site_keeps_the_behaviour_it_always_had(): void
    {
        // 설정을 안 한 현장이 이 변경 때문에 달라지면, 그건 조용한 임금 변경이다.
        $rules = WorkRules::forSite($this->savannah);

        $this->assertSame(WorkRules::DEFAULT_REGULAR_MINUTES, $rules->regularMinutes);
        $this->assertSame(WorkRules::DEFAULT_BREAK_MINUTES, $rules->breakMinutes);
        $this->assertSame(WorkRules::DEFAULT_BREAK_AFTER_MINUTES, $rules->breakAfterMinutes);
    }

    // ── 급여와 화면이 같은 숫자를 말한다 ────────────────────────────────

    public function test_payroll_follows_the_site_rules(): void
    {
        $this->punch('2026-09-22 07:00:00', 'clock_in');
        $this->punch('2026-09-22 16:30:00', 'clock_out');

        $sheet = $this->timesheet();

        $this->assertSame(510, (int) $sheet->payable_minutes);
        $this->assertSame(480, (int) $sheet->regular_minutes);
        $this->assertSame(30, (int) $sheet->overtime_minutes);
    }

    public function test_changing_the_site_rules_changes_what_payroll_counts(): void
    {
        $this->punch('2026-09-22 07:00:00', 'clock_in');
        $this->punch('2026-09-22 16:30:00', 'clock_out');

        $this->savannah->forceFill(['break_minutes' => 0])->save();   // 점심을 주지 않는 현장
        WorkRules::forget();

        $sheet = $this->timesheet();

        $this->assertSame(570, (int) $sheet->payable_minutes, '무급 휴게가 0이면 있던 시간 전부가 급여 시간이다.');
        $this->assertSame(90, (int) $sheet->overtime_minutes);
    }

    public function test_the_screen_shows_the_same_hours_payroll_pays(): void
    {
        // 화면이 9시간 30분, 급여가 8시간 30분이면 둘 다 맞아 보이지만 하나는 틀렸다.
        $this->punch('2026-09-22 07:00:00', 'clock_in');
        $this->punch('2026-09-22 16:30:00', 'clock_out');
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $row = app(AttendanceLogAdminService::class)
            ->list(['from' => '2026-09-01', 'until' => '2026-09-30'])['rows'][0];

        $this->assertSame('8시간 30분', $row['workedLabel']);
        $this->assertSame('점심 1시간 제외', $row['breakLabel'], '빼고 말하지 않으면 «내 시간이 없어졌다» 가 된다.');
        $this->assertSame('초과 30분', $row['overtimeLabel']);
        $this->assertSame(WorkRules::hours((int) $this->timesheet()->payable_minutes), $row['workedLabel']);
    }

    public function test_the_screen_names_the_rule_it_used(): void
    {
        $this->savannah->forceFill(['work_start' => '06:00:00', 'work_end' => '14:30:00'])->save();
        WorkRules::forget();
        $this->punch('2026-09-22 06:00:00', 'clock_in');
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $row = app(AttendanceLogAdminService::class)
            ->list(['from' => '2026-09-01', 'until' => '2026-09-30'])['rows'][0];

        $this->assertSame('06:00–14:30 · 정규 8시간 · 점심 1시간 무급', $row['rulesLabel']);
    }

    // ── 설정 화면 ───────────────────────────────────────────────────────

    public function test_a_manager_sets_the_hours_on_the_site_screen(): void
    {
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        $res = app(SiteAdminService::class)->saveSite([
            'id' => $this->savannah->id,
            'code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York', 'status' => 'active',
            'work_start' => '06:30', 'work_end' => '17:00',
            'regular_minutes' => 600, 'break_minutes' => 30, 'break_after_minutes' => 300,
        ]);

        $this->assertTrue($res['success'], $res['error'] ?? '');

        $rules = WorkRules::forSite($this->savannah->fresh());
        $this->assertSame('06:30', $rules->start);
        $this->assertSame('17:00', $rules->end);
        $this->assertSame(600, $rules->regularMinutes);
        $this->assertSame(30, $rules->breakMinutes);
    }

    public function test_clearing_the_times_returns_the_site_to_the_company_default(): void
    {
        // 값을 지울 길이 없으면, 한 번 잘못 넣은 현장은 영원히 그 값으로 돌아간다.
        $this->savannah->forceFill(['work_start' => '06:00:00', 'work_end' => '14:30:00'])->save();
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));

        app(SiteAdminService::class)->saveSite([
            'id' => $this->savannah->id,
            'code' => '703K', 'name' => 'Savannah', 'timezone' => 'America/New_York', 'status' => 'active',
            'work_start' => '', 'work_end' => '',
        ]);

        $this->assertNull($this->savannah->fresh()->work_start);
        $this->assertSame(WorkRules::DEFAULT_START, WorkRules::forSite($this->savannah->fresh())->start);
    }

    // ── 자동 퇴근 마감도 현장 시각으로 ──────────────────────────────────

    public function test_the_automatic_clock_out_uses_the_sites_own_end_time(): void
    {
        // 회사 한 값으로 마감하면 어느 현장인가는 반드시 남의 시간에 퇴근 처리된다.
        $this->savannah->forceFill(['work_end' => '17:30:00'])->save();
        WorkRules::forget();

        $end = WorkRules::forSite($this->savannah)->endOfWorkDay('2026-09-22');

        $this->assertSame('17:30', $end->copy()->timezone($this->savannah->timezone)->format('H:i'));
        $this->assertSame(
            '2026-09-22',
            $end->copy()->timezone($this->savannah->timezone)->toDateString(),
            '마감은 그날 현장 시계의 시각이어야 한다.',
        );
    }
}
