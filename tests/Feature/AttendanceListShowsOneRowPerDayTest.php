<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\AttendanceLogAdminService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 출퇴근 기록 목록 — <b>한 사람의 하루가 한 줄</b>, 시각은 <b>현장 시계</b>.
 *
 * 여기서 잠그는 것은 두 가지다.
 *
 * 1. 출근과 퇴근이 나란히 선다. 따로 올라오면 같은 사람의 두 끝이 목록 여기저기에
 *    흩어져, 「몇 시간 일했나」 를 눈으로 짝지어야 한다 — 그게 이 표를 여는 이유인데도.
 * 2. 시각을 <b>그 일이 일어난 곳의 시계</b>로 보여 준다. 서버 시계로 보여 주면
 *    사바나 아침 7시 50분 출근이 04:50 으로 뜬다. 기록은 옳은데 화면만 거짓말을 한다 —
 *    2026-09 에 실제로 그랬고, 알아채는 데 하루가 걸렸다.
 */
class AttendanceListShowsOneRowPerDayTest extends TestCase
{
    use RefreshDatabase;

    private Site $savannah;

    private Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();

        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->savannah = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $company->id,
        ]);
        $this->worker = Employee::create([
            'name' => 'Kelvis Quevedo', 'employee_number' => 'E-2001',
            'company_id' => $company->id, 'site_id' => $this->savannah->id, 'employment_status' => 'active',
        ]);
    }

    /** 현장 시계로 이때 찍혔다 — 저장은 순간으로. */
    private function punch(string $siteWallClock, string $type = 'clock_in', array $extra = []): AttendanceLog
    {
        $moment = Carbon::parse($siteWallClock, $this->savannah->timezone);

        return AttendanceLog::create(array_merge([
            'employee_id' => $this->worker->id,
            'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id,
            'attendance_date' => $moment->copy()->timezone($this->savannah->timezone)->toDateString(),
            'event_type' => $type,
            'event_at' => $moment->copy()->setTimezone(config('app.timezone')),
            'source' => 'gate_qr',
            'status' => 'approved',
        ], $extra));
    }

    private function svc(): AttendanceLogAdminService
    {
        return app(AttendanceLogAdminService::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function firstRow(): array
    {
        $res = $this->svc()->list(['from' => '2026-09-01', 'until' => '2026-09-30']);
        $this->assertTrue($res['success']);

        return $res['rows'][0];
    }

    public function test_one_person_one_day_is_one_row_with_both_ends(): void
    {
        $this->punch('2026-09-22 07:50:00');
        $this->punch('2026-09-22 16:20:00', 'clock_out');
        $this->admin();

        $res = $this->svc()->list(['from' => '2026-09-01', 'until' => '2026-09-30']);

        $this->assertCount(1, $res['rows'], '출근과 퇴근이 두 줄로 흩어지면 안 된다.');
        $row = $res['rows'][0];
        $this->assertSame('07:50', $row['clockIn']['time']);
        $this->assertSame('16:20', $row['clockOut']['time']);
        // 「근무」 는 급여가 보는 시간이다 — 현장에 있던 8시간 30분에서 무급 점심 1시간을 뺀 값.
        $this->assertSame('7시간 30분', $row['workedLabel']);
        $this->assertSame('점심 1시간 제외', $row['breakLabel']);
    }

    public function test_the_time_is_the_clock_of_the_place_it_happened(): void
    {
        // 서버 시계(피닉스)로 보여 주면 04:50 이 된다 — 아무도 그 시각에 출근하지 않았다.
        $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $row = $this->firstRow();

        $this->assertSame('07:50', $row['clockIn']['time']);
        $this->assertNotSame('04:50', $row['clockIn']['time']);
    }

    public function test_the_row_says_which_clock_it_is_showing(): void
    {
        // 이 한 글자가 화면에 없어서, «3시간 차이» 를 발견하는 데 하루가 걸렸다.
        $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $this->assertSame('EDT', $this->firstRow()['zone']);
    }

    public function test_a_missing_clock_out_is_an_empty_box_not_a_zero(): void
    {
        // 퇴근이 없는 날은 «0시간 일했다» 가 아니라 «모른다» 이고, 그 차이가 임금이다.
        $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $row = $this->firstRow();

        $this->assertNotNull($row['clockIn']);
        $this->assertNull($row['clockOut']);
        $this->assertNull($row['workedLabel']);
    }

    public function test_the_last_tag_of_the_day_is_the_one_that_closes_it(): void
    {
        // 같은 날 두 번 찍어도 줄은 하나다(모델이 합친다). 점심 뒤에 다시 찍은 퇴근이
        // 그날의 끝이 되어야 한다 — 12시로 닫히면 오후가 통째로 사라진다.
        $this->punch('2026-09-22 07:50:00');
        $this->punch('2026-09-22 08:10:00');                 // 중복 태그
        $this->punch('2026-09-22 12:00:00', 'clock_out');
        $this->punch('2026-09-22 16:20:00', 'clock_out');    // 점심 뒤 진짜 퇴근
        $this->admin();

        $row = $this->firstRow();

        $this->assertSame('07:50', $row['clockIn']['time']);
        $this->assertSame('16:20', $row['clockOut']['time']);
        $this->assertSame('7시간 30분', $row['workedLabel']);
    }

    public function test_a_rejected_record_is_kept_in_sight_next_to_the_good_one(): void
    {
        // 반려된 기록은 합쳐지지 않으므로 같은 날 같은 구분이 둘이 된다. 그때 한 줄로
        // 모으면서 하나를 숨기면, 급여 다툼에서 «그건 화면에 없었다» 가 된다.
        $this->punch('2026-09-22 05:30:00', 'clock_in', ['status' => 'rejected']);
        $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $row = $this->firstRow();

        $this->assertCount(1, $row['extras'], '밀려난 기록도 같은 줄에 남아야 한다.');
        $times = array_merge([$row['clockIn']['time']], array_column($row['extras'], 'time'));
        sort($times);
        $this->assertSame(['05:30', '07:50'], $times);
    }

    public function test_a_rejected_end_does_not_become_worked_hours(): void
    {
        $this->punch('2026-09-22 07:50:00');
        $this->punch('2026-09-22 16:20:00', 'clock_out', ['status' => 'rejected']);
        $this->admin();

        $this->assertNull($this->firstRow()['workedLabel'], '반려된 기록은 급여 계산에서 빠진다.');
    }

    public function test_two_people_on_the_same_day_are_two_rows(): void
    {
        $other = Employee::create([
            'name' => 'Javier Escobar', 'company_id' => $this->worker->company_id,
            'site_id' => $this->savannah->id, 'employment_status' => 'active',
        ]);
        $this->punch('2026-09-22 07:50:00');
        $this->punch('2026-09-22 07:51:00', 'clock_in', ['employee_id' => $other->id]);
        $this->admin();

        $this->assertCount(2, $this->svc()->list(['from' => '2026-09-01', 'until' => '2026-09-30'])['rows']);
    }

    public function test_how_it_was_recorded_is_written_in_words(): void
    {
        // 'gate_qr' 이 그대로 뜨면, 급여 근거를 보는 사람에게는 «내가 모르는 경로로
        // 들어온 기록» 으로 읽힌다 — 그 줄을 믿을지 말지 판단할 수 없게 된다.
        $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $this->assertSame('게이트 QR', $this->firstRow()['clockIn']['sourceLabel']);
    }

    public function test_a_hand_entered_record_cannot_claim_it_came_from_the_gate(): void
    {
        // 출처가 증거인데 사람이 아무 출처나 붙일 수 있으면 그 증거가 증거가 아니게 된다.
        $this->admin();

        $this->svc()->save([
            'employeeId' => $this->worker->id,
            'siteId' => $this->savannah->id,
            'eventType' => 'clock_in',
            'eventAt' => '2026-09-22 07:00:00',
            'status' => 'approved',
            'source' => 'gate_qr',
        ]);

        $this->assertSame('manual', $this->firstRow()['clockIn']['source']);
    }

    public function test_editing_a_gate_record_does_not_rewrite_where_it_came_from(): void
    {
        // 고치려고 열었을 뿐인데 출처가 «수기 입력» 으로 바뀌면, 그 기록이 실제로
        // 게이트에서 찍혔다는 사실이 사라진다.
        $log = $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $this->svc()->save([
            'id' => $log->id,
            'employeeId' => $this->worker->id,
            'siteId' => $this->savannah->id,
            'eventType' => 'clock_in',
            'eventAt' => '2026-09-22 08:00:00',
            'status' => 'approved',
            'source' => 'gate_qr',
        ]);

        $row = $this->firstRow();
        $this->assertSame('gate_qr', $row['clockIn']['source']);
        $this->assertSame('08:00', $row['clockIn']['time']);
    }

    // ── 고칠 때도 같은 시계 ─────────────────────────────────────────────

    public function test_saving_a_record_without_changing_it_does_not_move_the_time(): void
    {
        // 화면은 현장 시계로 보여 주는데 저장은 서버 시계로 읽으면, 기록을 열어
        // 아무것도 안 고치고 저장만 해도 시각이 3시간 움직인다.
        $log = $this->punch('2026-09-22 07:50:00');
        $this->admin();

        $shown = $this->firstRow()['clockIn']['eventAt'];

        $res = $this->svc()->save([
            'id' => $log->id,
            'employeeId' => $this->worker->id,
            'siteId' => $this->savannah->id,
            'eventType' => 'clock_in',
            'eventAt' => $shown,
            'status' => 'approved',
        ]);

        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame([], $res['changed'] ?? [], '열었다 닫았을 뿐인데 무언가 바뀌면 안 된다.');
        $this->assertSame('07:50', $this->firstRow()['clockIn']['time']);
    }

    public function test_a_hand_typed_time_is_read_on_the_site_clock(): void
    {
        // 관리자가 화면에서 본 시계로 적는다. 07:00 이라고 적으면 사바나 아침 7시다.
        $this->admin();

        $res = $this->svc()->save([
            'employeeId' => $this->worker->id,
            'siteId' => $this->savannah->id,
            'eventType' => 'clock_in',
            'eventAt' => '2026-09-22 07:00:00',
            'status' => 'approved',
        ]);

        $this->assertTrue($res['success'], $res['error'] ?? '');
        $row = $this->firstRow();
        $this->assertSame('07:00', $row['clockIn']['time']);
        $this->assertSame('2026-09-22', $row['date']);
    }

    public function test_a_late_night_punch_belongs_to_the_site_day_not_the_server_day(): void
    {
        // 사바나 밤 10시는 피닉스로 저녁 7시다. 날짜를 서버 시계로 정하면 그날
        // 출역 인원과 일일 마감이 통째로 어긋난다.
        $this->punch('2026-09-22 22:00:00');
        $this->admin();

        $row = $this->firstRow();

        $this->assertSame('2026-09-22', $row['date']);
        $this->assertSame('22:00', $row['clockIn']['time']);
    }
}
