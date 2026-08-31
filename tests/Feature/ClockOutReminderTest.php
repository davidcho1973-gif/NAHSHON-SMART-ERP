<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceReminder;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Attendance\AutoClockOutService;
use App\Services\Attendance\ClockOutReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 퇴근 알림 — 출근은 찍고 퇴근은 그냥 가는, 현장에서 제일 잦은 사고.
 *
 * 시급 직영은 자동 마감을 하지 않으므로(임금 왜곡 방지) 미마감으로 남고, 급여
 * 마감날 기억으로 채워진다 — 그것이 분쟁이다. 여기서도 "안 보내는 경우" 의 시험이
 * "보내는 경우" 만큼 많다: 잘못 울리는 알림은 꺼지고, 꺼진 알림은 다시 못 켠다.
 */
class ClockOutReminderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Employee $employee;

    private ClockOutReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'RM-CO', 'name' => 'Remind Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $company->id, 'code' => 'RM', 'name' => '현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '김반장', 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_DIRECT,
        ]);
        User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker', 'account_status' => 'active',
        ]);

        $this->service = app(ClockOutReminderService::class);
    }

    /** 지난 주 월~금 퇴근 기록. 앱 시간대가 곧 현장 시간대라 변환 없이 쓴다. */
    private function history(array $localTimes, string $source = 'web_portal'): void
    {
        $days = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'];
        foreach ($localTimes as $i => $time) {
            AttendanceLog::create([
                'employee_id' => $this->employee->id,
                'company_id' => $this->employee->company_id,
                'site_id' => $this->site->id,
                'attendance_date' => $days[$i],
                'event_type' => 'clock_out',
                'event_at' => Carbon::parse($days[$i].' '.$time, 'America/Phoenix'),
                'source' => $source, 'status' => 'approved',
            ]);
        }
    }

    private function log(string $type, string $local, string $date = '2026-08-10'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => $date,
            'event_type' => $type,
            'event_at' => Carbon::parse($date.' '.$local, 'America/Phoenix'),
            'source' => 'web_portal', 'status' => 'approved',
        ]);
    }

    private function at(string $local): Carbon
    {
        return Carbon::parse('2026-08-10 '.$local, 'America/Phoenix');
    }

    private function localNow(string $local = '18:00'): Carbon
    {
        return Carbon::parse('2026-08-10 '.$local, 'America/Phoenix');
    }

    // ── 평소 퇴근 시각 배우기 ─────────────────────────────────────────

    public function test_the_usual_time_is_the_median_so_one_long_day_does_not_drag_it(): void
    {
        // 닷새 중 하루만 자정 — 평균이면 저녁으로 밀리지만 중간값은 15:35 다.
        $this->history(['15:30', '15:35', '15:40', '23:50', '15:32']);

        $usual = $this->service->usualClockOut($this->employee, 'America/Phoenix', $this->localNow());

        $this->assertSame('15:35', $usual->format('H:i'));
    }

    public function test_auto_closed_records_do_not_teach_the_usual_time(): void
    {
        // 자동 마감(16:00)은 그 사람의 습관이 아니다 — 그것으로 배우면
        // 16:00 이 모두의 퇴근 시각이 되어 버린다. 기본값으로 물러나야 한다.
        $this->history(['16:00', '16:00', '16:00', '16:00', '16:00'], source: 'auto_clockout');

        $usual = $this->service->usualClockOut($this->employee, 'America/Phoenix', $this->localNow());

        $this->assertSame('17:00', $usual->format('H:i'), '자동 마감 기록은 배움에서 빼야 한다');
    }

    public function test_a_new_worker_without_history_starts_from_the_default_time(): void
    {
        $usual = $this->service->usualClockOut($this->employee, 'America/Phoenix', $this->localNow());

        $this->assertSame('17:00', $usual->format('H:i'));
    }

    // ── 보내는 경우 ────────────────────────────────────────────────

    public function test_it_asks_after_the_usual_time_plus_a_grace_period(): void
    {
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');

        // 15:35 + 30분 유예 = 16:05 부터. 아직 정리 중일 수 있는 15:50 에는 안 묻는다.
        $this->assertNull($this->service->due($this->employee, $this->at('15:50')));

        $due = $this->service->due($this->employee, $this->at('16:10'));
        $this->assertNotNull($due);
        $this->assertStringContainsString('퇴근', $due['message']['title']);
    }

    public function test_an_hourly_worker_is_told_why_it_matters(): void
    {
        // 직영(TYPE_DIRECT)에 월급 프로필이 없으면 기본이 시급이다 — 퇴근 시각이 곧 임금이다.
        $this->assertSame(Employee::POLICY_HOURLY, $this->employee->attendancePolicy());
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');

        $due = $this->service->due($this->employee, $this->at('16:10'));

        $this->assertNotNull($due);
        $this->assertStringContainsString('급여', $due['message']['body']);
    }

    // ── 안 보내는 경우 ─────────────────────────────────────────────

    public function test_nothing_for_someone_who_already_clocked_out(): void
    {
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');
        $this->log('clock_out', '15:40');

        $this->assertNull($this->service->due($this->employee, $this->at('18:00')));
    }

    public function test_nothing_for_someone_who_did_not_come_in_today(): void
    {
        // 오늘 출근 기록이 없는 사람에게 퇴근을 묻는 것은 그 자체로 틀린 질문이다.
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);

        $this->assertNull($this->service->due($this->employee, $this->at('18:00')));
    }

    public function test_nothing_in_the_middle_of_the_night(): void
    {
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');

        // 새벽에 울리는 퇴근 알림은 그 자체가 사고다.
        $this->assertNull($this->service->due($this->employee, $this->at('03:00')));
        $this->assertNull($this->service->due($this->employee, $this->at('23:30')));
    }

    public function test_it_stops_after_two_and_waits_between_them(): void
    {
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');

        $this->assertNotNull($this->service->due($this->employee, $this->at('16:10')));
        AttendanceReminder::query()->where('employee_id', $this->employee->id)
            ->where('kind', AttendanceReminder::KIND_CLOCK_OUT)
            ->update(['sent_count' => 1, 'last_sent_at' => $this->at('16:10')]);

        // 연달아 두 번 울리면 잔소리다.
        $this->assertNull($this->service->due($this->employee, $this->at('16:30')));
        $this->assertNotNull($this->service->due($this->employee, $this->at('17:10')));

        AttendanceReminder::query()->where('employee_id', $this->employee->id)
            ->where('kind', AttendanceReminder::KIND_CLOCK_OUT)
            ->update(['sent_count' => 2, 'last_sent_at' => $this->at('17:10')]);

        // 세 번째부터는 도움이 아니라 잔소리다.
        $this->assertNull($this->service->due($this->employee, $this->at('19:00')));
    }

    public function test_the_two_reminder_kinds_do_not_eat_each_others_daily_budget(): void
    {
        // 아침에 출근 알림 상한을 다 썼어도 저녁 퇴근 알림은 나가야 한다 —
        // 정작 돈이 걸린 쪽은 퇴근이다.
        AttendanceReminder::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-10',
            'kind' => AttendanceReminder::KIND_CLOCK_IN, 'sent_count' => 2,
            'last_sent_at' => $this->at('07:00'),
        ]);
        $this->history(['15:30', '15:35', '15:40', '15:33', '15:32']);
        $this->log('clock_in', '05:00');

        $this->assertNotNull($this->service->due($this->employee, $this->at('16:10')));
    }

    // ── 알림을 무시했을 때: 사람에게 올라간다 ───────────────────────

    public function test_an_ignored_reminder_becomes_a_manager_alert_with_names(): void
    {
        $this->log('clock_in', '05:00');

        app(AutoClockOutService::class)->run(Carbon::parse('2026-08-10 16:05', 'America/Phoenix'));

        $alert = UnifiedAlert::query()->where('event_type', 'attendance_unclosed')->sole();
        $this->assertSame('ATT', $alert->source_module);
        $this->assertStringContainsString('RM', $alert->title);
        // 숫자만 있으면 누구를 물어봐야 할지 모른다 — 이름이 있어야 한다.
        $this->assertStringContainsString('김반장', $alert->content);
        $this->assertSame($this->site->id, $alert->site_id);
    }

    public function test_a_subcontractor_who_is_auto_closed_does_not_raise_that_alert(): void
    {
        $this->employee->forceFill(['employment_type' => Employee::TYPE_INDIRECT])->save();
        $this->log('clock_in', '05:00');

        app(AutoClockOutService::class)->run(Carbon::parse('2026-08-10 16:05', 'America/Phoenix'));

        // 자동 마감으로 기록이 남았으므로 사람이 확인할 일이 없다.
        $this->assertSame(0, UnifiedAlert::query()->where('event_type', 'attendance_unclosed')->count());
        $this->assertTrue(AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->where('event_type', 'clock_out')
            ->where('source', 'auto_clockout')
            ->exists());
    }
}
