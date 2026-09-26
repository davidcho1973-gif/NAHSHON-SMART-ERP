<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationMessageReaction;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MessageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확인하고, 찾고, 꽂아 둔다.
 *
 *   · ✅ 반응 — 지시에 "네 확인했습니다" 답글 스무 개 대신 누른다. 누가 눌렀는지 보인다.
 *   · 검색 — 볼 수 있는 방의 글만. 지운 글과 남의 1:1 은 관리자에게도 안 나온다.
 *   · 고정 — 두고두고 볼 글(도면 Rev, 출입 규칙)을 방 위에 꽂아 둔다.
 */
class ChatReactionsSearchPinsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private CommunicationRoom $room;
    private CommunicationService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(CommunicationService::class);
        $this->company = Company::create(['code' => 'RSP-CO', 'name' => 'RSP Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'RSP', 'name' => '현장', 'status' => 'active']);
        $this->room = $this->room('현장 채팅방');
    }

    private function room(string $name, string $type = CommunicationRoom::TYPE_SITE_CHAT, ?Site $site = null): CommunicationRoom
    {
        $site ??= $this->site;

        return CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'type' => $type, 'name' => $name, 'status' => 'active',
            'is_read_only' => $type === CommunicationRoom::TYPE_SITE_ANNOUNCEMENT,
        ]);
    }

    private function person(string $name, string $role = 'worker', ?CommunicationRoom $room = null, ?Site $site = null): User
    {
        static $n = 0;
        $n++;
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => ($site ?? $this->site)->id,
            'name' => $name, 'email' => "r{$n}@example.com", 'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id, 'email' => "r{$n}@example.com",
            'access_role' => $role, 'access_scope' => 'self', 'account_status' => 'active',
        ]);
        $this->chat->ensureRoomMember($room ?? $this->room, $employee);

        return $user;
    }

    private function react(User $user, CommunicationMessage $message, string $emoji = '✅')
    {
        return $this->actingAs($user)->postJson(
            route('communication.react', ['room' => $message->communication_room_id, 'message' => $message]),
            ['emoji' => $emoji],
        );
    }

    // ── ✅ 반응 ───────────────────────────────────────────────────────

    public function test_confirming_toggles_on_and_off(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $order = $this->chat->postMessage($boss, $this->room, '내일 07시 안전교육');

        $this->react($lee, $order)->assertOk()
            ->assertJsonPath('reactions.0.emoji', '✅')
            ->assertJsonPath('reactions.0.count', 1)
            ->assertJsonPath('reactions.0.mine', true);
        $this->assertSame(1, CommunicationMessageReaction::query()->count());

        // 한 번 더 누르면 취소 — 잘못 누른 것을 되돌릴 수 있어야 한다.
        $this->react($lee, $order)->assertOk()->assertJsonPath('reactions', []);
        $this->assertSame(0, CommunicationMessageReaction::query()->count());
    }

    public function test_only_the_offered_reactions_are_accepted(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '자재 도착');

        $this->react($lee, $message, '🎉')->assertStatus(422);
        $this->assertSame(0, CommunicationMessageReaction::query()->count());
    }

    public function test_someone_outside_the_room_cannot_react(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '자재 도착');

        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'RSP-2', 'name' => '다른 현장', 'status' => 'active']);
        $outsider = $this->person('외부인', 'worker', $this->room('다른 방', CommunicationRoom::TYPE_SITE_CHAT, $otherSite), $otherSite);

        $this->react($outsider, $message)->assertForbidden();
    }

    public function test_a_removed_message_takes_no_reactions(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '잘못 올림');
        $this->chat->removeMessage($lee, $message);

        $this->react($lee, $message->fresh())->assertStatus(422);
    }

    public function test_the_stream_shows_who_confirmed(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $order = $this->chat->postMessage($boss, $this->room, '3층 출입 통제');

        $this->react($lee, $order);
        $this->react($kim, $order);
        $this->react($kim, $order, '👀');

        $row = $this->actingAs($boss)->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk()->json('messages.0');

        $this->assertSame('✅', $row['reactions'][0]['emoji'], '✅ 확인이 맨 앞에 와야 합니다.');
        $this->assertSame(2, $row['reactions'][0]['count']);
        $this->assertEqualsCanonicalizing(['이영희', '김철수'], $row['reactions'][0]['names']);
        $this->assertFalse($row['reactions'][0]['mine']);
        $this->assertSame('👀', $row['reactions'][1]['emoji']);
    }

    public function test_a_reaction_reaches_screens_that_are_already_open(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $message = $this->chat->postMessage($kim, $this->room, '배관 완료');

        $first = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))->json();
        $this->travel(10)->seconds();
        $this->react($kim, $message);

        $next = $this->actingAs($lee)->getJson(route('communication.stream', [
            'room' => $this->room, 'after' => $first['lastId'], 'changed' => $first['cursor'],
        ]))->json();

        $this->assertSame([$message->id], array_column($next['messages'], 'id'), '누른 반응이 열려 있는 화면에 닿지 않았습니다.');
        $this->assertSame(1, $next['messages'][0]['reactions'][0]['count']);
    }

    // ── 검색 ─────────────────────────────────────────────────────────

    private function search(User $user, string $q, ?CommunicationRoom $room = null): array
    {
        return array_column(app(MessageSearch::class)->search($user, $q, $room), 'id');
    }

    public function test_search_finds_a_word_even_with_a_particle_attached(): void
    {
        $lee = $this->person('이영희');
        $hit = $this->chat->postMessage($lee, $this->room, '3층 슬리브를 옮겨야 합니다');
        $this->chat->postMessage($lee, $this->room, '점심 먹고 합시다');

        $this->assertSame([$hit->id], $this->search($lee, '슬리브'));
    }

    public function test_every_word_must_be_in_the_message(): void
    {
        $lee = $this->person('이영희');
        $both = $this->chat->postMessage($lee, $this->room, '3층 슬리브 위치 변경');
        $this->chat->postMessage($lee, $this->room, '2층 슬리브 완료');

        $this->assertSame([$both->id], $this->search($lee, '3층 슬리브'));
    }

    public function test_search_never_reaches_a_room_you_cannot_open(): void
    {
        $lee = $this->person('이영희');
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'RSP-3', 'name' => '다른 현장', 'status' => 'active']);
        $otherRoom = $this->room('다른 현장방', CommunicationRoom::TYPE_SITE_CHAT, $otherSite);
        $stranger = $this->person('남', 'worker', $otherRoom, $otherSite);
        $this->chat->postMessage($stranger, $otherRoom, '비밀 단가 표');

        $this->assertSame([], $this->search($lee, '단가'), '들어갈 수 없는 방의 글이 검색에 나왔습니다.');
    }

    public function test_an_admin_does_not_find_other_peoples_direct_messages(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $dm = $this->chat->directRoomFor($lee->employee, $kim->employee);
        $this->chat->postMessage($lee, $dm, '개인 사정으로 내일 늦습니다');

        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        $this->assertSame([], $this->search($admin, '개인 사정'));
        $this->assertCount(1, $this->search($lee, '개인 사정'), '본인은 자기 1:1 대화를 찾을 수 있어야 합니다.');
    }

    public function test_removed_messages_stay_hidden(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '잘못 올린 금액 5000');
        $this->chat->removeMessage($lee, $message);

        $this->assertSame([], $this->search($lee, '5000'));
    }

    public function test_file_names_are_searched_too(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '');
        CommunicationMessageFile::query()->create([
            'communication_message_id' => $message->id, 'original_name' => '3층_견적서_Rev2.pdf',
            'kind' => 'document', 'file_size' => 100,
        ]);

        $this->assertSame([$message->id], $this->search($lee, '견적서'));
    }

    public function test_search_can_stay_inside_one_room(): void
    {
        $lee = $this->person('이영희');
        $team = $this->room('배관팀', CommunicationRoom::TYPE_TEAM);
        $this->chat->ensureRoomMember($team, $lee->employee);
        $inTeam = $this->chat->postMessage($lee, $team, '압력시험 통과');
        $this->chat->postMessage($lee, $this->room, '압력시험 일정');

        $this->assertSame([$inTeam->id], $this->search($lee, '압력시험', $team));
    }

    public function test_the_search_page_links_straight_to_the_message(): void
    {
        $lee = $this->person('이영희');
        $message = $this->chat->postMessage($lee, $this->room, '도면 Rev.3 받았습니다');

        $this->actingAs($lee)->get(route('communication.search', ['q' => 'Rev.3']))
            ->assertOk()
            ->assertSee(route('communication.show', ['room' => $this->room, 'focus' => $message->id]), false)
            ->assertSee('<mark>Rev.3</mark>', false);

        // 한 글자("도")는 거의 모든 글에 걸린다 — 결과를 쏟아내지 않고 더 적어 달라고 한다.
        $this->actingAs($lee)->get(route('communication.search', ['q' => '도']))->assertOk()->assertDontSee('class="hit"', false);
    }

    public function test_searching_a_room_you_cannot_open_is_refused(): void
    {
        $lee = $this->person('이영희');
        $otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'RSP-4', 'name' => '다른 현장', 'status' => 'active']);
        $otherRoom = $this->room('다른 현장방', CommunicationRoom::TYPE_SITE_CHAT, $otherSite);

        $this->actingAs($lee)->get(route('communication.search', ['q' => '단가', 'room' => $otherRoom->id]))->assertNotFound();
    }

    // ── 고정 ─────────────────────────────────────────────────────────

    public function test_a_member_pins_and_unpins_a_message(): void
    {
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $rule = $this->chat->postMessage($kim, $this->room, '3층 출입은 07~17시만');

        $this->actingAs($lee)->postJson(route('communication.pin', ['room' => $this->room, 'message' => $rule]))
            ->assertOk()->assertJsonPath('pinned', true);
        $this->assertTrue($rule->fresh()->is_pinned);
        $this->assertSame('이영희', $rule->fresh()->payload['pinned_by']['name'], '누가 꽂았는지 남아야 합니다.');

        $pins = $this->actingAs($kim)->getJson(route('communication.pins', ['room' => $this->room]))->assertOk()->json('pins');
        $this->assertSame([$rule->id], array_column($pins, 'id'));

        $this->actingAs($lee)->postJson(route('communication.pin', ['room' => $this->room, 'message' => $rule]))
            ->assertOk()->assertJsonPath('pinned', false);
        $this->assertFalse($rule->fresh()->is_pinned);
    }

    public function test_in_a_notice_room_only_leads_pin(): void
    {
        $notice = $this->room('공지방', CommunicationRoom::TYPE_SITE_ANNOUNCEMENT);
        $boss = $this->person('박반장', 'site_manager', $notice);
        $lee = $this->person('이영희', 'worker', $notice);
        $post = $this->chat->postMessage($boss, $notice, '안전모 필수', ['kind' => CommunicationMessage::KIND_ANNOUNCEMENT]);
        $post->update(['is_pinned' => false]);

        $this->actingAs($lee)->postJson(route('communication.pin', ['room' => $notice, 'message' => $post]))->assertForbidden();
        $this->actingAs($boss)->postJson(route('communication.pin', ['room' => $notice, 'message' => $post]))->assertOk();
    }
}
