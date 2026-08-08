<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DailyClosingReport;
use App\Models\DailyCrewReport;
use App\Models\Employee;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Models\Team;
use App\Services\Ops\DailyClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 마감은 하나여야 한다.
 *
 * 모바일 출퇴근앱은 팀 단위로 daily_crew_reports 에, 상황실은 현장 단위로
 * daily_closing_reports 에 마감을 남기면서 서로를 몰랐다. 양쪽에서 마감하면
 * 그날 최종 인원이 두 벌 남고, 어느 쪽이 정본인지 알 수 없었다.
 *
 * 이제 현장 마감이 팀 마감을 읽어 정본을 만든다.
 * 팀 마감은 "입력", 현장 마감은 "확정".
 */
class DailyClosingCrewMergeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private const DATE = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
    }

    private function team(string $code, string $name): Team
    {
        return Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function scanned(Team $team, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $emp = Employee::create([
                'company_id' => $this->company->id,
                'site_id' => $this->site->id,
                'name' => $team->code.'-'.$i,
                'role' => 'Worker',
                'employment_status' => 'active',
                'employment_type' => Employee::TYPE_INDIRECT,
            ]);

            AttendanceLog::create([
                'employee_id' => $emp->id,
                'company_id' => $this->company->id,
                'site_id' => $this->site->id,
                'team_id' => $team->id,
                'attendance_date' => self::DATE,
                'event_type' => 'clock_in',
                'event_at' => Carbon::parse(self::DATE.' 07:00:00', 'America/Phoenix'),
                'source' => 'team_qr',
                'status' => 'approved',
            ]);
        }
    }

    private function crewClose(Team $team, array $attrs): DailyCrewReport
    {
        return DailyCrewReport::create(array_merge([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $team->id,
            'work_date' => self::DATE,
            'status' => 'closed',
            'closed_at' => now(),
        ], $attrs));
    }

    private function metrics(): array
    {
        return app(DailyClosingService::class)->metrics($this->site->id, self::DATE);
    }

    // ── 정본 ────────────────────────────────────────────────────────────

    public function test_the_site_closing_absorbs_every_team_close(): void
    {
        $a = $this->team('T-A', '전기 1팀');
        $b = $this->team('T-B', '배관 2팀');
        $this->scanned($a, 5);
        $this->scanned($b, 3);
        $this->crewClose($a, ['scanned_headcount' => 5, 'external_headcount' => 2, 'manual_adjustment' => 0]);
        $this->crewClose($b, ['scanned_headcount' => 3, 'external_headcount' => 1, 'manual_adjustment' => -1,
            'adjustment_reason' => '조퇴 1명']);

        $labor = $this->metrics()['labor'];

        $this->assertSame(8, $labor['actualQr'], 'QR 실적은 8명');
        $this->assertSame(3, $labor['crew']['external'], '외부 인원은 팀 마감에서만 알 수 있다');
        $this->assertSame(-1, $labor['crew']['adjustment']);
        $this->assertSame(10, $labor['final'], '최종 인원 = QR 8 + 외부 3 - 보정 1');
        $this->assertSame(2, $labor['crew']['closedTeams']);
    }

    public function test_the_adjustment_reason_travels_with_the_number(): void
    {
        // 숫자만 바뀌고 이유가 없으면 나중에 아무도 설명하지 못한다.
        $t = $this->team('T-A', '전기 1팀');
        $this->scanned($t, 4);
        $this->crewClose($t, ['scanned_headcount' => 4, 'manual_adjustment' => -2,
            'adjustment_reason' => '오전 반차 2명', 'work_description' => '2층 배관 슬리브']);

        $team = $this->metrics()['labor']['crew']['teams'][0];

        $this->assertSame('전기 1팀', $team['team']);
        $this->assertSame('오전 반차 2명', $team['adjustmentReason']);
        $this->assertSame('2층 배관 슬리브', $team['workDescription']);
    }

    public function test_a_day_with_no_team_close_still_reports_a_final_number(): void
    {
        $t = $this->team('T-A', '전기 1팀');
        $this->scanned($t, 6);

        $labor = $this->metrics()['labor'];

        $this->assertSame([], $labor['crew']['teams']);
        $this->assertSame(6, $labor['final'], '팀 마감이 없으면 최종 인원은 QR 실적 그대로다');
    }

    public function test_the_final_headcount_never_goes_below_zero(): void
    {
        // 보정을 잘못 넣어도 음수 인원이 보고서에 남으면 안 된다.
        $t = $this->team('T-A', '전기 1팀');
        $this->scanned($t, 2);
        $this->crewClose($t, ['scanned_headcount' => 2, 'manual_adjustment' => -99]);

        $this->assertSame(0, $this->metrics()['labor']['final']);
    }

    public function test_another_sites_team_close_does_not_leak_in(): void
    {
        $other = Site::create(['code' => 'TX-02', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $mine = $this->team('T-A', '전기 1팀');
        $theirs = Team::create(['company_id' => $this->company->id, 'site_id' => $other->id, 'code' => 'T-X', 'name' => '남의 현장팀']);

        $this->scanned($mine, 3);
        $this->crewClose($mine, ['scanned_headcount' => 3, 'external_headcount' => 1]);
        DailyCrewReport::create([
            'company_id' => $this->company->id, 'site_id' => $other->id, 'team_id' => $theirs->id,
            'work_date' => self::DATE, 'status' => 'closed',
            'scanned_headcount' => 50, 'external_headcount' => 50,
        ]);

        $labor = $this->metrics()['labor'];

        $this->assertSame(1, $labor['crew']['external'], '남의 현장 마감이 섞이면 안 된다');
        $this->assertSame(4, $labor['final']);
    }

    public function test_yesterdays_team_close_does_not_count_toward_today(): void
    {
        $t = $this->team('T-A', '전기 1팀');
        $this->scanned($t, 3);
        DailyCrewReport::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'team_id' => $t->id,
            'work_date' => '2026-08-04', 'status' => 'closed',
            'scanned_headcount' => 9, 'external_headcount' => 9,
        ]);

        $this->assertSame(0, $this->metrics()['labor']['crew']['external']);
        $this->assertSame(3, $this->metrics()['labor']['final']);
    }

    // ── 보고 인원과의 차이 ────────────────────────────────────────────────

    public function test_the_reported_versus_actual_gap_still_uses_qr_not_the_final(): void
    {
        // AI 가 읽은 보고 인원과 맞대볼 상대는 QR 실적이다. 외부 인원을 얹은 최종값과
        // 비교하면 차이가 사라져 버려서, 정작 확인이 필요한 어긋남을 놓친다.
        $t = $this->team('T-A', '전기 1팀');
        $this->scanned($t, 8);
        $this->crewClose($t, ['scanned_headcount' => 8, 'external_headcount' => 3]);
        OpsLaborReport::create([
            'site_id' => $this->site->id, 'company_id' => $this->company->id,
            'work_date' => self::DATE, 'headcount' => 11, 'trade' => '전기',
        ]);

        $labor = $this->metrics()['labor'];

        $this->assertSame(11, $labor['reported']);
        $this->assertSame(8, $labor['actualQr']);
        $this->assertSame(3, $labor['gap'], '보고 11 - QR 8 = 3명 확인 필요');
        $this->assertSame(11, $labor['final']);
    }

    // ── 목록 ────────────────────────────────────────────────────────────

    public function test_old_reports_without_a_final_fall_back_to_the_qr_count(): void
    {
        // 이 필드가 생기기 전에 만들어진 보고서도 목록에서 숫자가 비면 안 된다.
        DailyClosingReport::create([
            'site_id' => $this->site->id,
            'report_date' => self::DATE,
            'status' => 'done',
            'metrics' => ['labor' => ['reported' => 12, 'actualQr' => 10]],
            'narrative' => ['headline' => '예전 보고서'],
        ]);

        $row = app(DailyClosingService::class)->recent($this->site->id)['reports'][0];

        $this->assertSame(10, $row['final']);
    }
}
