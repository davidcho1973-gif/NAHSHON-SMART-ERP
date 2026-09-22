<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
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

    /** 고치기 전의 게이트가 남기던 모양 그대로 — 현장 시계의 벽시계가 저장된 줄. */
    private function brokenGateLog(string $siteWallClock, string $source = 'gate_qr'): AttendanceLog
    {
        return AttendanceLog::query()->create([
            'employee_id' => $this->worker->id,
            'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id,
            'attendance_date' => substr($siteWallClock, 0, 10),
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse($siteWallClock, config('app.timezone')),
            'source' => $source,
            'status' => 'approved',
        ]);
    }

    /** --apply 는 «언제까지의 줄인가» 를 반드시 받는다. 시험도 실제와 같게 부른다. */
    private function apply(): PendingCommand
    {
        return $this->artisan('attendance:fix-gate-clock', [
            '--apply' => true,
            '--before' => now()->addMinute()->toDateTimeString(),
        ]);
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

    public function test_what_it_used_to_show_is_kept_so_the_change_can_be_explained(): void
    {
        // 나중에 «왜 시각이 바뀌었냐» 를 물으면 답할 수 있어야 한다.
        $log = $this->brokenGateLog('2026-09-22 07:00:00');

        $this->apply()->assertSuccessful();

        $this->assertSame('09/22 10:00', $log->refresh()->payload['gate_clock_was'] ?? null);
    }
}
