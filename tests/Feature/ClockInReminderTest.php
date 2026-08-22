<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceReminder;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Attendance\ClockInReminderService;
use App\Services\Attendance\WorkerAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 아침 출근 알림 — 사람마다 자기 시간에, 하루 두 번을 넘지 않게.
 *
 * 알림 시각은 입력이 아니라 그 사람의 기록(최근 2주 중간값)에서 나온다.
 * 잘못 울리는 알림은 꺼지고, 꺼진 알림은 영영 다시 못 켠다 — 그래서 "안 보내는
 * 경우"의 시험이 "보내는 경우"만큼 많다.
 */
class ClockInReminderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Employee $employee;
    private User $user;
    private ClockInReminderService $service;

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
        ]);
        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker', 'account_status' => 'active',
        ]);

        $this->service = app(ClockInReminderService::class);
    }

    /**
     * 지난 주 월~금(8/3~8/7)에 이 시각들로 출근했다고 기록을 깐다.
     * 앱 기준 시간대가 곧 현장 시간대(America/Phoenix)라 변환 없이 그대로 쓴다.
     */
    private function history(array $localTimes): void
    {
        $days = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'];
        foreach ($localTimes as $i => $time) {
            AttendanceLog::create([
                'employee_id' => $this->employee->id,
                'company_id' => $this->employee->company_id,
                'site_id' => $this->site->id,
                'attendance_date' => $days[$i],
                'event_type' => 'clock_in',
                'event_at' => Carbon::parse($days[$i].' '.$time, 'America/Phoenix'),
                'source' => 'web_portal', 'status' => 'approved',
            ]);
        }
    }

    private function at(string $local): Carbon
    {
        return Carbon::parse('2026-08-10 '.$local, 'America/Phoenix');
    }

    // ── 평소 출근 시각 배우기 ─────────────────────────────────────────

    public function test_the_usual_time_is_the_median_so_one_late_day_does_not_drag_it(): void
    {
        // 닷새 중 하루만 11시 — 평균이면 6시 반으로 밀리지만 중간값은 5:05 근처다.
        $this->history(['05:00', '05:05', '05:10', '11:00', '05:02']);

        $usual = $this->service->usualClockIn(
            $this->employee, 'America/Phoenix', Carbon::parse('2026-08-10 04:00', 'America/Phoenix'),
        );

        $this->assertSame('05:05', $usual->format('H:i'));
    }

    public function test_a_new_worker_without_history_starts_from_the_default_time(): void
    {
        $usual = $this->service->usualClockIn(
            $this->employee, 'America/Phoenix', Carbon::parse('2026-08-10 04:00', 'America/Phoenix'),
        );

        $this->assertSame('06:00', $usual->format('H:i'));
    }

    public function test_no_reminder_on_a_weekday_this_person_never_works(): void
    {
        // 월~금 기록만 있는 사람 — 토요일(8/15)에는 울리지 않는다.
        $this->history(['05:00', '05:00', '05:00', '05:00', '05:00']);

        $usual = $this->service->usualClockIn(
            $this->employee, 'America/Phoenix', Carbon::parse('2026-08-15 05:30', 'America/Phoenix'),
        );

        $this->assertNull($usual, '토요일에 나온 적 없는 사람의 토요일 아침을 깨우면 알림이 꺼진다');
    }

    // ── 언제 보내고 언제 침묵하는가 ──────────────────────────────────

    public function test_it_is_due_after_the_usual_time_and_not_before(): void
    {
        $this->history(['05:00', '05:00', '05:00', '05:00']);

        $this->assertNull($this->service->due($this->employee, $this->at('04:40')), '그 사람 시간 전에는 조용하다');
        $this->assertNotNull($this->service->due($this->employee, $this->at('05:10')));
    }

    public function test_it_stays_silent_once_the_person_has_clocked_in(): void
    {
        $this->history(['05:00', '05:00', '05:00', '05:00']);

        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => $this->at('05:03'),
            'source' => 'geo_auto', 'status' => 'approved',
        ]);

        $this->assertNull($this->service->due($this->employee, $this->at('05:20')));
    }

    public function test_at_most_two_reminders_a_day_then_it_gives_up(): void
    {
        $this->history(['05:00', '05:00', '05:00', '05:00']);

        AttendanceReminder::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-10',
            'sent_count' => 2, 'last_sent_at' => $this->at('05:00'),
        ]);

        $this->assertNull(
            $this->service->due($this->employee, $this->at('07:00')),
            '결근·휴가일 수 있다 — 세 번째부터는 도움이 아니라 잔소리다',
        );
    }

    public function test_the_second_reminder_waits_instead_of_firing_back_to_back(): void
    {
        $this->history(['05:00', '05:00', '05:00', '05:00']);

        AttendanceReminder::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-10',
            'sent_count' => 1, 'last_sent_at' => $this->at('05:05'),
        ]);

        $this->assertNull($this->service->due($this->employee, $this->at('05:20')), '첫 알림 직후 또 울리면 잔소리다');
        $this->assertNotNull($this->service->due($this->employee, $this->at('05:50')));
    }

    public function test_nothing_fires_outside_the_morning_window(): void
    {
        $this->history(['05:00', '05:00', '05:00', '05:00']);

        $this->assertNull($this->service->due($this->employee, $this->at('02:00')), '밤에 울리는 출근 알림은 사고다');
        $this->assertNull($this->service->due($this->employee, $this->at('14:00')));
    }

    // ── 출근 시각 정정 요청 ──────────────────────────────────────────

    public function test_a_late_first_record_can_be_flagged_for_correction(): void
    {
        Carbon::setTestNow($this->at('11:30'));
        $log = AttendanceLog::create([
            'employee_id' => $this->employee->id, 'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => $this->at('11:00'),
            'source' => 'geo_auto', 'status' => 'approved',
        ]);

        $result = app(WorkerAttendanceService::class)->requestCorrection($this->employee, '05:00');

        $this->assertTrue($result['success']);
        $log->refresh();
        $this->assertSame('pending', $log->status, '임금 기록은 본인 신고만으로 바뀌지 않는다 — 반장 확인 대기로 돈다');
        $this->assertSame('05:00', $log->payload['correction_request']['requested_time']);
        $this->assertStringContainsString('정정 요청', (string) $log->notes);
    }

    public function test_the_same_day_cannot_be_flagged_twice(): void
    {
        Carbon::setTestNow($this->at('11:30'));
        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => $this->at('11:00'),
            'source' => 'geo_auto', 'status' => 'approved',
        ]);

        $svc = app(WorkerAttendanceService::class);
        $this->assertTrue($svc->requestCorrection($this->employee, '05:00')['success']);
        $this->assertFalse($svc->requestCorrection($this->employee, '05:30')['success']);
    }

    public function test_only_an_earlier_time_can_be_requested(): void
    {
        Carbon::setTestNow($this->at('11:30'));
        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id, 'attendance_date' => '2026-08-10',
            'event_type' => 'clock_in', 'event_at' => $this->at('05:00'),
            'source' => 'web_portal', 'status' => 'approved',
        ]);

        $result = app(WorkerAttendanceService::class)->requestCorrection($this->employee, '09:00');

        $this->assertFalse($result['success'], '더 늦게 왔다는 정정은 본인 요청으로 시작할 일이 아니다');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
