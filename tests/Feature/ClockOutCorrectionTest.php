<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Attendance\WorkerAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 퇴근 시각도 정정할 수 있어야 한다.
 *
 * ── 왜 ─────────────────────────────────────────────────────────────────
 * 정정 기능은 <b>절반만</b> 만들어져 있었다. 서버는 오늘의 clock_in 기록만 찾았고,
 * 화면도 출근 기록에만 버튼을 붙였다. 그래서 퇴근이 잘못 찍히면 — 4시 30분에 일이
 * 끝나고 정리하다 5시 10분에 찍히거나, 3시 30분에 찍고 30분 더 일하거나 — 작업자
 * 손에 아무 길이 없었다. 반장에게 말로 하는 수밖에 없었다.
 *
 * 퇴근 시각이 곧 임금이다. 고칠 길이 없는 임금 기록을 남겨 두면 안 된다.
 *
 * ── 방향이 한쪽으로만 열려 있는 이유 ───────────────────────────────────
 * 출근은 <b>더 이른</b> 시각만, 퇴근은 <b>더 늦은</b> 시각만 요청할 수 있다.
 * 둘 다 «내 근무시간이 실제로는 더 길었다» 는 방향이다. 반대 방향은 근무시간을
 * 줄이는 요청이라 본인 신고로 시작할 일이 아니다 — 작업자가 실수로 자기 임금을
 * 깎게 된다. 줄이는 것은 반장이 기록을 보고 직접 한다.
 */
class ClockOutCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::query()->create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'employee_number' => 'W-1', 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B',
            'employment_status' => 'active',
        ]);
        User::query()->create([
            'name' => 'A B', 'email' => 'ab@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites',
            'account_status' => 'active', 'employee_id' => $this->employee->id,
        ]);
    }

    /** 오늘 그 현장 시간대의 특정 시각에 기록 한 줄을 남긴다. */
    private function log(string $type, string $localTime, string $status = 'approved'): AttendanceLog
    {
        $tz = $this->site->timezone;
        $at = Carbon::parse(Carbon::now($tz)->toDateString().' '.$localTime, $tz);

        return AttendanceLog::query()->create([
            'employee_id' => $this->employee->id, 'company_id' => $this->employee->company_id,
            'site_id' => $this->site->id, 'attendance_date' => Carbon::now($tz)->toDateString(),
            'event_type' => $type, 'event_at' => $at, 'source' => 'web_portal',
            'status' => $status, 'payload' => ['verified_on_site' => true],
        ]);
    }

    /** @return array<string, mixed> */
    private function ask(string $time, string $direction = 'out', string $lang = 'ko'): array
    {
        return app(WorkerAttendanceService::class)
            ->requestCorrection($this->employee, $time, $lang, $direction);
    }

    public function test_a_worker_can_ask_to_fix_a_clock_out_that_was_recorded_too_early(): void
    {
        // 3시 30분에 찍고 30분 더 일했다.
        $out = $this->log('clock_out', '15:30');

        $result = $this->ask('16:00');

        $this->assertTrue($result['success'], '퇴근 시각을 고칠 길이 있어야 한다 — 임금이 걸린 기록이다.');

        $out->refresh();
        $this->assertSame('pending', $out->status, '본인 신고만으로 임금 기록을 바꾸지 않는다. 반장이 본다.');
        $this->assertSame('16:00', $out->payload['correction_request']['requested_time']);
        $this->assertSame('15:30', $out->payload['correction_request']['recorded_time']);
        $this->assertSame('clock_out', $out->payload['correction_request']['event_type'],
            '반장 화면이 «무엇을 고쳐 달라는 건가» 를 되묻지 않아도 돼야 한다.');
        $this->assertStringContainsString('16:00', (string) $out->notes);
    }

    public function test_the_request_does_not_touch_the_clock_in_record(): void
    {
        // 두 기록이 같은 날 같이 있다. 방향을 안 보고 첫 기록을 잡으면 출근이 바뀐다.
        $in = $this->log('clock_in', '06:00');
        $this->log('clock_out', '15:30');

        $this->ask('16:00');

        $in->refresh();
        $this->assertSame('approved', $in->status, '퇴근을 고쳐 달라고 했는데 출근이 대기로 떨어지면 안 된다.');
        $this->assertArrayNotHasKey('correction_request', (array) $in->payload);
    }

    public function test_clock_in_still_works_the_old_way_without_being_told_the_direction(): void
    {
        // 방향을 안 보내던 시절의 화면이 남아 있어도 출근 정정은 그대로 돼야 한다.
        $in = $this->log('clock_in', '11:00');

        $result = app(WorkerAttendanceService::class)->requestCorrection($this->employee, '05:00');

        $this->assertTrue($result['success']);
        $in->refresh();
        $this->assertSame('05:00', $in->payload['correction_request']['requested_time']);
    }

    public function test_it_refuses_a_clock_out_earlier_than_the_record(): void
    {
        // 「사실 더 일찍 끝났다」 는 자기 임금을 깎는 요청이다. 본인 신고로 시작할 일이 아니다.
        $out = $this->log('clock_out', '16:00');

        $result = $this->ask('15:00');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('늦은 시각만', $result['error']);
        $this->assertStringContainsString('반장', $result['error'], '막았으면 어디로 가야 하는지 말해 줘야 한다.');
        $this->assertSame('approved', $out->refresh()->status);
    }

    public function test_it_refuses_a_clock_in_later_than_the_record(): void
    {
        // 출근은 반대 방향이다. 규칙이 한 벌로 뒤집혀야지, 한쪽만 맞으면 안 된다.
        $this->log('clock_in', '06:00');

        $result = $this->ask('07:00', 'in');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('이른 시각만', $result['error']);
    }

    public function test_it_says_so_when_there_is_no_clock_out_yet(): void
    {
        // 출근만 찍고 아직 안 나간 사람. 「출근 기록이 없습니다」 라고 하면 화면을 믿지 않게 된다.
        $this->log('clock_in', '06:00');

        $result = $this->ask('16:00');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('퇴근 기록이 없습니다', $result['error']);
    }

    public function test_a_second_clock_out_does_not_leave_a_second_record_to_fix(): void
    {
        // 하루에 퇴근을 두 번 찍어도 기록은 <b>한 줄</b>이다 — 모델이 뒤엣것을 앞엣
        // 줄에 합치고 시각만 늦춘다. 그래서 «어느 퇴근을 고쳐 달라는 건가» 가
        // 생기지 않는다. 여기서 그 전제를 못으로 박아 둔다: 언젠가 합치기를 떼면
        // 고칠 대상이 둘이 되고, 그때 이 시험이 먼저 깨져 알려 준다.
        $row = $this->log('clock_out', '12:00');
        $this->log('clock_out', '16:00');

        $this->assertSame(1, AttendanceLog::query()->where('event_type', 'clock_out')->count());

        $this->assertTrue($this->ask('17:00')['success']);

        $row->refresh();
        $this->assertSame('17:00', $row->payload['correction_request']['requested_time']);
        $this->assertSame('16:00', $row->payload['correction_request']['recorded_time'],
            '합쳐진 뒤의 시각(늦은 쪽)이 기준이어야 한다.');
    }

    public function test_only_one_request_a_day_per_record(): void
    {
        $this->log('clock_out', '15:30');

        $this->assertTrue($this->ask('16:00')['success']);
        $second = $this->ask('17:00');

        $this->assertFalse($second['success']);
        $this->assertStringContainsString('이미', $second['error']);
    }

    public function test_a_rejected_record_is_not_the_one_to_fix(): void
    {
        $this->log('clock_out', '15:30', 'rejected');

        $this->assertFalse($this->ask('16:00')['success']);
    }

    public function test_a_junk_time_is_refused_before_anything_is_touched(): void
    {
        $out = $this->log('clock_out', '15:30');

        $this->assertFalse($this->ask('저녁때')['success']);
        $this->assertFalse($this->ask('25:00')['success']);
        $this->assertSame('approved', $out->refresh()->status);
    }

    public function test_the_route_carries_the_direction_through(): void
    {
        // 서버가 받을 준비가 돼 있어도 경로가 방향을 버리면 화면은 아무것도 못 한다.
        $this->log('clock_out', '15:30');

        $this->actingAs(User::query()->firstOrFail())
            ->postJson(route('attendance-app.correction'), [
                'time' => '16:00', 'lang' => 'ko', 'direction' => 'out',
            ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_the_screen_offers_the_clock_out_button_in_all_three_languages(): void
    {
        // 서버만 고치면 쓸 수 있는 사람이 없다.
        $html = (string) file_get_contents(base_path('resources/views/attendance-app/index.blade.php'));

        $this->assertStringContainsString('퇴근 시각 정정 요청', $html);
        $this->assertStringContainsString('Fix clock-out time', $html);
        $this->assertStringContainsString('Corregir hora de salida', $html);

        // 버튼이 방향을 실어 보내야 한다. 없으면 퇴근 버튼이 출근을 고친다.
        $this->assertStringContainsString('data-dir=', $html);
        $this->assertStringContainsString("direction: out ? 'out' : 'in'", $html);
    }
}
