<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\AttendanceLogAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 하루에 출근 한 줄, 퇴근 한 줄.
 *
 * 실제로 이렇게 나와 있었다 — 같은 사람 같은 날에 퇴근이 두 줄, 출근·퇴근이 같은
 * 분(13:56)에 찍힌 줄. GPS 자동 기록(geo_auto)에 중복 검사가 아예 없었기 때문이다.
 * 게이트와 QR 에는 있었는데 그쪽만 빠져서, 이미 퇴근한 사람에게 퇴근이 또 생겼다.
 *
 * 기록을 만드는 곳이 여덟 군데라(웹·GPS·게이트·QR·수기·자동마감·현장앱·오프라인)
 * 검사를 화면마다 붙이면 한 군데는 반드시 빠진다. 그래서 모델에 둔다 — 여기 있는 한
 * 어느 경로로 들어와도 같은 규칙을 받는다.
 */
class AttendanceOncePerDayTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'DP', 'name' => 'TEST CO', 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'Dhairo Carmona', 'employment_status' => 'active',
        ]);
    }

    private function punch(string $type, string $time, string $source = 'web_portal'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'event_type' => $type,
            'event_at' => Carbon::parse('2026-08-11 '.$time),
            'attendance_date' => '2026-08-11',
            'status' => 'approved',
            'source' => $source,
        ]);
    }

    private function rows(string $type): int
    {
        return AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->where('event_type', $type)
            ->count();
    }

    private function timeOf(string $type): ?string
    {
        return AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->where('event_type', $type)
            ->first()?->event_at?->format('H:i');
    }

    // ── 출근은 먼저 찍은 것이 남는다 ────────────────────────────────────

    public function test_the_first_clock_in_of_the_day_is_the_one_that_counts(): void
    {
        // 점심 먹고 돌아와 다시 찍어도, 그날 처음 온 시각이 출근이어야 한다.
        $this->punch('clock_in', '07:00');
        $this->punch('clock_in', '13:39', 'geo_auto');

        $this->assertSame(1, $this->rows('clock_in'));
        $this->assertSame('07:00', $this->timeOf('clock_in'));
    }

    // ── 퇴근은 나중에 찍은 것이 남는다 ──────────────────────────────────

    public function test_the_last_clock_out_of_the_day_wins(): void
    {
        // 실제로 있었던 하루 — 13:41 에 퇴근을 눌렀지만 GPS 는 19:06 까지 현장에
        // 있었다고 본다. 오후에 나갔다 돌아온 것이므로 19:06 까지가 그날 일한 시간이다.
        $this->punch('clock_out', '13:41');
        $this->punch('clock_out', '19:06', 'geo_auto');

        $this->assertSame(1, $this->rows('clock_out'));
        $this->assertSame('19:06', $this->timeOf('clock_out'));
    }

    public function test_an_earlier_clock_out_does_not_pull_the_day_back(): void
    {
        // 늦게 들어온 이른 시각이 그날 퇴근을 앞당기면 임금이 줄어든다.
        $this->punch('clock_out', '19:06');
        $this->punch('clock_out', '13:41', 'geo_auto');

        $this->assertSame(1, $this->rows('clock_out'));
        $this->assertSame('19:06', $this->timeOf('clock_out'));
    }

    // ── 버려도 흔적은 남는다 ────────────────────────────────────────────

    public function test_a_dropped_punch_leaves_a_trace(): void
    {
        // 아무 말 없이 사라지면 "분명히 찍었는데 왜 없냐" 를 확인할 방법이 없다.
        $this->punch('clock_in', '07:00');
        $this->punch('clock_in', '13:39', 'geo_auto');

        $edits = AttendanceLog::query()->where('event_type', 'clock_in')->first()->payload['admin_edits'] ?? [];

        $this->assertNotEmpty($edits);
        $this->assertSame('merge', end($edits)['action']);
        $this->assertStringContainsString('geo_auto', end($edits)['by']);
    }

    // ── 다른 날 · 다른 사람은 따로다 ────────────────────────────────────

    public function test_another_day_is_a_separate_day(): void
    {
        $this->punch('clock_in', '07:00');

        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'company_id' => $this->company->id, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse('2026-08-12 07:10'), 'attendance_date' => '2026-08-12',
            'status' => 'approved', 'source' => 'web_portal',
        ]);

        $this->assertSame(2, $this->rows('clock_in'));
    }

    public function test_two_people_do_not_block_each_other(): void
    {
        $other = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => 'HYUNSUK CHO', 'employment_status' => 'active',
        ]);
        $this->punch('clock_in', '07:00');

        AttendanceLog::create([
            'employee_id' => $other->id, 'site_id' => $this->site->id,
            'company_id' => $this->company->id, 'event_type' => 'clock_in',
            'event_at' => Carbon::parse('2026-08-11 07:31'), 'attendance_date' => '2026-08-11',
            'status' => 'approved', 'source' => 'web_portal',
        ]);

        $this->assertSame(2, AttendanceLog::query()->where('event_type', 'clock_in')->count());
    }

    public function test_clock_in_and_clock_out_are_different_things(): void
    {
        $this->punch('clock_in', '07:00');
        $this->punch('clock_out', '17:00');

        $this->assertSame(1, $this->rows('clock_in'));
        $this->assertSame(1, $this->rows('clock_out'));
    }

    // ── 반려한 기록은 길을 막지 않는다 ──────────────────────────────────

    public function test_a_rejected_punch_does_not_block_a_good_one(): void
    {
        // 잘못 찍힌 것을 반려해 뒀는데 그것 때문에 제대로 된 기록을 못 넣으면,
        // 고칠 방법이 사라진다.
        $bad = $this->punch('clock_in', '03:00');
        $bad->update(['status' => 'rejected']);

        $this->punch('clock_in', '07:00');

        $this->assertSame(1, AttendanceLog::query()
            ->where('event_type', 'clock_in')->where('status', 'approved')->count());
    }

    public function test_a_deleted_punch_does_not_block_a_new_one(): void
    {
        $wrong = $this->punch('clock_in', '03:00');
        $wrong->delete();

        $this->punch('clock_in', '07:00');

        $this->assertSame('07:00', $this->timeOf('clock_in'));
    }

    // ── 사람이 손으로 넣을 때는 말해 준다 ──────────────────────────────

    public function test_the_admin_screen_says_why_instead_of_swallowing_it(): void
    {
        // 눌렀는데 아무 일도 안 일어나면 저장이 안 된 줄 알고 또 누른다.
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
        $this->punch('clock_in', '07:00');

        $res = app(AttendanceLogAdminService::class)->save([
            'employeeId' => (string) $this->employee->id,
            'eventType' => 'clock_in',
            'eventAt' => '2026-08-11 13:39',
            'status' => 'approved',
            'source' => 'manual',
            'siteId' => (string) $this->site->id,
        ]);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('이미 있습니다', $res['errors']['eventType']);
        $this->assertStringContainsString('07:00', $res['errors']['eventType']);
    }

    public function test_the_admin_can_still_fix_the_existing_record(): void
    {
        // 막기만 하고 고칠 길이 없으면 기록을 바로잡을 방법이 사라진다.
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
        $row = $this->punch('clock_in', '07:00');

        $res = app(AttendanceLogAdminService::class)->save([
            'id' => $row->id,
            'employeeId' => (string) $this->employee->id,
            'eventType' => 'clock_in',
            'eventAt' => '2026-08-11 07:30',
            'status' => 'approved',
            'source' => 'manual',
            'siteId' => (string) $this->site->id,
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('07:30', $this->timeOf('clock_in'));
    }
}
