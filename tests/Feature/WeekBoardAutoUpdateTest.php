<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Communication\CommunicationService;
use App\Services\Ocr\OcrEngine;
use App\Services\Ops\OpsIntakeAnalyzer;
use App\Services\Ops\OpsIntakeService;
use App\Services\Ops\OpsRoomAutoReader;
use App\Services\Wbs\WeekBoardReflector;
use App\Services\Wbs\WeekBoardService;
use App\Support\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 상황실 글 → 이번 주 작업판 자동 갱신.
 *
 * 사장 지시: 「상황실 글·사진으로 작업판 자동 갱신도 해줘」. 반장이 상황실에 올린 한마디가
 * 작업판의 그 줄을 움직인다. 이 시험이 지키는 것 — 말이 있어야 움직인다(사진만으로는 안
 * 움직인다), 앞으로만 간다(사람이 완료로 둔 줄·확신 낮은 판단은 건드리지 않는다), 흔적을
 * 남긴다(어느 글의 어느 문장인지), 사람이 누르면 사람이 이긴다.
 */
class WeekBoardAutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private User $user;

    /** @var array<int, string> */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        SiteClock::forget();
        Carbon::setTestNow('2026-09-23 10:00:00');   // 수요일 — 이번 주 월요일은 9/21

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id,
        ]);
        $emp = Employee::create(['name' => '김반장', 'role' => '배관', 'company_id' => $this->company->id,
            'site_id' => $this->site->id, 'employment_status' => 'active', 'email' => 'kim@x.com']);
        $this->user = User::factory()->create(['name' => '김반장', 'email' => 'kim@x.com', 'employee_id' => $emp->id,
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(string $trade, string $task, string $status = 'planned', string $week = '2026-09-21'): WeekBoardLine
    {
        return WeekBoardLine::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id, 'week_start' => $week,
            'trade' => $trade, 'task' => $task, 'status' => $status,
            'done_at' => $status === 'done' ? Carbon::now() : null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function batch(string $text, array $extra = []): OpsIntakeBatch
    {
        return OpsIntakeBatch::create($extra + [
            'site_id' => $this->site->id, 'created_by_id' => $this->user->id, 'source' => 'room',
            'raw_text' => $text, 'image_count' => 0, 'status' => 'done',
        ]);
    }

    /**
     * 작업판 비서 대신 — 프롬프트를 기록하고 정해진 판단을 돌려준다. 부른 횟수도 센다.
     *
     * @param  array<int, array<string, mixed>>  $marks
     */
    private function fakeEngine(array $marks, int &$calls = 0): void
    {
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andReturnUsing(function (array $images, string $prompt) use ($marks, &$calls): array {
            $calls++;
            $this->prompts[] = $prompt;

            return ['data' => ['marks' => $marks], 'model' => 'fake'];
        });
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
    }

    // ── 움직인다 ──────────────────────────────────────────────────────

    public function test_a_field_post_moves_the_lines_it_talks_about_and_leaves_a_trail(): void
    {
        $hot = $this->line('배관', '급탕 배관');
        $trap = $this->line('배관', '그리스 트랩 설치');
        $hood = $this->line('전기', '후드 배선');
        $this->fakeEngine([
            ['id' => $hot->id, 'status' => 'done', 'quote' => '급탕 배관 오늘 다 끝냈습니다', 'confidence' => 92],
            ['id' => $trap->id, 'status' => 'doing', 'quote' => '그리스 트랩은 반쯤', 'confidence' => 80],
            ['id' => $hood->id, 'status' => 'none', 'confidence' => 0],
        ]);

        $batch = $this->batch('급탕 배관 오늘 다 끝냈습니다. 그리스 트랩은 반쯤 했어요.');
        $out = app(WeekBoardReflector::class)->reflect($batch);

        $this->assertSame(2, $out['updated']);
        $this->assertSame(['급탕 배관' => '완료', '그리스 트랩 설치' => '진행중'], collect($out['lines'])->pluck('statusLabel', 'task')->all());

        $hot->refresh();
        $this->assertSame('done', $hot->status);
        $this->assertNotNull($hot->done_at);
        $this->assertSame('상황실 09/23 김반장', $hot->auto_source, '누구의 어느 날 글인지');
        $this->assertSame('급탕 배관 오늘 다 끝냈습니다', $hot->auto_quote, '어느 문장을 듣고 바꿨는지');
        $this->assertSame($batch->id, $hot->auto_batch_id);
        $this->assertNotNull($hot->auto_at);
        $this->assertSame('doing', $trap->refresh()->status);
        $this->assertSame('planned', $hood->refresh()->status);
        $this->assertNull($hood->auto_source);
        $this->assertSame(2, $batch->refresh()->week_board_updated);

        $this->assertStringContainsString('급탕 배관', $this->prompts[0]);
        $this->assertStringContainsString('추측 금지', $this->prompts[0]);
        $this->assertStringContainsString('done 은 끝났다는 말이 있을 때만', $this->prompts[0]);
    }

    public function test_blocked_keeps_the_reason_the_post_gave(): void
    {
        $duct = $this->line('덕트', '주방 덕트', 'doing');
        $this->fakeEngine([['id' => $duct->id, 'status' => 'blocked', 'reason' => '자재 미입고', 'quote' => '덕트 자재가 안 와서 오늘 못 했습니다', 'confidence' => 85]]);

        app(WeekBoardReflector::class)->reflect($this->batch('덕트 자재가 안 와서 오늘 못 했습니다'));

        $duct->refresh();
        $this->assertSame('blocked', $duct->status);
        $this->assertSame('자재 미입고', $duct->reason);
    }

    // ── 안 움직인다 ───────────────────────────────────────────────────

    public function test_lines_a_person_finished_and_unsure_judgements_are_left_alone(): void
    {
        $doneByHuman = $this->line('배관', '급탕 배관', 'done');
        $unsure = $this->line('전기', '후드 배선');
        $same = $this->line('덕트', '주방 덕트', 'doing');
        $this->fakeEngine([
            ['id' => $doneByHuman->id, 'status' => 'doing', 'quote' => '급탕 다시 봅니다', 'confidence' => 95],   // 완료를 되돌리려 함
            ['id' => $unsure->id, 'status' => 'done', 'quote' => '후드 쪽도 뭐', 'confidence' => 40],           // 확신 낮음
            ['id' => $same->id, 'status' => 'doing', 'quote' => '덕트 하는 중', 'confidence' => 90],          // 이미 그 상태
        ]);

        $out = app(WeekBoardReflector::class)->reflect($this->batch('급탕 다시 봅니다. 후드 쪽도 뭐. 덕트 하는 중.'));

        $this->assertSame(0, $out['updated']);
        $this->assertSame('done', $doneByHuman->refresh()->status, '사람이 완료로 둔 줄은 AI 가 되돌리지 않는다');
        $this->assertStringNotContainsString('급탕 배관', $this->prompts[0], '끝난 줄은 AI 에게 보여 주지도 않는다');
        $this->assertSame('planned', $unsure->refresh()->status);
        $this->assertNull($unsure->auto_source);
        $this->assertNull($same->refresh()->auto_source, '바뀐 것이 없으면 흔적도 없다');
    }

    public function test_a_photo_only_post_does_not_move_the_board(): void
    {
        $this->line('배관', '급탕 배관');
        $calls = 0;
        $this->fakeEngine([], $calls);

        $out = app(WeekBoardReflector::class)->reflect($this->batch('', ['image_count' => 2,
            'photo_kinds' => [['kind' => 'construction', 'label' => '시공 사진', 'summary' => '배관 작업']]]));

        $this->assertSame(0, $out['updated']);
        $this->assertSame(0, $calls, '사진에는 맥락이 없다 — AI 를 부르지도 않는다');
        $this->assertSame('planned', WeekBoardLine::query()->first()->status);
    }

    public function test_a_site_with_no_lines_this_week_does_not_call_the_ai(): void
    {
        $this->line('배관', '급탕 배관', 'planned', '2026-09-14');   // 지난주 줄뿐
        $calls = 0;
        $this->fakeEngine([], $calls);

        app(WeekBoardReflector::class)->reflect($this->batch('급탕 배관 끝났습니다'));

        $this->assertSame(0, $calls);
    }

    public function test_a_persons_button_beats_the_automatic_mark_and_clears_the_trail(): void
    {
        $hot = $this->line('배관', '급탕 배관');
        $this->fakeEngine([['id' => $hot->id, 'status' => 'done', 'quote' => '급탕 끝', 'confidence' => 90]]);
        app(WeekBoardReflector::class)->reflect($this->batch('급탕 끝'));
        $this->assertSame('done', $hot->refresh()->status);

        $res = app(WeekBoardService::class)->setStatus($hot->id, 'doing');

        $this->assertTrue($res['success']);
        $hot->refresh();
        $this->assertSame('doing', $hot->status);
        $this->assertNull($hot->auto_source, '사람이 누르면 자동 흔적은 지워진다');
        $this->assertNull($hot->auto_quote);
        $this->assertSame($this->user->id, $hot->updated_by_id);

        $row = collect(app(WeekBoardService::class)->board('703K')['groups'])->flatMap(fn ($g) => $g['lines'])->firstWhere('id', $hot->id);
        $this->assertNull($row['auto']);
    }

    // ── 상황실 판독의 한 단계로 ───────────────────────────────────────

    public function test_ops_room_analysis_updates_the_board_and_the_phone_hears_about_it(): void
    {
        $hot = $this->line('배관', '급탕 배관');
        $this->swap(OpsIntakeAnalyzer::class, new class extends OpsIntakeAnalyzer
        {
            public function __construct() {}

            public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = [], array $specs = [], array $inspections = [], array $submittals = []): array
            {
                return [];
            }
        });
        $this->fakeEngine([['id' => $hot->id, 'status' => 'done', 'quote' => '급탕 배관 끝났습니다', 'confidence' => 90]]);

        $batch = $this->batch('급탕 배관 끝났습니다', ['status' => 'analyzing']);
        app(OpsIntakeService::class)->analyze($batch->id);

        $this->assertSame('done', $hot->refresh()->status);
        $this->assertSame('done', $batch->refresh()->status);
        $this->assertSame(1, $batch->week_board_updated);
        $this->assertSame(1, app(OpsIntakeService::class)->job($batch->id)['weekBoardUpdated']);
    }

    public function test_a_board_failure_never_breaks_the_ops_room_reading(): void
    {
        $this->line('배관', '급탕 배관');
        $this->swap(OpsIntakeAnalyzer::class, new class extends OpsIntakeAnalyzer
        {
            public function __construct() {}

            public function read(string $text, array $activities, array $purchases, string $today, array $images = [], string $learned = '', array $photoKinds = [], array $specs = [], array $inspections = [], array $submittals = []): array
            {
                return [];
            }
        });
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andThrow(new \RuntimeException('AI 응답 없음'));
        $this->app->instance(OcrEngine::class, $engine);

        $batch = $this->batch('급탕 배관 끝났습니다', ['status' => 'analyzing']);
        app(OpsIntakeService::class)->analyze($batch->id);

        $this->assertSame('done', $batch->refresh()->status, '작업판은 부가 목적지다 — 실패해도 판독은 끝난다');
        $this->assertSame(0, $batch->week_board_updated);
    }

    public function test_the_room_reply_tells_the_foreman_which_line_moved(): void
    {
        $hot = $this->line('배관', '급탕 배관');
        // 상황실 판독기(제미나이 HTTP)는 아무 항목도 못 찾는다 — 작업판만 움직이는 경우.
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => []])]]]]],
        ])]);
        $this->fakeEngine([['id' => $hot->id, 'status' => 'done', 'quote' => '급탕 배관 끝났습니다', 'confidence' => 90]]);

        app(CommunicationService::class)->ensureSiteRooms($this->site);
        $room = CommunicationRoom::where('site_id', $this->site->id)->where('type', CommunicationRoom::TYPE_SITE_OPS)->firstOrFail();
        $msg = app(CommunicationService::class)->postMessage($this->user, $room, '급탕 배관 끝났습니다');

        app(OpsRoomAutoReader::class)->handle($msg->fresh());

        $this->assertSame('done', $hot->refresh()->status);
        $reply = CommunicationMessage::where('kind', CommunicationMessage::KIND_SYSTEM)->first();
        $this->assertNotNull($reply, '작업판이 움직였으면 그것만으로도 답한다');
        $this->assertStringContainsString('📋 작업판: 배관 · 급탕 배관 → 완료', $reply->body);
    }
}
