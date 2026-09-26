<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 폰은 필요한 때만 울린다 — "@이름" 으로 부르고, 방마다 알림을 고른다.
 *
 * 그동안은 방에 글이 올라오면 방 사람 전원의 폰이 울렸다. 30명 방이면 글 하나에 29대.
 * 그러면 사람들은 알림을 통째로 끄고, 정작 급한 지시가 묻힌다. 여기서 지키는 것:
 *   · 부른 사람은 확실히 울리고, 부르지 않은 사람은 각자 고른 대로.
 *   · 🚨 긴급은 알림을 꺼 둔 사람에게도 닿는다 — 끄는 것이 안전하려면 이 예외가 있어야 한다.
 *   · 나를 부른 것은 활동함에 모이고, 누르면 그 글로 간다.
 */
class ChatMentionsAndNotifyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private CommunicationRoom $room;
    private CommunicationService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.webpush.public_key' => 'test-public-key',
            'services.webpush.private_key' => 'test-private-key',
            'services.webpush.subject' => 'mailto:admin@example.com',
        ]);

        $this->chat = app(CommunicationService::class);
        $this->company = Company::create(['code' => 'MEN-CO', 'name' => 'Mention Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'MEN', 'name' => '현장', 'status' => 'active']);
        $this->room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장 채팅방', 'status' => 'active',
        ]);
    }

    private function person(string $name, string $role = 'worker', ?CommunicationRoom $room = null): User
    {
        static $n = 0;
        $n++;
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => $name, 'email' => "p{$n}@example.com", 'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id, 'email' => "p{$n}@example.com",
            'access_role' => $role, 'account_status' => 'active',
        ]);
        $this->chat->ensureRoomMember($room ?? $this->room, $employee);

        return $user;
    }

    /** 실제 발송 대신 "누구에게 무엇을 보내려 했는지" 만 붙잡는다. */
    private function captureSends(): object
    {
        $spy = new class
        {
            public array $calls = [];

            /** @return list<int> */
            public function rung(): array
            {
                return array_values(array_unique(array_merge(...array_map(fn ($c) => $c['users'], $this->calls ?: [['users' => []]]))));
            }
        };

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('available')->andReturn(true);
        $sender->shouldReceive('sendToUsers')->andReturnUsing(function ($users, $payload) use ($spy): int {
            $spy->calls[] = ['users' => array_map('intval', collect($users)->all()), 'payload' => $payload];

            return count(collect($users)->all());
        });
        $this->app->instance(WebPushSender::class, $sender);

        return $spy;
    }

    private function mentionsOf(User $user): int
    {
        return CommunicationNotification::query()
            ->where('employee_id', $user->employee_id)
            ->where('type', CommunicationNotification::TYPE_MENTION)
            ->count();
    }

    private function setLevel(User $user, string $level, ?CommunicationRoom $room = null): void
    {
        CommunicationRoomMember::query()
            ->where('communication_room_id', ($room ?? $this->room)->id)
            ->where('employee_id', $user->employee_id)
            ->update(['notify_level' => $level]);
    }

    // ── 부르기 ─────────────────────────────────────────────────────────

    public function test_calling_a_name_reaches_that_person_and_nobody_else(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $kim = $this->person('김철수');
        $lee = $this->person('이영희');

        $message = $this->chat->postMessage($boss, $this->room, '@김철수 3층 배관 압력시험 결과 올려 주세요');

        $this->assertSame(1, $this->mentionsOf($kim), '부른 사람에게 알림이 가지 않았습니다.');
        $this->assertSame(0, $this->mentionsOf($lee), '부르지 않은 사람에게도 알림이 갔습니다.');
        $this->assertSame(0, $this->mentionsOf($boss), '부른 사람 자신에게 알림이 갔습니다.');
        $this->assertSame('김철수', $message->payload['mentions'][0]['name']);
    }

    public function test_the_longest_name_wins_so_a_shorter_name_inside_it_is_not_called(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $kim = $this->person('김철수');
        $kimShort = $this->person('김철');

        $this->chat->postMessage($boss, $this->room, '@김철수 확인 부탁');
        $this->assertSame(1, $this->mentionsOf($kim));
        $this->assertSame(0, $this->mentionsOf($kimShort), '"@김철수" 안의 "김철" 까지 불렀습니다.');

        $this->chat->postMessage($boss, $this->room, '@김철 자재 왔어요');
        $this->assertSame(1, $this->mentionsOf($kimShort));
        $this->assertSame(1, $this->mentionsOf($kim), '"@김철" 로 김철수까지 불렀습니다.');
    }

    public function test_a_particle_after_the_name_still_counts(): void
    {
        // 현장에서 "@이영희님" 처럼 붙여 쓴다 — 띄어쓰기를 요구하면 안 쓰이는 기능이 된다.
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');

        $this->chat->postMessage($boss, $this->room, '@이영희님 내일 07시 출근');

        $this->assertSame(1, $this->mentionsOf($lee));
    }

    public function test_calling_everyone_is_for_leads_only(): void
    {
        $worker = $this->person('김철수');
        $lee = $this->person('이영희');
        $boss = $this->person('박반장', 'site_manager');

        // 작업자의 "@모두" 는 그냥 글자다 — 아무나 쓰면 알림 설정이 무의미해진다.
        $plain = $this->chat->postMessage($worker, $this->room, '@모두 점심 뭐 먹어요');
        $this->assertFalse((bool) ($plain->payload['mention_everyone'] ?? false));
        $this->assertSame(0, $this->mentionsOf($lee));

        $this->chat->postMessage($boss, $this->room, '@모두 13시 안전교육 집합');
        $this->assertSame(1, $this->mentionsOf($lee));
        $this->assertSame(1, $this->mentionsOf($worker));
        $this->assertSame(0, $this->mentionsOf($boss));
    }

    public function test_calling_ai_is_not_mistaken_for_a_person(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $this->person('Ai');

        $message = $this->chat->postMessage($boss, $this->room, '@AI 오늘 공정 알려줘');

        $this->assertArrayNotHasKey('mentions', (array) $message->payload);
    }

    // ── 누가 울리는가 ──────────────────────────────────────────────────

    public function test_a_mentions_only_member_rings_only_when_called(): void
    {
        $spy = $this->captureSends();
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $this->setLevel($lee, CommunicationRoom::NOTIFY_MENTIONS);

        $this->chat->postMessage($boss, $this->room, '3층 동편 배관 완료');
        $this->assertNotContains($lee->id, $spy->rung(), '"부를 때만" 으로 둔 사람이 평범한 글에 울렸습니다.');

        $spy->calls = [];
        $this->chat->postMessage($boss, $this->room, '@이영희 사진 좀 올려 주세요');
        $this->assertContains($lee->id, $spy->rung(), '이름을 불렀는데 울리지 않았습니다.');
        $this->assertStringStartsWith('@ ', $spy->calls[0]['payload']['title'], '나를 부른 알림은 제목에서 구분돼야 합니다.');
    }

    public function test_a_muted_member_stays_silent_but_urgent_breaks_through(): void
    {
        $spy = $this->captureSends();
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $this->setLevel($lee, CommunicationRoom::NOTIFY_NONE);

        $this->chat->postMessage($boss, $this->room, '@이영희 확인');
        $this->assertNotContains($lee->id, $spy->rung(), '알림을 끈 사람이 울렸습니다.');
        // 울리지는 않아도 활동함에는 남는다 — 끈 것은 소리이지 기록이 아니다.
        $this->assertSame(1, $this->mentionsOf($lee));

        $spy->calls = [];
        $this->chat->postMessage($boss, $this->room, '전원 대피 — 2층 가스 누출', ['priority' => 'urgent']);
        $this->assertContains($lee->id, $spy->rung(), '긴급이 알림을 끈 사람에게 닿지 않았습니다.');
    }

    public function test_the_company_room_starts_quiet(): void
    {
        // 회사 전원이 모인 방 — 글 대부분은 나에게 할 일을 주지 않는다.
        $spy = $this->captureSends();
        $companyRoom = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'type' => CommunicationRoom::TYPE_COMPANY,
            'name' => '회사방', 'status' => 'active',
        ]);
        $boss = $this->person('박반장', 'site_manager', $companyRoom);
        $lee = $this->person('이영희', 'worker', $companyRoom);

        $this->assertSame(CommunicationRoom::NOTIFY_MENTIONS, $this->chat->notifyLevelFor($lee, $companyRoom));

        $this->chat->postMessage($boss, $companyRoom, '이번 주 금요일 회식');
        $this->assertNotContains($lee->id, $spy->rung());

        $this->chat->postMessage($boss, $companyRoom, '@이영희 서류 제출해 주세요');
        $this->assertContains($lee->id, $spy->rung());
    }

    // ── 답글 ──────────────────────────────────────────────────────────

    public function test_a_reply_tells_the_people_in_that_thread(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $boss = $this->person('박반장', 'site_manager');

        $question = $this->chat->postMessage($lee, $this->room, '3층 슬리브 위치 도면이랑 달라요');
        $this->chat->postMessage($kim, $this->room, '저도 봤어요', ['parent_id' => $question->id]);
        $this->chat->postMessage($boss, $this->room, '설계사에 확인 중', ['parent_id' => $question->id]);

        $replies = fn (User $u): int => CommunicationNotification::query()
            ->where('employee_id', $u->employee_id)->where('type', CommunicationNotification::TYPE_REPLY)->count();

        $this->assertSame(2, $replies($lee), '질문한 사람이 답글 소식을 못 받았습니다.');
        $this->assertSame(1, $replies($kim), '먼저 답한 사람이 뒤이은 답글 소식을 못 받았습니다.');
        $this->assertSame(0, $replies($boss), '답글 쓴 사람 자신에게 알림이 갔습니다.');
    }

    public function test_a_reply_that_also_calls_the_name_arrives_once(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');

        $question = $this->chat->postMessage($lee, $this->room, '자재 언제 와요?');
        $this->chat->postMessage($kim, $this->room, '@이영희 내일 오전', ['parent_id' => $question->id]);

        $this->assertSame(1, CommunicationNotification::query()->where('employee_id', $lee->employee_id)->count(),
            '같은 글로 알림이 두 번 왔습니다(부름 + 답글).');
    }

    // ── 고친 글 ───────────────────────────────────────────────────────

    public function test_editing_in_a_new_name_calls_only_the_new_person(): void
    {
        $spy = $this->captureSends();
        $boss = $this->person('박반장', 'site_manager');
        $kim = $this->person('김철수');
        $lee = $this->person('이영희');

        $message = $this->chat->postMessage($boss, $this->room, '@김철수 확인');
        $spy->calls = [];

        $this->chat->editMessage($boss, $message, '@김철수 @이영희 확인');

        $this->assertSame(1, $this->mentionsOf($kim), '이미 부른 사람을 고칠 때마다 또 불렀습니다.');
        $this->assertSame(1, $this->mentionsOf($lee));
        $this->assertSame([$lee->id], $spy->rung(), '고친 글에서는 새로 부른 사람만 울려야 합니다.');
        $this->assertCount(2, $message->fresh()->payload['mentions']);
    }

    // ── 긴급 ──────────────────────────────────────────────────────────

    public function test_only_leads_can_send_urgent(): void
    {
        $worker = $this->person('김철수');
        $boss = $this->person('박반장', 'site_manager');

        $this->actingAs($worker)->post(route('communication.store', ['room' => $this->room]), [
            'body' => '긴급인 척', 'urgent' => '1',
        ])->assertRedirect();
        $this->assertSame('normal', CommunicationMessage::query()->where('body', '긴급인 척')->value('priority'));

        $this->actingAs($boss)->post(route('communication.store', ['room' => $this->room]), [
            'body' => '전원 대피', 'urgent' => '1',
        ])->assertRedirect();
        $this->assertSame('urgent', CommunicationMessage::query()->where('body', '전원 대피')->value('priority'));
    }

    // ── 방별 알림 설정 ─────────────────────────────────────────────────

    public function test_a_member_picks_the_level_for_a_room(): void
    {
        $lee = $this->person('이영희');

        $this->actingAs($lee)->postJson(route('communication.notify', ['room' => $this->room]), ['level' => 'mentions'])
            ->assertOk()->assertJsonPath('level', 'mentions');
        $this->assertSame('mentions', $this->chat->notifyLevelFor($lee, $this->room));

        $this->actingAs($lee)->postJson(route('communication.notify', ['room' => $this->room]), ['level' => 'loud'])
            ->assertStatus(422);
    }

    public function test_someone_outside_the_room_cannot_change_its_level(): void
    {
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'OTHER', 'name' => '다른 현장', 'status' => 'active']);
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $otherSite->id,
            'name' => '외부인', 'email' => 'out@example.com', 'employment_status' => 'active',
        ]);
        $outsider = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]);

        $this->actingAs($outsider)->postJson(route('communication.notify', ['room' => $this->room]), ['level' => 'none'])
            ->assertForbidden();
    }

    // ── 활동함 ────────────────────────────────────────────────────────

    public function test_the_activity_list_shows_calls_and_opening_one_goes_to_that_message(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($boss, $this->room, '@이영희 도면 Rev.3 확인');

        $this->actingAs($lee)->get(route('communication.activity'))
            ->assertOk()->assertSee('박반장')->assertSee('도면 Rev.3 확인');

        $notification = CommunicationNotification::query()->where('employee_id', $lee->employee_id)->firstOrFail();
        $this->actingAs($lee)->get(route('communication.activity.open', ['notification' => $notification]))
            ->assertRedirect(route('communication.show', ['room' => $this->room, 'focus' => $message->id]));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_nobody_opens_someone_elses_notification(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $this->chat->postMessage($boss, $this->room, '@이영희 확인');

        $notification = CommunicationNotification::query()->where('employee_id', $lee->employee_id)->firstOrFail();

        $this->actingAs($kim)->get(route('communication.activity.open', ['notification' => $notification]))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_opening_the_room_clears_the_calls_waiting_there(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $this->chat->postMessage($boss, $this->room, '@이영희 확인');

        $this->assertSame([$this->room->id => 1], $this->chat->personalUnreadByRoom($lee));

        $this->actingAs($lee)->get(route('communication.show', ['room' => $this->room]))->assertOk();

        $this->assertSame([], $this->chat->personalUnreadByRoom($lee), '방을 열어 봤는데 활동함에 안 읽음으로 남았습니다.');
    }

    public function test_the_room_list_marks_rooms_that_called_me(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $this->chat->postMessage($boss, $this->room, '@이영희 확인');

        $html = $this->actingAs($lee)->get(route('communication.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="link-activity"', $html);
        $this->assertStringContainsString('<span class="badge">@</span>', $html, '나를 부른 방에 @ 표시가 없습니다.');
    }

    public function test_the_room_with_the_latest_talk_is_on_top(): void
    {
        // PostgreSQL 은 내림차순에서 빈 값을 맨 앞에 둔다 — 글이 한 번도 없는 방이
        // 방금 대화가 오간 방보다 위에 서 있었다.
        $lee = $this->person('이영희');
        CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_TEAM, 'name' => '조용한 방', 'status' => 'active',
        ])->members()->create(['employee_id' => $lee->employee_id, 'user_id' => $lee->id, 'status' => 'active']);

        $this->chat->postMessage($lee, $this->room, '방금 쓴 글');

        $rooms = $this->chat->roomsForUser($lee);
        $this->assertSame($this->room->id, $rooms->first()->id, '방금 대화가 오간 방이 맨 위가 아닙니다.');
    }

    // ── 대화 불러오기 ──────────────────────────────────────────────────

    public function test_the_stream_says_who_was_called_so_the_screen_can_highlight_it(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $this->chat->postMessage($boss, $this->room, '@이영희 확인');

        $row = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk()->json('messages.0');

        $this->assertSame(['이영희'], $row['mentions']);
        $this->assertTrue($row['mentionsMe']);
    }

    public function test_a_notification_link_opens_around_that_message_not_at_the_bottom(): void
    {
        $lee = $this->person('이영희');
        $ids = [];
        for ($i = 1; $i <= 150; $i++) {
            $ids[$i] = CommunicationMessage::query()->create([
                'communication_room_id' => $this->room->id, 'sender_employee_id' => $lee->employee_id,
                'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => "글 {$i}", 'status' => 'active',
            ])->id;
        }

        $data = $this->actingAs($lee)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0, 'focus' => $ids[30]]))
            ->assertOk()->json();

        $got = array_column($data['messages'], 'id');
        $this->assertContains($ids[30], $got, '눌러 들어온 글이 화면에 없습니다.');
        $this->assertContains($ids[10], $got, '그 글 앞의 대화도 함께 보여야 합니다.');
        $this->assertTrue($data['hasOlder']);
    }

    public function test_scrolling_up_brings_older_messages(): void
    {
        $lee = $this->person('이영희');
        for ($i = 1; $i <= 130; $i++) {
            CommunicationMessage::query()->create([
                'communication_room_id' => $this->room->id, 'sender_employee_id' => $lee->employee_id,
                'kind' => CommunicationMessage::KIND_MESSAGE, 'body' => "글 {$i}", 'status' => 'active',
            ]);
        }

        $first = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))->json();
        $this->assertCount(60, $first['messages']);
        $this->assertTrue($first['hasOlder']);

        $oldest = min(array_column($first['messages'], 'id'));
        $older = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $this->room, 'before' => $oldest]))->json();

        $this->assertCount(60, $older['messages']);
        $this->assertLessThan($oldest, max(array_column($older['messages'], 'id')));
        $this->assertTrue($older['hasOlder']);

        $last = $this->actingAs($lee)->getJson(route('communication.stream', [
            'room' => $this->room, 'before' => min(array_column($older['messages'], 'id')),
        ]))->json();
        $this->assertCount(10, $last['messages']);
        $this->assertFalse($last['hasOlder'], '더 없는데 "더 보기" 가 남았습니다.');
    }

    public function test_a_change_after_the_cursor_reaches_other_screens(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $message = $this->chat->postMessage($kim, $this->room, '처음 글');

        $first = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))->json();

        $this->travel(10)->seconds();
        $this->chat->editMessage($kim, $message, '고친 글');

        $next = $this->actingAs($lee)->getJson(route('communication.stream', [
            'room' => $this->room, 'after' => $first['lastId'], 'changed' => $first['cursor'],
        ]))->json();

        $this->assertSame(['고친 글'], array_column($next['messages'], 'body'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
