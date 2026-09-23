<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\OpsIntakeItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Communication\CommunicationService;
use App\Services\Ops\OpsDigestService;
use App\Services\Wbs\WeekBoardService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 매일 저녁 K-TALK 로 작업판 한 줄 요약(사장 지시).
 *
 * 저녁 메시지를 하나 더 만들지 않는다 — 이미 18:00(현장 시간)에 상황실 방으로 가는 하루
 * 요약의 <b>맨 위</b>에 작업판 한 줄을 싣는다. 상황실이 조용한 날도 작업판에 줄이 있으면
 * 그 한 줄은 간다. 판이 비어 있으면 없는 판을 요약하지 않는다.
 */
class WeekBoardEveningDigestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        Carbon::setTestNow('2026-09-23 15:00:00');   // 앱 시계(피닉스) 15:00 = 사바나 수요일 18:00, 요약이 가는 시각

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(string $trade, string $task, string $status = 'planned', array $extra = []): WeekBoardLine
    {
        return WeekBoardLine::create($extra + [
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'week_start' => '2026-09-21',
            'trade' => $trade, 'task' => $task, 'status' => $status,
        ]);
    }

    private function opsRoom(): CommunicationRoom
    {
        app(CommunicationService::class)->ensureSiteRooms($this->site);

        return CommunicationRoom::where('site_id', $this->site->id)->where('type', CommunicationRoom::TYPE_SITE_OPS)->firstOrFail();
    }

    public function test_the_one_line_counts_the_week_and_names_why_things_were_blocked(): void
    {
        $this->line('배관', '급탕 배관', 'done', ['done_at' => Carbon::now()]);                      // 오늘 완료
        $this->line('배관', '그리스 트랩', 'done', ['done_at' => Carbon::parse('2026-09-22 15:00')]); // 어제 완료
        $this->line('전기', '후드 배선', 'doing', ['auto_at' => Carbon::now(), 'auto_source' => '상황실 09/23']);
        $this->line('덕트', '주방 덕트', 'blocked', ['reason' => '자재 미입고']);
        $this->line('덕트', '배기 덕트', 'blocked', ['reason' => '앞 공정 안 끝남']);
        $this->line('기계', '후드 설치');

        $s = app(WeekBoardService::class)->daySummary($this->site, '2026-09-23');

        $this->assertSame('📋 작업판 9/23 — 6줄: ✅ 완료 2 (오늘 1) · 🔧 진행중 1 · ⛔ 못함 2 (자재 미입고, 앞 공정 안 끝남) · 예정 1 · 🤖 상황실 글로 자동 1', $s['line']);
        $this->assertSame(6, $s['total']);
        $this->assertSame(1, $s['doneToday']);
    }

    public function test_an_empty_board_has_no_summary(): void
    {
        $this->line('배관', '급탕 배관', 'planned', ['week_start' => '2026-09-14']);   // 지난주 줄뿐

        $this->assertNull(app(WeekBoardService::class)->daySummary($this->site, '2026-09-23'));
    }

    public function test_the_evening_digest_carries_the_board_line_even_when_the_room_was_quiet(): void
    {
        $this->opsRoom();
        $this->line('배관', '급탕 배관', 'done', ['done_at' => Carbon::now()]);
        $this->line('덕트', '주방 덕트', 'blocked', ['reason' => '자재 미입고']);

        $r = app(OpsDigestService::class)->dispatchDigest(Carbon::parse('2026-09-23'));

        $this->assertSame(1, $r['posted'], '상황실이 조용해도 작업판 한 줄은 간다');
        $digest = CommunicationMessage::where('title', 'like', '%하루 요약%')->firstOrFail();
        $this->assertStringStartsWith('📋 작업판 9/23 — 2줄: ✅ 완료 1 (오늘 1)', $digest->body, '작업판 한 줄이 맨 위');
        $this->assertStringContainsString('⛔ 못함 1 (자재 미입고)', $digest->body);
        $this->assertStringContainsString('오늘 상황실은 조용했습니다.', $digest->body);

        $note = CommunicationNotification::where('type', 'ops_digest')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('[작업판 요약] 703K · 완료 1 · 못함 1', $note->title);
        $this->assertStringStartsWith('📋 작업판', $note->body);
    }

    public function test_the_board_line_sits_above_the_ops_room_numbers(): void
    {
        $this->opsRoom();
        $this->line('배관', '급탕 배관', 'doing');
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'a', 'category' => 'issue', 'status' => 'applied', 'summary' => '이슈']);

        app(OpsDigestService::class)->dispatchDigest(Carbon::parse('2026-09-23'));

        $body = CommunicationMessage::where('title', 'like', '%하루 요약%')->firstOrFail()->body;
        $this->assertStringStartsWith('📋 작업판 9/23 — 1줄: ✅ 완료 0 · 🔧 진행중 1', $body);
        $this->assertStringContainsString('✅ 반영 1건', $body, '상황실 숫자는 그대로 뒤에 온다');
        $this->assertStringContainsString('[상황실 요약]', CommunicationNotification::where('type', 'ops_digest')->firstOrFail()->title);
    }

    public function test_no_board_and_no_room_activity_stays_quiet(): void
    {
        $this->opsRoom();

        $r = app(OpsDigestService::class)->dispatchDigest(Carbon::parse('2026-09-23'));

        $this->assertSame(0, $r['posted']);
        $this->assertSame(0, CommunicationMessage::where('title', 'like', '%하루 요약%')->count());
    }
}
