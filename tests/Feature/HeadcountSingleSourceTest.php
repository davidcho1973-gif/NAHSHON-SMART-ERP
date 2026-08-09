<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Attendance\DailyHeadcountService;
use App\Services\DailyCrewReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 인원을 세는 규칙은 한 곳에만 있어야 한다.
 *
 * 전에는 모바일 출퇴근앱(DailyCrewReportService)과 상황실(DailyHeadcountService)이
 * 같은 attendance_logs 를 보면서 각자 집계 쿼리를 갖고 있었다. 규칙이 두 벌이면
 * 언젠가 갈라지고, 갈라진 순간 "오늘 몇 명 일했나" 에 답이 두 개가 된다.
 *
 * 이 표는 급여의 근거가 되므로 숫자가 어긋나는 것은 실제 손해로 이어진다.
 */
class HeadcountSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private Team $team;

    private const DATE = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->team = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEAM-E1',
            'name' => '전기 1팀',
        ]);
    }

    private function worker(string $name): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => $name,
            'role' => 'Electrician',
            'employment_status' => 'active',
            'employment_type' => Employee::TYPE_INDIRECT,
        ]);
    }

    private function log(Employee $emp, string $type, string $time, array $extra = []): void
    {
        AttendanceLog::create(array_merge([
            'employee_id' => $emp->id,
            'company_id' => $emp->company_id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'attendance_date' => self::DATE,
            'event_type' => $type,
            'event_at' => Carbon::parse(self::DATE.' '.$time, 'America/Phoenix'),
            'source' => 'team_qr',
            'status' => 'approved',
        ], $extra));
    }

    private function qr(): AttendanceQrCode
    {
        return AttendanceQrCode::create([
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'name' => '전기 1팀 출퇴근 QR',
            'mode' => 'crew',
            'status' => 'active',
            'token' => 'tok-team-1',
            'token_hash' => hash('sha256', 'tok-team-1'),
        ]);
    }

    private function headcount(): DailyHeadcountService
    {
        return app(DailyHeadcountService::class);
    }

    private function crew(): DailyCrewReportService
    {
        return app(DailyCrewReportService::class);
    }

    // ── 두 화면이 같은 숫자를 본다 ────────────────────────────────────────

    public function test_the_app_and_the_ops_room_report_the_same_headcount(): void
    {
        $a = $this->worker('강민철');
        $b = $this->worker('이수현');
        $this->log($a, 'clock_in', '07:00:00');
        $this->log($a, 'clock_out', '16:00:00');
        $this->log($b, 'clock_in', '07:30:00');

        $opsRoom = $this->headcount()->today($this->site->id, self::DATE, $this->team->id);
        $app = $this->crew()->scannedHeadcount($this->qr(), self::DATE);

        $this->assertSame(2, $app);
        $this->assertSame(2, $opsRoom['indirect']['count'], '앱과 상황실이 다른 숫자를 보면 어느 쪽이 맞는지 알 수 없다');
    }

    public function test_a_worker_who_only_clocked_out_is_not_counted_as_having_come(): void
    {
        // 전날 야간조가 새벽에 퇴근만 찍은 경우. 그날 온 사람은 아니다.
        // 예전에는 상황실만 이 사람을 세고 앱은 안 세서 숫자가 하나 어긋났다.
        $night = $this->worker('야간조');
        $this->log($night, 'clock_out', '02:00:00');

        $this->assertSame(0, $this->crew()->scannedHeadcount($this->qr(), self::DATE));
        $this->assertSame(0, $this->headcount()->presentCount($this->site->id, self::DATE, $this->team->id));
        $this->assertSame(0, $this->headcount()->today($this->site->id, self::DATE, $this->team->id)['indirect']['count']);
    }

    public function test_a_rejected_log_is_counted_by_neither(): void
    {
        $ghost = $this->worker('반려된 기록');
        $this->log($ghost, 'clock_in', '07:00:00', ['status' => 'rejected']);

        $this->assertSame(0, $this->crew()->scannedHeadcount($this->qr(), self::DATE));
        $this->assertSame(0, $this->headcount()->presentCount($this->site->id, self::DATE, $this->team->id));
    }

    public function test_repeated_scans_by_one_person_count_once(): void
    {
        $eager = $this->worker('여러번 찍은 사람');
        $this->log($eager, 'clock_in', '07:00:00');
        $this->log($eager, 'clock_in', '07:01:00');
        $this->log($eager, 'clock_in', '12:30:00');

        $this->assertSame(1, $this->crew()->scannedHeadcount($this->qr(), self::DATE));
        $this->assertSame(1, $this->headcount()->presentCount($this->site->id, self::DATE, $this->team->id));
    }

    // ── 팀 범위 ──────────────────────────────────────────────────────────

    public function test_the_team_qr_only_counts_its_own_team(): void
    {
        $other = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEAM-P2',
            'name' => '배관 2팀',
        ]);

        $mine = $this->worker('우리팀');
        $theirs = $this->worker('남의팀');
        $this->log($mine, 'clock_in', '07:00:00');
        $this->log($theirs, 'clock_in', '07:00:00', ['team_id' => $other->id]);

        $this->assertSame(1, $this->crew()->scannedHeadcount($this->qr(), self::DATE), '팀 QR 은 그 팀만 세야 한다');
        $this->assertSame(2, $this->headcount()->presentCount($this->site->id, self::DATE), '현장 전체는 둘 다 센다');
    }

    public function test_the_site_view_covers_every_team(): void
    {
        $other = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEAM-P2',
            'name' => '배관 2팀',
        ]);

        foreach (['A', 'B', 'C'] as $i => $name) {
            $w = $this->worker($name);
            $this->log($w, 'clock_in', '07:0'.$i.':00', ['team_id' => $i === 2 ? $other->id : $this->team->id]);
        }

        $this->assertSame(2, $this->headcount()->presentCount($this->site->id, self::DATE, $this->team->id));
        $this->assertSame(1, $this->headcount()->presentCount($this->site->id, self::DATE, $other->id));
        $this->assertSame(3, $this->headcount()->presentCount($this->site->id, self::DATE));
    }

    // ── 마감 ────────────────────────────────────────────────────────────

    public function test_the_closed_report_stores_the_shared_count(): void
    {
        $a = $this->worker('강민철');
        $b = $this->worker('이수현');
        $this->log($a, 'clock_in', '07:00:00');
        $this->log($b, 'clock_in', '07:10:00');

        // 마감은 그날 기준이므로 현장 시간대의 오늘로 기록을 옮겨 둔다.
        $today = Carbon::today('America/Phoenix')->toDateString();
        AttendanceLog::query()->update(['attendance_date' => $today]);

        $user = User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $report = $this->crew()->closeDay($user, $this->qr(), [
            'external_headcount' => 3,
            'manual_adjustment' => 0,
            'work_description' => '2층 배관 슬리브',
        ]);

        $this->assertSame(2, $report->scanned_headcount);
        $this->assertSame(2, $this->headcount()->presentCount($this->site->id, $today, $this->team->id),
            '마감에 남는 숫자와 상황실이 보는 숫자가 같아야 한다');
    }
}
