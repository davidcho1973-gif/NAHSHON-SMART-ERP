<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Wbs\WeekBoardService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 이번 주 작업판 — 공종별로, 현장의 말로, 한 주.
 *
 * 정식 공정표는 액티비티 80개에 코드와 선행이 붙은 원청용 문서다. 사장 말로 「내용도
 * 틀리고 너무 전문적이고 너무 잘게 쪼개져 있다」. 현장이 한 주를 잡는 단위는
 * 공종 × 하는 일 × 인원이고, 이 표는 그 단위를 그대로 담는다.
 */
class WeekBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        Carbon::setTestNow('2026-09-23 10:00:00');   // 수요일

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
        $this->actingAs($user);

        return $user;
    }

    private function svc(): WeekBoardService
    {
        return app(WeekBoardService::class);
    }

    private function worker(string $trade, string $name = 'W'): Employee
    {
        return Employee::create([
            'name' => $name, 'role' => $trade, 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'employment_status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function add(string $trade, string $task, ?float $headcount = null, array $extra = []): array
    {
        $res = $this->svc()->save(array_merge([
            'siteId' => $this->site->id, 'trade' => $trade, 'task' => $task, 'headcount' => $headcount,
        ], $extra), '703K');
        $this->assertTrue($res['success'], json_encode($res, JSON_UNESCAPED_UNICODE));

        return $res;
    }

    public function test_the_week_is_grouped_by_trade_in_the_fields_own_words(): void
    {
        $this->manager();
        $this->add('배관', '급탕 배관', 2);
        $this->add('전기', '후드 배선', 3);
        $this->add('배관', '그리스 트랩 설치', 1);

        $board = $this->svc()->board('703K');

        $this->assertTrue($board['success']);
        $this->assertSame('2026-09-21', $board['weekStart'], '주는 월요일로 시작한다.');
        $this->assertTrue($board['isThisWeek']);
        $this->assertSame(['배관', '전기'], array_column($board['groups'], 'trade'), '공종별로 묶이고 가나다순이다.');
        $this->assertSame(['급탕 배관', '그리스 트랩 설치'], array_column($board['groups'][0]['lines'], 'task'));
        $this->assertSame(3.0, $board['groups'][0]['plannedHeadcount']);
    }

    public function test_a_line_needs_a_trade_and_a_task_and_nothing_else(): void
    {
        // 코드도, 날짜도, 선행도 묻지 않는다. 그것이 이 표의 이유다.
        $this->manager();

        $res = $this->svc()->save(['siteId' => $this->site->id, 'trade' => '', 'task' => ''], '703K');

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('trade', $res['errors']);
        $this->assertArrayHasKey('task', $res['errors']);
    }

    public function test_status_is_one_press_and_blocked_keeps_its_reason(): void
    {
        $this->manager();
        $id = $this->add('배관', '급탕 배관', 2)['id'];

        $this->assertTrue($this->svc()->setStatus($id, 'doing')['success']);
        $this->assertTrue($this->svc()->setStatus($id, 'blocked', '자재 미입고')['success']);

        $line = $this->svc()->board('703K')['groups'][0]['lines'][0];
        $this->assertSame('못함', $line['statusLabel']);
        $this->assertSame('자재 미입고', $line['reason']);

        $this->assertTrue($this->svc()->setStatus($id, 'done')['success']);
        $line = $this->svc()->board('703K')['groups'][0]['lines'][0];
        $this->assertSame('done', $line['status']);
        $this->assertNull($line['reason'], '완료된 줄에 못한 이유가 남아 있으면 안 된다.');
        $this->assertNotNull($line['doneAt']);
    }

    public function test_unfinished_lines_carry_over_to_the_next_week_once(): void
    {
        $this->manager();
        $done = $this->add('배관', '급탕 배관', 2, ['week' => '2026-09-14'])['id'];
        $this->add('전기', '후드 배선', 3, ['week' => '2026-09-14']);
        $this->svc()->setStatus($done, 'done');

        $this->assertSame(1, $this->svc()->board('703K')['leftoverCount'], '지난주에 못 끝낸 줄이 몇 개인지 미리 보인다.');

        $this->assertSame(1, $this->svc()->carryOver('703K')['moved']);
        $this->assertSame(0, $this->svc()->carryOver('703K')['moved'], '두 번 눌러도 두 번 넘어오지 않는다.');

        $board = $this->svc()->board('703K');
        $this->assertSame(1, $board['total']);
        $this->assertSame('후드 배선', $board['groups'][0]['lines'][0]['task']);
        $this->assertTrue($board['groups'][0]['lines'][0]['carried'], '어디서 넘어왔는지 보여야 한다.');
        $this->assertSame('planned', $board['groups'][0]['lines'][0]['status']);
    }

    public function test_todays_clock_ins_stand_next_to_the_planned_headcount(): void
    {
        // 계획 옆에 현실. «배관 2명 계획» 옆에 «오늘 출근 1명» 이 보여야 아침에 조정한다.
        $this->manager();
        $plumber = $this->worker('배관', 'P1');
        $this->worker('배관', 'P2');
        $this->add('배관', '급탕 배관', 2);

        $moment = Carbon::parse('2026-09-23 07:00:00', $this->site->timezone);
        AttendanceLog::create([
            'employee_id' => $plumber->id, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'attendance_date' => '2026-09-23', 'event_type' => 'clock_in',
            'event_at' => $moment->copy()->setTimezone(config('app.timezone')), 'source' => 'gate_qr', 'status' => 'approved',
        ]);

        $group = $this->svc()->board('703K')['groups'][0];

        $this->assertSame(2.0, $group['plannedHeadcount']);
        $this->assertSame(1, $group['presentToday']);
    }

    public function test_trade_choices_are_the_sites_employee_trades(): void
    {
        // 일일보고와 같은 낱말. 여기서 다른 이름을 쓰면 「전기」 와 「전기/배관」 이 두 공종이 된다.
        $this->manager();
        $this->worker('배관');
        $this->worker('전기');
        Employee::create(['name' => 'Office', 'position' => 'office', 'employment_type' => Employee::TYPE_STAFF,
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'employment_status' => 'active']);
        $this->add('용접', '후드 프레임 제작', 1);

        $this->assertSame(['배관', '용접', '전기'], $this->svc()->board('703K')['tradeOptions'],
            '직원 공종 + 이미 적힌 공종. 사무 같은 부서는 공종이 아니다.');
    }

    public function test_finishing_a_linked_line_completes_the_schedule_activity(): void
    {
        // 정식 공정표에 붙여 둔 줄이 끝나면 그쪽도 끝난다 — 진척률은 그렇게 올라간다.
        $this->manager();
        $stage = WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'stage', 'wbs_code' => '703K-KITCHEN-S-1', 'name' => '배관', 'site_id' => $this->site->id]);
        $task = WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'task', 'wbs_code' => '703K-KITCHEN-T-1.1', 'name' => '급탕', 'parent_id' => $stage->id, 'site_id' => $this->site->id]);
        $act = WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'subtask', 'wbs_code' => '703K-KITCHEN-W-P010', 'activity_id' => 'P010', 'name' => '급탕 배관', 'parent_id' => $task->id, 'site_id' => $this->site->id, 'status' => '진행중']);
        $other = WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'subtask', 'wbs_code' => '703K-KITCHEN-W-P020', 'activity_id' => 'P020', 'name' => '급수 배관', 'parent_id' => $task->id, 'site_id' => $this->site->id, 'status' => '진행중']);

        $id = $this->add('배관', '급탕 배관', 2, ['wbsCodes' => '703K-KITCHEN-W-P010'])['id'];
        $res = $this->svc()->setStatus($id, 'done');

        $this->assertTrue($res['success']);
        $this->assertNull($res['warning'], $res['warning'] ?? '');
        $this->assertSame(WbsItem::STATUS_DONE, $act->fresh()->status);
        $this->assertSame('진행중', $other->fresh()->status, '붙이지 않은 액티비티는 건드리지 않는다.');
    }

    public function test_an_unlinked_line_leaves_the_schedule_alone(): void
    {
        // 작업판은 작업판, 공정표는 공정표. 둘을 맞추는 동기화 층은 없다.
        $this->manager();
        $act = WbsItem::create(['project_code' => '703K-KITCHEN', 'level' => 'subtask', 'wbs_code' => '703K-KITCHEN-W-P010', 'name' => '급탕 배관', 'site_id' => $this->site->id, 'status' => '진행중']);

        $id = $this->add('배관', '급탕 배관', 2)['id'];
        $this->svc()->setStatus($id, 'done');

        $this->assertSame('진행중', $act->fresh()->status);
    }

    public function test_another_sites_board_is_out_of_reach(): void
    {
        $this->manager();
        $this->add('배관', '급탕 배관', 2);

        $other = Site::create(['code' => 'PHX1', 'name' => 'Phoenix', 'country' => 'US',
            'timezone' => 'America/Phoenix', 'status' => 'active', 'company_id' => $this->company->id]);
        $this->manager(['access_scope' => 'site', 'allowed_site_id' => $other->id]);

        $this->assertSame(0, $this->svc()->board('PHX1')['total']);
        $this->assertNull($this->svc()->board('703K')['siteId'] ?? null, '남의 현장은 아예 열리지 않는다.');
        $this->assertFalse($this->svc()->save(['siteId' => $this->site->id, 'trade' => '배관', 'task' => 'x'], '703K')['success']);
    }

    public function test_a_worker_can_look_but_not_write(): void
    {
        $this->manager();
        $this->add('배관', '급탕 배관', 2);
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'access_scope' => 'all_sites', 'account_status' => 'active']));

        $board = $this->svc()->board('703K');
        $this->assertTrue($board['success']);
        $this->assertFalse($board['canManage']);
        $this->assertFalse($this->svc()->save(['siteId' => $this->site->id, 'trade' => '배관', 'task' => 'x'], '703K')['success']);
    }

    public function test_the_week_follows_the_site_clock_not_the_server_clock(): void
    {
        // 사바나 월요일 00:30 은 피닉스 일요일 21:30 이다. 서버 시계로 주를 정하면 그 30분에
        // 적은 줄이 지난주로 들어간다.
        $this->manager();
        Carbon::setTestNow(Carbon::parse('2026-09-21 00:30:00', 'America/New_York'));

        $this->assertSame('2026-09-21', $this->svc()->board('703K')['weekStart']);
    }

    public function test_the_api_exposes_the_board(): void
    {
        $this->manager();
        $this->add('배관', '급탕 배관', 2);

        $this->postJson('/smart-company-api/api_getWeekBoard', ['args' => [null], 'siteId' => '703K'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('total', 1);
    }
}
