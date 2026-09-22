<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Attendance\AutoClockOutService;
use App\Services\Attendance\GateAttendanceService;
use App\Services\Attendance\WorkerAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 찍힌 시각은 <b>그 현장의 시계</b>로 보여야 한다.
 *
 * ── 겪은 일 (2026-09-22) ───────────────────────────────────────────────
 * 조지아 사바나 현장에서 작업자가 아침 7시에 출근을 찍었는데 기록에는 10시로 찍혔다.
 * 정확히 3시간 — 애리조나 피닉스(UTC-7)와 사바나(UTC-4)의 차이다.
 *
 * 원인은 «현장 시간대가 틀렸다» 가 아니었다. 2026-07-24 에 날짜 칸을 전부 naive 로
 * 통일하면서 «저장되는 문자열은 <b>앱 시간대</b>의 벽시계» 가 규칙이 됐는데, 게이트만
 * 그 규칙을 어기고 <b>현장 시간대</b>의 벽시계를 넣고 있었다. 쓰는 시계와 읽는 시계가
 * 달라서, 두 시계의 차이(피닉스↔사바나 = 3시간)가 그대로 오차가 된 것이다.
 *
 * 현장이 애리조나 한 곳일 때는 두 시계가 같아 아무 일도 없었다. 현장이 다른 주(州)에
 * 생기는 순간 드러났다.
 *
 * 그리고 이건 보기 흉한 정도의 문제가 아니다 — 시각이 3시간 어긋나면 근무시간이
 * 어긋나고, 그건 그 사람 임금이다.
 */
class AttendanceShowsTheSiteClockTest extends TestCase
{
    use RefreshDatabase;

    private function siteAt(string $timezone): Site
    {
        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);

        return Site::query()->create([
            'company_id' => $company->id, 'code' => '703K', 'name' => 'Savannah',
            'country' => 'US', 'timezone' => $timezone, 'status' => 'active',
        ]);
    }

    private function workerAt(Site $site): Employee
    {
        $employee = Employee::query()->create([
            'company_id' => $site->company_id, 'site_id' => $site->id,
            'name' => 'John Smith', 'first_name' => 'John', 'last_name' => 'Smith',
            'phone' => '9125550100', 'employment_status' => 'active',
        ]);
        User::query()->create([
            'name' => 'John Smith', 'email' => null, 'password' => Hash::make('x'),
            'employee_id' => $employee->id,
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        return $employee;
    }

    /** 사바나의 아침 7시 — 9월이라 EDT(UTC-4)이므로 세계시로 11시. */
    private function savannahSevenAm(): Carbon
    {
        return Carbon::parse('2026-09-22 11:00:00', 'UTC');
    }

    public function test_seven_in_the_morning_in_savannah_reads_as_seven(): void
    {
        $site = $this->siteAt('America/New_York');
        $employee = $this->workerAt($site);

        Carbon::setTestNow($this->savannahSevenAm());
        app(WorkerAttendanceService::class)->punch($employee, 'in', ['gate_site' => $site->id]);

        $today = app(WorkerAttendanceService::class)->home($employee);
        $this->assertSame('07:00', $today['logs'][0]['at'] ?? null);
    }

    /**
     * 게이트로 찍어도 같은 시각이어야 한다 — 이것이 사바나에서 어긋난 자리다.
     *
     * 이 저장소는 2026-07-24 에 날짜 칸을 전부 <b>naive</b> 로 통일했다(그 마이그레이션의
     * 주석에 이유가 적혀 있다). 그때부터 규칙은 «저장되는 문자열은 <b>앱 시간대</b>의
     * 벽시계» 다. Laravel 이 그 규칙으로 쓰고 그 규칙으로 읽기 때문에 왕복이 맞는다.
     *
     * 그런데 게이트만 `Carbon::now($tz)` 로 <b>현장 시간대의 벽시계</b>를 만들어 넣었다.
     * 사바나의 07:00 이 문자열 "07:00" 으로 저장되고, 읽을 때는 그것을 피닉스의 07:00
     * 으로 읽어 세계시 14:00 이 된다. 그것을 다시 사바나 시계로 보이면 <b>10:00</b> —
     * 사장님이 본 그 숫자다.
     */
    public function test_a_gate_punch_reads_the_same_as_an_app_punch(): void
    {
        $site = $this->siteAt('America/New_York');
        $employee = $this->workerAt($site);

        Carbon::setTestNow($this->savannahSevenAm());
        app(GateAttendanceService::class)->punch($employee, $site);

        $today = app(WorkerAttendanceService::class)->home($employee);
        $this->assertSame('07:00', $today['logs'][0]['at'] ?? null, '게이트로 찍은 07:00 이 10:00 으로 적히면 그 3시간은 그 사람 임금이다.');
    }

    public function test_the_work_date_follows_the_site_clock_too(): void
    {
        // 사바나의 밤 11시는 피닉스로는 아직 저녁 8시 — <b>같은 날</b>이다. 하지만
        // 사바나의 새벽 1시는 피닉스로는 <b>전날</b> 밤 10시다. 날짜가 하루 밀리면
        // 그날 출역 인원과 일일 마감이 통째로 어긋난다.
        $site = $this->siteAt('America/New_York');
        $employee = $this->workerAt($site);

        // 사바나 2026-09-23 새벽 1시 = 2026-09-23 05:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-09-23 05:00:00', 'UTC'));
        app(WorkerAttendanceService::class)->punch($employee, 'in', ['gate_site' => $site->id]);

        $this->assertSame('2026-09-23', AttendanceLog::query()->value('attendance_date')?->toDateString());
    }

    /**
     * 자동 퇴근 마감도 같은 자리에서 어긋났다.
     *
     * «저녁 6시» 라는 뜻은 맞았는데, 그 순간을 <b>현장 시계 문자열</b>로 저장했다.
     * 읽을 때는 앱 시간대로 읽으니 사바나에서는 저녁 9시로 적힌다.
     */
    public function test_the_automatic_clock_out_lands_at_the_site_evening(): void
    {
        $site = $this->siteAt('America/New_York');
        $employee = $this->workerAt($site);
        $employee->forceFill(['employment_type' => Employee::TYPE_INDIRECT])->save();

        Carbon::setTestNow($this->savannahSevenAm());
        app(GateAttendanceService::class)->punch($employee, $site);

        // 사바나 밤 11시 — 그날은 이미 끝났다.
        Carbon::setTestNow(Carbon::parse('2026-09-23 03:00:00', 'UTC'));
        app(AutoClockOutService::class)->run(Carbon::parse('2026-09-22'));

        $out = AttendanceLog::query()->where('event_type', 'clock_out')->first();
        $this->assertNotNull($out, '자동 마감이 퇴근을 만들지 않았습니다.');
        $this->assertSame(
            (string) AutoClockOutService::cutoffHour().':00',
            ltrim($out->event_at->timezone('America/New_York')->format('G:i')),
            '자동 퇴근이 현장의 저녁 시각으로 적혀야 한다.',
        );
    }

    public function test_the_instant_itself_is_stored_not_a_wall_clock_reading(): void
    {
        // 저장되는 것은 «몇 시였다» 가 아니라 «언제였다» 여야 한다. 벽시계 숫자를
        // 저장하면 읽는 쪽이 어느 시계로 읽느냐에 따라 답이 달라진다.
        $site = $this->siteAt('America/New_York');
        $employee = $this->workerAt($site);

        Carbon::setTestNow($this->savannahSevenAm());
        app(WorkerAttendanceService::class)->punch($employee, 'in', ['gate_site' => $site->id]);

        $this->assertSame('11:00', AttendanceLog::query()->value('event_at')->utc()->format('H:i'));
    }
}
