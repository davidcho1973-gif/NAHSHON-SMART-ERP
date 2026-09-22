<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\AutoClockOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * 이미 3시간 밀린 채 저장된 기록을 바로잡는다.
 *
 * 코드를 고쳐도 <b>이미 저장된 줄</b>은 그대로다. 그건 근무시간이고 근무시간은 임금이라
 * 그냥 둘 수 없다. 다만 시각을 고치는 명령은 잘못 쓰면 원래보다 나빠지므로,
 * 여기서 잠그는 것은 «고친다» 보다 «<b>엉뚱한 줄은 건드리지 않는다</b>» 쪽이다.
 */
class FixGateAttendanceClockTest extends TestCase
{
    use RefreshDatabase;

    private Site $savannah;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->savannah = Site::query()->create([
            'company_id' => $company->id, 'code' => '703K', 'name' => 'Savannah',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
        $this->worker = Employee::query()->create([
            'company_id' => $company->id, 'site_id' => $this->savannah->id,
            'name' => 'John Smith', 'first_name' => 'John', 'last_name' => 'Smith',
            'phone' => '9125550100', 'employment_status' => 'active',
        ]);
    }

    /**
     * 고치기 전의 게이트가 남기던 모양 그대로 — 현장 시계의 벽시계가 저장된 줄.
     *
     * created_at 까지 그때처럼 넣는다. 게이트는 찍히는 그 순간에 줄을 만들었고
     * created_at 은 <b>앱 시계로 옳게</b> 적혔다 — 그 어긋남이 이 사고의 증거이고,
     * 지금은 명령이 그 증거를 보고 고칠 줄을 고른다. 증거 없이 만든 줄로 시험하면
     * 실제와 다른 것을 시험하는 셈이 된다.
     */
    private function brokenGateLog(string $siteWallClock, string $source = 'gate_qr'): AttendanceLog
    {
        $trueMoment = Carbon::parse($siteWallClock, $this->savannah->timezone);

        $log = AttendanceLog::query()->create([
            'employee_id' => $this->worker->id,
            'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id,
            'attendance_date' => substr($siteWallClock, 0, 10),
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse($siteWallClock, config('app.timezone')),
            'source' => $source,
            'status' => 'approved',
        ]);

        $log->forceFill(['created_at' => $trueMoment->copy()->setTimezone(config('app.timezone'))])->saveQuietly();

        return $log;
    }

    /** 고친 코드가 남기는 모양 — 찍힌 순간이 그대로 적힌 줄. */
    private function correctGateLog(string $siteWallClock): AttendanceLog
    {
        $trueMoment = Carbon::parse($siteWallClock, $this->savannah->timezone);

        $log = AttendanceLog::query()->create([
            'employee_id' => $this->worker->id,
            'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id,
            'attendance_date' => $trueMoment->copy()->timezone($this->savannah->timezone)->toDateString(),
            'event_type' => 'clock_in',
            'event_at' => $trueMoment->copy()->setTimezone(config('app.timezone')),
            'source' => 'gate_qr',
            'status' => 'approved',
        ]);

        $log->forceFill(['created_at' => $trueMoment->copy()->setTimezone(config('app.timezone'))])->saveQuietly();

        return $log;
    }

    private function apply(): PendingCommand
    {
        return $this->artisan('attendance:fix-gate-clock', ['--apply' => true]);
    }

    private function shownAt(AttendanceLog $log): string
    {
        return $log->refresh()->event_at->timezone('America/New_York')->format('H:i');
    }

    public function test_a_preview_changes_nothing(): void
    {
        $log = $this->brokenGateLog('2026-09-22 07:00:00');
        $this->assertSame('10:00', $this->shownAt($log), '전제 확인 — 고치기 전에는 10시로 보인다.');

        $this->artisan('attendance:fix-gate-clock')->assertSuccessful();

        $this->assertSame('10:00', $this->shownAt($log), '미리보기는 아무것도 바꾸지 않아야 한다.');
    }

    public function test_applying_it_puts_the_morning_back_where_it_belongs(): void
    {
        $log = $this->brokenGateLog('2026-09-22 07:00:00');

        $this->apply()->assertSuccessful();

        $this->assertSame('07:00', $this->shownAt($log));
    }

    public function test_running_it_twice_does_not_shift_the_time_twice(): void
    {
        // 시각을 고치는 명령이 두 번 돌면 오차가 두 배가 된다 — 처음보다 나빠진다.
        $log = $this->brokenGateLog('2026-09-22 07:00:00');

        $this->apply()->assertSuccessful();
        $this->apply()->assertSuccessful();

        $this->assertSame('07:00', $this->shownAt($log));
    }

    public function test_records_from_other_doors_are_left_alone(): void
    {
        // 앱·수기 기록은 처음부터 옳았다. 그것까지 옮기면 멀쩡한 기록을 망가뜨린다.
        $app = $this->brokenGateLog('2026-09-22 07:00:00', 'web_portal');
        $before = $this->shownAt($app);

        $this->apply()->assertSuccessful();

        $this->assertSame($before, $this->shownAt($app));
    }

    public function test_records_written_after_the_code_was_fixed_are_left_alone(): void
    {
        // 이 명령이 가장 크게 잘못될 수 있는 방법은 «멀쩡한 기록을 3시간 옮기는 것» 이다.
        // 예전에는 사람이 배포 시각(--before)을 정확히 적어야 그 사고를 막을 수 있었다.
        // 이제는 줄 스스로가 증거를 갖고 있어, 아무 조건 없이 돌려도 옳은 줄은 그대로다.
        $good = $this->correctGateLog('2026-09-22 07:00:00');
        $bad = $this->brokenGateLog('2026-09-23 07:00:00');

        $this->apply()->assertSuccessful();

        $this->assertSame('07:00', $this->shownAt($good));
        $this->assertSame('07:00', $this->shownAt($bad), '어긋난 줄은 여전히 고쳐져야 한다.');
    }

    public function test_a_site_on_the_app_clock_is_left_alone(): void
    {
        // 애리조나 현장은 두 시계가 같아 어긋난 적이 없다.
        $this->savannah->forceFill(['timezone' => config('app.timezone')])->save();
        $log = $this->brokenGateLog('2026-09-22 07:00:00');
        $before = $log->refresh()->event_at->format('H:i');

        $this->apply()->assertSuccessful();

        $this->assertSame($before, $log->refresh()->event_at->format('H:i'));
    }

    public function test_the_work_date_follows_the_corrected_time(): void
    {
        // 사바나 밤 11시로 저장된 줄은 고치면 그날 저녁 8시가 된다 — 날짜는 그대로여야 한다.
        // 반대로 날짜가 밀리면 그날 출역 인원과 일일 마감이 통째로 어긋난다.
        $log = $this->brokenGateLog('2026-09-22 23:00:00');

        $this->apply()->assertSuccessful();

        $this->assertSame('2026-09-22', $log->refresh()->attendance_date->toDateString());
        $this->assertSame('23:00', $this->shownAt($log));
    }

    /** 자동 마감이 남기던 모양 — 현장 시계의 마감 시각 숫자가 그대로 들어간 줄. */
    private function brokenAutoCloseLog(string $workDate): AttendanceLog
    {
        $hour = str_pad((string) AutoClockOutService::cutoffHour(), 2, '0', STR_PAD_LEFT);

        $log = AttendanceLog::query()->create([
            'employee_id' => $this->worker->id,
            'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id,
            'attendance_date' => $workDate,
            'event_type' => 'clock_out',
            'event_at' => Carbon::parse("{$workDate} {$hour}:00:00", config('app.timezone')),
            'source' => 'auto_clockout',
            'status' => 'approved',
        ]);

        // 자동 마감은 밤에 한꺼번에 돈다 — 적은 시각은 찍힌 시각과 무관하다.
        $log->forceFill(['created_at' => Carbon::parse("{$workDate} 23:30:00", config('app.timezone'))])->saveQuietly();

        return $log;
    }

    public function test_the_automatic_clock_out_is_fixed_too(): void
    {
        // 근무시간은 출근과 퇴근 두 끝으로 잰다. 출근만 고치면 임금은 여전히 틀리다.
        $log = $this->brokenAutoCloseLog('2026-09-22');
        $cutoff = AutoClockOutService::cutoffHour();

        $this->apply()->assertSuccessful();

        $this->assertSame(
            sprintf('%02d:00', $cutoff),
            $this->shownAt($log),
            '자동 마감은 현장 시계의 마감 시각으로 보여야 한다.',
        );
    }

    public function test_an_automatic_clock_out_that_was_already_right_is_left_alone(): void
    {
        $cutoff = AutoClockOutService::cutoffHour();
        $correct = Carbon::parse(sprintf('2026-09-22 %02d:00:00', $cutoff), $this->savannah->timezone)
            ->setTimezone(config('app.timezone'));

        $log = $this->brokenAutoCloseLog('2026-09-22');
        $log->forceFill(['event_at' => $correct])->saveQuietly();

        $this->apply()->assertSuccessful();

        $this->assertSame(sprintf('%02d:00', $cutoff), $this->shownAt($log));
    }

    public function test_the_deploy_itself_repairs_the_records(): void
    {
        // 서버에 들어가 명령을 돌릴 사람이 없어도 고쳐져야 한다. 환경이 셋(나손·다솔·KSR)
        // 이라 사람이 기억해야 하면 한 곳은 빠지고, 빠진 곳 사람들의 임금만 조용히
        // 3시간 어긋난 채로 남는다.
        $log = $this->brokenGateLog('2026-09-22 07:00:00');

        $migration = require database_path('migrations/2026_09_22_000101_fix_gate_attendance_clock.php');
        $migration->up();

        $this->assertSame('07:00', $this->shownAt($log));
    }

    public function test_what_it_used_to_show_is_kept_so_the_change_can_be_explained(): void
    {
        // 나중에 «왜 시각이 바뀌었냐» 를 물으면 답할 수 있어야 한다.
        $log = $this->brokenGateLog('2026-09-22 07:00:00');

        $this->apply()->assertSuccessful();

        $this->assertSame('09/22 10:00', $log->refresh()->payload['gate_clock_was'] ?? null);
    }
}
