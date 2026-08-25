<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OpsIntakeItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\CommunicationService;
use App\Services\Ops\OpsDigestService;
use App\Jobs\ReadOpsRoomMessageJob;
use App\Services\Ops\OpsLearningService;
use App\Services\Ops\OpsRoomAutoReader;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 현장 상황실 3~5단계 — 방 자동 판독 / 하루 다이제스트 / 학습.
 */
class OpsRoomAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'ABC ENG', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'LG-PH', 'name' => 'LG Phoenix', 'status' => 'active']);

        Project::firstOrCreate(['project_code' => 'LG-01'], ['name' => 'LG', 'construction_type' => 'equipment_setting']);
        WbsItem::create([
            'project_code' => 'LG-01', 'site_id' => $this->site->id, 'level' => 'subtask',
            'wbs_code' => 'LG-01-W-A100', 'activity_id' => 'A100', 'node_no' => '1.1',
            'name' => '천장 전기 배관', 'status' => '검수완료', 'crew_size' => 3,
            'planned_start' => now()->toDateString(), 'planned_end' => now()->addDays(2)->toDateString(),
        ]);

        $emp = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id, 'name' => '김철수',
            'first_name' => '김철수', 'last_name' => '', 'email' => 'kim@x.com', 'employment_status' => 'active',
        ]);
        $this->user = User::factory()->create([
            'email' => 'kim@x.com', 'employee_id' => $emp->id,
            'access_role' => 'site_manager', 'account_status' => 'active',
        ]);
    }

    private function fakeAi(array $items): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items])]]]]],
            ]),
        ]);
    }

    private function opsRoom(): CommunicationRoom
    {
        app(CommunicationService::class)->ensureSiteRooms($this->site);

        return CommunicationRoom::where('site_id', $this->site->id)
            ->where('type', CommunicationRoom::TYPE_SITE_OPS)->firstOrFail();
    }

    private function progressItem(array $over = []): array
    {
        return array_merge([
            'raw_text' => '천장 배관 12개 했습니다', 'speaker' => '김철수', 'category' => 'progress',
            'confidence' => 90, 'summary' => '천장 전기 배관 진행률 60%', 'target_type' => 'wbs',
            'target_code' => 'LG-01-W-A100', 'target_name' => '천장 전기 배관',
            'occurred_on' => now()->toDateString(), 'proposed' => ['progress' => 60], 'question' => '',
        ], $over);
    }

    // ── 3단계: 방 자동 판독 ────────────────────────────────

    /** 상황실 글쓰기가 판독 잡을 예약하는지(배선). 실제 판독은 응답 후에 돌아간다. */
    public function test_posting_in_ops_room_schedules_the_reader(): void
    {
        Bus::fake();
        $room = $this->opsRoom();

        app(CommunicationService::class)->postMessage($this->user, $room, '천장 배관 12개 했습니다');

        Bus::assertDispatchedAfterResponse(ReadOpsRoomMessageJob::class);
    }

    public function test_other_rooms_do_not_schedule_the_reader(): void
    {
        Bus::fake();
        app(CommunicationService::class)->ensureSiteRooms($this->site);
        $chat = CommunicationRoom::where('site_id', $this->site->id)
            ->where('type', CommunicationRoom::TYPE_SITE_CHAT)->firstOrFail();

        app(CommunicationService::class)->postMessage($this->user, $chat, '천장 배관 12개 했습니다');

        Bus::assertNotDispatchedAfterResponse(ReadOpsRoomMessageJob::class);
    }

    public function test_reader_parses_the_message_and_replies(): void
    {
        $this->fakeAi([$this->progressItem()]);
        $room = $this->opsRoom();
        $msg = app(CommunicationService::class)->postMessage($this->user, $room, '천장 배관 12개 했습니다');

        app(OpsRoomAutoReader::class)->handle($msg->fresh());

        $item = OpsIntakeItem::where('source', 'room')->first();
        $this->assertNotNull($item, '상황실 글은 판독돼야 한다.');
        $this->assertSame('LG-01-W-A100', $item->target_code);

        $reply = CommunicationMessage::where('kind', CommunicationMessage::KIND_SYSTEM)->first();
        $this->assertNotNull($reply);
        $this->assertStringContainsString('읽었습니다', $reply->body);
        $this->assertSame('ops_ai_reply', $reply->payload['bot']);
    }

    public function test_reader_ignores_its_own_reply(): void
    {
        $this->fakeAi([$this->progressItem()]);
        $room = $this->opsRoom();
        $msg = app(CommunicationService::class)->postMessage($this->user, $room, '천장 배관 12개 했습니다');
        app(OpsRoomAutoReader::class)->handle($msg->fresh());

        $reply = CommunicationMessage::where('kind', CommunicationMessage::KIND_SYSTEM)->firstOrFail();
        app(OpsRoomAutoReader::class)->handle($reply);  // 답글을 다시 넣어도

        $this->assertSame(1, OpsIntakeItem::where('source', 'room')->count(), '무한 루프가 나면 안 된다.');
    }

    public function test_reader_skips_messages_from_other_rooms(): void
    {
        $this->fakeAi([$this->progressItem()]);
        app(CommunicationService::class)->ensureSiteRooms($this->site);
        $chat = CommunicationRoom::where('site_id', $this->site->id)
            ->where('type', CommunicationRoom::TYPE_SITE_CHAT)->firstOrFail();
        $msg = app(CommunicationService::class)->postMessage($this->user, $chat, '천장 배관 12개 했습니다');

        app(OpsRoomAutoReader::class)->handle($msg->fresh());

        $this->assertSame(0, OpsIntakeItem::count(), '일반 현장방 대화는 판독 대상이 아니다.');
    }

    public function test_small_talk_gets_no_reply(): void
    {
        $this->fakeAi([[
            'raw_text' => '점심 뭐 먹지', 'speaker' => '', 'category' => 'noise', 'confidence' => 99,
            'summary' => '잡담', 'target_type' => '', 'target_code' => '', 'target_name' => '',
            'occurred_on' => '', 'proposed' => [], 'question' => '',
        ]]);
        $room = $this->opsRoom();
        $msg = app(CommunicationService::class)->postMessage($this->user, $room, '점심 뭐 먹지');

        app(OpsRoomAutoReader::class)->handle($msg->fresh());

        $this->assertSame(0, CommunicationMessage::where('kind', CommunicationMessage::KIND_SYSTEM)->count(),
            '잡담에는 답글을 달아 방을 어지럽히지 않는다.');
    }

    // ── 4단계: 하루 다이제스트 ─────────────────────────────

    public function test_digest_counts_the_days_work(): void
    {
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'a', 'category' => 'progress', 'status' => 'applied', 'summary' => 'x']);
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'b', 'category' => 'plan', 'status' => 'pending', 'summary' => 'y']);
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'c', 'category' => 'progress', 'status' => 'needs_input', 'summary' => 'z', 'question' => '어느 작업?']);
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'd', 'category' => 'noise', 'status' => 'ignored']);

        $s = app(OpsDigestService::class)->summary($this->site->id);

        $this->assertSame(4, $s['parsed']);
        $this->assertSame(3, $s['actionable']);
        $this->assertSame(1, $s['applied']);
        $this->assertSame(1, $s['needsInput']);
        $this->assertSame(1, $s['noise']);
        $this->assertSame('어느 작업?', $s['needsItems'][0]['question']);
    }

    public function test_digest_posts_to_room_and_notifies_managers(): void
    {
        $this->opsRoom();
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'a', 'category' => 'progress', 'status' => 'applied', 'summary' => '진행률 반영']);

        $r = app(OpsDigestService::class)->dispatchDigest();

        $this->assertSame(1, $r['posted']);
        $digest = CommunicationMessage::where('title', 'like', '%하루 요약%')->first();
        $this->assertNotNull($digest);
        $this->assertStringContainsString('반영 1건', $digest->body);
        // 방 멤버인 관리자는 방 게시로 받는다 — 종(개인 알림)까지 울리면 같은 요약이
        // 두 번 온다(연계 점검). 방에 없는 관리자에게만 개인 알림이 간다.
        $this->assertSame($r['notified'], CommunicationNotification::where('type', 'ops_digest')->count());
        $memberUserIds = \App\Models\CommunicationRoomMember::query()
            ->whereNotNull('user_id')->pluck('user_id')->all();
        $this->assertSame(
            0,
            CommunicationNotification::where('type', 'ops_digest')->whereIn('user_id', $memberUserIds)->count(),
            '방 멤버가 개인 알림까지 받으면 이중 수신이다'
        );
    }

    public function test_digest_is_silent_when_nothing_was_captured(): void
    {
        $this->opsRoom();

        $r = app(OpsDigestService::class)->dispatchDigest();

        $this->assertSame(0, $r['posted'], '건진 게 없으면 조용해야 한다.');
    }

    public function test_digest_command_runs(): void
    {
        $this->opsRoom();
        OpsIntakeItem::create(['site_id' => $this->site->id, 'raw_text' => 'a', 'category' => 'issue', 'status' => 'pending', 'summary' => '이슈']);

        $this->artisan('ops:digest')->assertExitCode(0);

        $this->assertSame(1, CommunicationMessage::where('title', 'like', '%하루 요약%')->count());
    }

    // ── 5단계: 학습 ────────────────────────────────────────

    public function test_glossary_learns_name_to_code_from_applied_items(): void
    {
        foreach ([1, 2, 3] as $_) {
            OpsIntakeItem::create([
                'site_id' => $this->site->id, 'raw_text' => 'x', 'category' => 'progress', 'status' => 'applied',
                'summary' => 's', 'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100', 'target_name' => '천장배관',
            ]);
        }

        $g = app(OpsLearningService::class)->glossary($this->site->id);

        $this->assertSame('천장배관', $g[0]['name']);
        $this->assertSame('LG-01-W-A100', $g[0]['code']);
        $this->assertSame(3, $g[0]['hits'], '자주 확인된 표현일수록 위로 와야 한다.');
    }

    public function test_dismissed_items_become_correction_examples(): void
    {
        OpsIntakeItem::create([
            'site_id' => $this->site->id, 'raw_text' => '배관 밀렸어요', 'category' => 'progress',
            'status' => 'dismissed', 'summary' => '엉뚱한 작업으로 읽음',
        ]);

        $c = app(OpsLearningService::class)->corrections($this->site->id);

        $this->assertSame('배관 밀렸어요', $c[0]['raw']);
        $this->assertSame('엉뚱한 작업으로 읽음', $c[0]['wrong']);
    }

    public function test_prompt_block_is_empty_before_any_learning(): void
    {
        $this->assertSame('', app(OpsLearningService::class)->promptBlock($this->site->id));
    }

    public function test_learned_knowledge_is_sent_to_the_ai(): void
    {
        OpsIntakeItem::create([
            'site_id' => $this->site->id, 'raw_text' => 'x', 'category' => 'progress', 'status' => 'applied',
            'summary' => 's', 'target_type' => 'wbs', 'target_code' => 'LG-01-W-A100', 'target_name' => '천장배관',
        ]);
        $block = app(OpsLearningService::class)->promptBlock($this->site->id);

        $this->assertStringContainsString('현장 용어사전', $block);
        $this->assertStringContainsString('"천장배관" → LG-01-W-A100', $block);

        // 실제 판독 요청에 이 블록이 실려 나가는지 확인.
        $this->fakeAi([$this->progressItem()]);
        app(\App\Services\Ops\OpsIntakeService::class)->ingest('천장배관 끝', $this->site);

        // 요청 본문은 유니코드 이스케이프로 직렬화되므로, 디코딩해서 확인한다.
        Http::assertSent(function ($request): bool {
            $json = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains((string) $json, '현장 용어사전')
                && str_contains((string) $json, 'LG-01-W-A100');
        });
    }
}
