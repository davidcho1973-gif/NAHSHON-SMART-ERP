<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 잘못 쓴 글은 본인이 고치고 지운다 — 다만 기록은 남는다.
 *
 * 현장 지시는 나중에 분쟁의 증거가 된다. 데이터베이스에서 진짜로 지워 버리면 무엇이
 * 있었는지조차 알 수 없게 된다. 그래서 카카오톡처럼 자리는 남기고 내용만 감춘다.
 * 고친 글에 (수정됨)이 붙는 것도 같은 이유다 — 조용히 바뀌면 다툼이 된다.
 *
 * 그리고 방에 누가 있는지, 지금 보고 있는지 보인다. 상대가 화면 앞에 있는지 아는
 * 것만으로 "왜 답이 없지" 가 사라진다.
 */
class ChatEditingAndPresenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private CommunicationRoom $room;
    private Employee $mine;
    private User $me;
    private Employee $theirs;
    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'EDIT-CO', 'name' => 'Edit Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'EDIT-SITE', 'name' => '현장', 'status' => 'active',
        ]);
        $this->room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장 채팅방', 'status' => 'active',
        ]);

        [$this->mine, $this->me] = $this->member('me@example.com');
        [$this->theirs, $this->them] = $this->member('them@example.com');
    }

    /** @return array{0: Employee, 1: User} */
    private function member(string $email): array
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'A', 'last_name' => $email, 'email' => $email, 'employment_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->id, 'email' => $email,
            'access_role' => 'worker', 'account_status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($this->room, $employee);

        return [$employee, $user];
    }

    private function say(User $user, string $body): CommunicationMessage
    {
        return app(CommunicationService::class)->postMessage($user, $this->room, $body);
    }

    // ── 고치기 ─────────────────────────────────────────────────────────

    public function test_i_can_fix_my_own_typo_and_it_says_so(): void
    {
        $message = $this->say($this->me, '3층 배관 4인치 완료');

        $this->actingAs($this->me)
            ->patchJson(route('communication.message.update', ['room' => $this->room, 'message' => $message]), [
                'body' => '3층 배관 6인치 완료',
            ])->assertOk();

        $message->refresh();
        $this->assertSame('3층 배관 6인치 완료', $message->body);
        $this->assertNotNull($message->edited_at, '고친 흔적이 없으면 "분명히 다르게 봤는데" 가 됩니다.');
    }

    public function test_i_cannot_rewrite_someone_elses_words(): void
    {
        $message = $this->say($this->them, '원청 지시 사항입니다');

        $this->actingAs($this->me)
            ->patchJson(route('communication.message.update', ['room' => $this->room, 'message' => $message]), [
                'body' => '그런 지시 없었습니다',
            ])->assertForbidden();

        $this->assertSame('원청 지시 사항입니다', $message->fresh()->body);
    }

    public function test_the_ai_reply_is_not_editable_by_anyone(): void
    {
        // 로봇 답글은 판단의 근거 기록이다 — 사람이 고치면 근거가 아니게 된다.
        $robot = CommunicationMessage::query()->create([
            'communication_room_id' => $this->room->id,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'body' => '읽었습니다 — 재무 승인대기로 올렸습니다.',
            'status' => 'active',
        ]);

        $this->assertFalse(app(CommunicationService::class)->canEdit($this->me, $robot));
    }

    // ── 지우기 ─────────────────────────────────────────────────────────

    public function test_deleting_hides_the_words_but_keeps_the_record(): void
    {
        $message = $this->say($this->me, '잘못 올린 개인 정보');

        $this->actingAs($this->me)
            ->deleteJson(route('communication.message.destroy', ['room' => $this->room, 'message' => $message]))
            ->assertOk();

        $message->refresh();
        $this->assertNotNull($message->removed_at);
        $this->assertSame($this->me->id, $message->removed_by_user_id);
        $this->assertSame('삭제된 메시지입니다.', $message->visibleBody(), '지운 글이 화면에 그대로 보입니다.');
        // 진짜로 지워지면 안 된다 — 현장 지시는 나중에 증거가 되는데, 무엇이 있었는지조차
        // 알 수 없게 된다. 원문은 데이터베이스에 그대로 남아 있어야 한다.
        $this->assertDatabaseHas('communication_messages', ['id' => $message->id]);
        $this->assertSame('잘못 올린 개인 정보', $message->body);
    }

    public function test_a_manager_can_take_down_someone_elses_post(): void
    {
        $manager = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $message = $this->say($this->them, '부적절한 글');

        $this->assertTrue(app(CommunicationService::class)->canRemove($manager, $message));
        $this->assertFalse(app(CommunicationService::class)->canRemove($this->me, $message),
            '아무나 남의 글을 지울 수 있으면 대화가 신뢰를 잃습니다.');
    }

    public function test_others_see_the_deletion_without_refreshing(): void
    {
        // 지운 글이 남의 화면에는 그대로 남아 있으면 지운 것이 아니다.
        $message = $this->say($this->me, '곧 지울 글');
        $seen = $this->actingAs($this->them)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->json('lastId');

        app(CommunicationService::class)->removeMessage($this->me, $message);

        $rows = $this->actingAs($this->them)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => $seen]))
            ->assertOk()->json('messages');

        $this->assertNotEmpty($rows, '지워진 사실이 상대 화면으로 전달되지 않았습니다.');
        $this->assertTrue($rows[0]['removed']);
        $this->assertSame('삭제된 메시지입니다.', $rows[0]['body']);
        $this->assertSame([], $rows[0]['files']);
    }

    // ── 누가 있는가 ────────────────────────────────────────────────────

    public function test_the_room_shows_who_is_here_and_who_is_watching_now(): void
    {
        $this->actingAs($this->me)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk();

        $response = $this->actingAs($this->me)
            ->getJson(route('communication.members', ['room' => $this->room]))
            ->assertOk();

        $members = $response->json('members');
        $this->assertCount(2, $members, '방에 누가 있는지 보이지 않습니다.');

        $watching = collect($members)->firstWhere('online', true);
        $this->assertNotNull($watching, '방금 방을 연 사람이 접속 중으로 보이지 않습니다.');
        $this->assertSame($this->mine->name, $watching['name']);
    }

    public function test_the_stream_carries_the_online_count(): void
    {
        $data = $this->actingAs($this->me)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk()->json();

        $this->assertSame(2, $data['membersCount']);
        $this->assertSame(1, $data['onlineCount']);
    }

    public function test_an_outsider_cannot_see_who_is_in_the_room(): void
    {
        $outsider = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);

        $this->actingAs($outsider)
            ->getJson(route('communication.members', ['room' => $this->room]))
            ->assertForbidden();
    }

    // ── 처음 열었을 때 ─────────────────────────────────────────────────

    public function test_opening_a_long_running_room_shows_the_latest_talk_not_the_oldest(): void
    {
        // 1년 전 대화부터 보여 주면 스크롤을 한참 내려야 오늘 이야기가 나온다.
        for ($i = 1; $i <= 70; $i++) {
            $this->say($this->me, "메시지 {$i}");
        }

        $rows = $this->actingAs($this->them)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk()->json('messages');

        $this->assertSame('메시지 70', end($rows)['body'], '처음 화면에 최근 대화가 없습니다.');
        $this->assertLessThanOrEqual(60, count($rows));
    }
}
