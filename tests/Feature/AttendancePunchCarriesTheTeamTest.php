<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Services\Attendance\DailyHeadcountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 「이 출근은 어느 팀 것인가」 는 기록에 적혀 있어야 한다.
 *
 * 기록을 만드는 곳이 여덟 군데인데 다섯 곳(게이트·GPS 자동·자동마감·현장앱·레거시)이
 * 팀을 비워 두었다. 그래서 팀별 출역 현황은 게이트로 찍은 출근을 한 명도 못 봤다 —
 * 현장에는 사람이 서 있는데 그 팀 숫자에는 없는 상태이고, 화면만 봐서는 알 수 없다.
 *
 * 그래서 빠뜨린 다섯 곳에 한 줄씩 더하지 않고 <b>기록 자신</b>이 답하게 했다.
 * 여섯 번째 경로가 생겨도 자동으로 답이 붙는다.
 */
class AttendancePunchCarriesTheTeamTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-24';

    private Site $site;

    private Company $company;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create([
            'code' => 'SUB', 'name' => '한빛전기', 'status' => 'active',
            'company_type' => Company::TYPE_PARTNER,
        ]);
        $this->team = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEAM-E1',
            'name' => '전기 1팀',
        ]);
    }

    private function worker(string $name, ?int $teamId): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $teamId,
            'name' => $name,
            'role' => 'Electrician',
            'employment_status' => 'active',
            'employment_type' => Employee::TYPE_INDIRECT,
        ]);
    }

    /** 팀을 적지 않고 만든 기록 — 게이트·GPS 자동·자동마감이 이렇게 만든다. */
    private function punch(Employee $employee, string $source, string $time = '07:10'): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $this->site->id,
            'attendance_date' => self::DATE,
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse(self::DATE.' '.$time, 'America/Phoenix'),
            'source' => $source,
            'status' => 'approved',
        ]);
    }

    /**
     * 팀을 안 적는 경로가 여럿이라 하나씩 확인한다 — 다음에 새 경로가 붙어도
     * 이 목록에 한 줄 더하면 그 경로까지 같이 잠긴다.
     */
    public static function teamlessSources(): array
    {
        return [
            '게이트 QR' => ['gate_qr'],
            'GPS 자동' => ['geo_auto'],
            '자동 마감' => ['auto_clock_out'],
            '현장앱' => ['field_app'],
        ];
    }

    #[DataProvider('teamlessSources')]
    public function test_a_punch_that_names_no_team_is_counted_by_the_workers_team(string $source): void
    {
        $employee = $this->worker('김전기', $this->team->id);

        $log = $this->punch($employee, $source);

        $this->assertSame($this->team->id, $log->fresh()->team_id);

        $headcount = app(DailyHeadcountService::class);
        $this->assertSame(1, $headcount->presentCount($this->site->id, self::DATE, $this->team->id));
        $this->assertSame(1, $headcount->presentCount($this->site->id, self::DATE));
    }

    /**
     * 팀 QR 은 일부러 <b>그 QR 의 팀</b>을 적는다 — 남의 팀을 도우러 간 사람의 출근은
     * 그날 그 팀 일로 잡혀야 한다. 기록이 스스로 채우는 값이 그 뜻을 덮으면 안 된다.
     */
    public function test_a_team_written_on_purpose_is_never_overwritten(): void
    {
        $other = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEAM-E2',
            'name' => '전기 2팀',
        ]);
        $employee = $this->worker('이도움', $this->team->id);

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $this->site->id,
            'team_id' => $other->id,
            'attendance_date' => self::DATE,
            'event_type' => 'clock_in',
            'event_at' => Carbon::parse(self::DATE.' 07:00', 'America/Phoenix'),
            'source' => 'team_qr',
            'status' => 'approved',
        ]);

        $this->assertSame($other->id, $log->fresh()->team_id);
        $headcount = app(DailyHeadcountService::class);
        $this->assertSame(1, $headcount->presentCount($this->site->id, self::DATE, $other->id));
        $this->assertSame(0, $headcount->presentCount($this->site->id, self::DATE, $this->team->id));
    }

    /** 관리자가 일부러 비운 팀은 다음 저장에 되살아나지 않는다. */
    public function test_a_team_an_admin_cleared_stays_cleared(): void
    {
        $employee = $this->worker('박정정', $this->team->id);
        $log = $this->punch($employee, 'gate_qr');

        $log->forceFill(['team_id' => null])->save();
        $log->forceFill(['notes' => '관리자 수정'])->save();

        $this->assertNull($log->fresh()->team_id);
    }

    /** 팀이 없는 사람은 그대로 팀 없이 남는다 — 없는 값을 지어내지 않는다. */
    public function test_a_worker_with_no_team_still_records_none(): void
    {
        $employee = $this->worker('최무소속', null);

        $log = $this->punch($employee, 'gate_qr');

        $this->assertNull($log->fresh()->team_id);
        $this->assertSame(1, app(DailyHeadcountService::class)->presentCount($this->site->id, self::DATE));
    }
}
