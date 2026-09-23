<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Wbs\WeekBoardService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 3주 나란히 — 지난주 · 이번 주 · 다음 주(사장 지시).
 *
 * 세 판 모두 한 주 판(board)과 같은 계산이다. 여기서 지키는 것은 «어느 주가 어느 칸에
 * 가는가» 와 «이름표(지난주/이번 주/다음 주)가 실제 오늘 기준인가» 뿐이다.
 */
class WeekBoardThreeWeeksTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        Carbon::setTestNow('2026-09-23 10:00:00');   // 수요일 — 이번 주 월요일 9/21

        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $company->id]);
        $this->actingAs(User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']));

        foreach (['2026-09-14' => '지난주 일', '2026-09-21' => '이번 주 일', '2026-09-28' => '다음 주 일'] as $week => $task) {
            WeekBoardLine::create(['company_id' => $company->id, 'site_id' => $this->site->id, 'week_start' => $week,
                'trade' => '배관', 'task' => $task, 'status' => $week === '2026-09-14' ? 'blocked' : 'planned']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_three_weeks_sit_side_by_side_with_the_viewed_week_in_the_middle(): void
    {
        $res = app(WeekBoardService::class)->threeWeeks('703K');

        $this->assertTrue($res['success']);
        $this->assertSame('2026-09-21', $res['current']['weekStart'], '가운데가 보고 있는 주이고 한 주 판과 같은 응답이다');
        $this->assertSame(['지난주', '이번 주', '다음 주'], array_column($res['weeks'], 'label'));
        $this->assertSame(['2026-09-14', '2026-09-21', '2026-09-28'], array_column($res['weeks'], 'weekStart'));
        $this->assertSame([false, true, false], array_column($res['weeks'], 'isThisWeek'));
        $this->assertSame(['지난주 일', '이번 주 일', '다음 주 일'], array_map(fn ($w) => $w['groups'][0]['lines'][0]['task'], $res['weeks']));
        $this->assertSame(1, $res['weeks'][0]['blockedCount']);
    }

    public function test_labels_follow_the_real_calendar_when_looking_at_another_week(): void
    {
        $res = app(WeekBoardService::class)->threeWeeks('703K', '2026-09-28');

        $this->assertSame('2026-09-28', $res['current']['weekStart']);
        $this->assertSame(['이번 주', '다음 주', '10/5 주'], array_column($res['weeks'], 'label'), '이름표는 화면이 아니라 달력 기준이다');
    }

    public function test_without_a_site_it_answers_like_the_one_week_board(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => null, 'account_status' => 'active']));

        $res = app(WeekBoardService::class)->threeWeeks('ALL');

        $this->assertTrue($res['noSite']);
        $this->assertArrayNotHasKey('weeks', $res);
    }
}
