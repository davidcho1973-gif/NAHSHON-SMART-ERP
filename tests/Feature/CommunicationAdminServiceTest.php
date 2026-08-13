<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\CommunicationAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 메신저 방 · 메시지 관리 — 직원이 대화하는 화면의 뒤편.
 *
 * 여기서 가장 자주 하는 일은 "구성원 동기화" 다. 나중에 합류한 직원은 방에 없어서
 * 공지를 못 보는데, 그 사실을 아무도 모른 채 지나간다. 그리고 가장 위험한 일은
 * 방 삭제다 — 방을 지우면 현장 지시·확인의 유일한 기록이 함께 사라진다.
 */
class CommunicationAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'AZ-PHX', 'name' => 'Phoenix',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function svc(): CommunicationAdminService
    {
        return app(CommunicationAdminService::class);
    }

    private function room(array $extra = []): CommunicationRoom
    {
        return CommunicationRoom::create(array_merge([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT,
            'name' => '피닉스 현장 채팅방',
            'status' => 'active',
        ], $extra));
    }

    public function test_worker_cannot_see_the_messenger_admin(): void
    {
        $this->actingAs($this->user('worker'));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_payroll_can_view_but_not_change(): void
    {
        $this->actingAs($this->user('payroll'));
        $this->room();

        $res = $this->svc()->list();

        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage']);
    }

    public function test_room_list_shows_member_and_message_counts(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '오늘 배관 작업 완료',
            'status' => 'active',
        ]);

        $row = collect($this->svc()->list()['rooms'])->firstWhere('id', $room->id);

        $this->assertSame(1, $row['messageCount']);
        $this->assertSame(0, $row['memberCount']);
        $this->assertTrue($row['canSyncMembers']);
    }

    public function test_direct_rooms_cannot_be_created_by_hand(): void
    {
        // 1:1 방은 두 직원의 조합(dm_key)으로만 만들어져야 짝이 어긋나지 않는다.
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveRoom([
            'name' => '수동 1:1', 'type' => CommunicationRoom::TYPE_DIRECT,
        ]);

        $this->assertFalse($res['success']);
        $this->assertDatabaseMissing('communication_rooms', ['name' => '수동 1:1']);
    }

    public function test_admin_can_create_an_announcement_room(): void
    {
        $this->actingAs($this->user('admin'));

        $res = $this->svc()->saveRoom([
            'name' => '피닉스 공지방',
            'type' => CommunicationRoom::TYPE_SITE_ANNOUNCEMENT,
            'site_id' => $this->site->id,
            'is_read_only' => true,
        ]);

        $this->assertTrue($res['success']);
        $room = CommunicationRoom::findOrFail($res['id']);
        $this->assertTrue($room->is_read_only);
        // 현장을 고르면 회사도 그 현장을 따라간다 — 사람이 두 번 고를 이유가 없다.
        $this->assertSame($this->company->id, $room->company_id);
    }

    public function test_syncing_members_adds_active_site_crew_and_is_repeatable(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '현장 인원', 'employment_status' => 'active',
        ]);
        Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '퇴사자', 'employment_status' => 'terminated',
        ]);

        $first = $this->svc()->syncRoomMembers($room->id);

        $this->assertTrue($first['success']);
        $this->assertSame(1, $first['added']);
        $this->assertSame(1, $first['total']);

        // 여러 번 눌러도 안전해야 한다 — 언제 누를지 사람이 헷갈리는 버튼이다.
        $again = $this->svc()->syncRoomMembers($room->id);
        $this->assertSame(0, $again['added']);
        $this->assertSame(1, $again['total']);
    }

    public function test_rooms_without_a_site_cannot_sync_members(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room(['site_id' => null, 'type' => CommunicationRoom::TYPE_COMPANY]);

        $this->assertFalse($this->svc()->syncRoomMembers($room->id)['success']);
    }

    public function test_room_with_messages_cannot_be_deleted(): void
    {
        // 현장에서 주고받은 지시·확인은 나중에 "그때 뭐라고 했나" 를 따질 유일한 기록이다.
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '내일 07시 집합',
            'status' => 'active',
        ]);

        $res = $this->svc()->deleteRoom($room->id);

        $this->assertFalse($res['success']);
        $this->assertDatabaseHas('communication_rooms', ['id' => $room->id]);
    }

    public function test_empty_room_can_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();

        $this->assertTrue($this->svc()->deleteRoom($room->id)['success']);
        $this->assertDatabaseMissing('communication_rooms', ['id' => $room->id]);
    }

    public function test_posting_a_message_records_the_sender_and_bumps_the_room(): void
    {
        $actor = $this->user('site_manager');
        $this->actingAs($actor);
        $room = $this->room();

        $res = $this->svc()->saveMessage([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '자재 도착했습니다',
        ]);

        $this->assertTrue($res['success']);
        $message = CommunicationMessage::findOrFail($res['id']);
        $this->assertSame($actor->id, $message->sender_user_id);
        // 방의 "최근 메시지" 가 갱신돼야 목록 정렬이 맞는다.
        $this->assertNotNull($room->refresh()->last_message_at);
    }

    public function test_editing_a_message_keeps_it_in_the_same_room(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        $other = $this->room(['name' => '다른 방']);
        $message = CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '오타 있음',
            'status' => 'active',
        ]);

        $res = $this->svc()->saveMessage([
            'id' => $message->id,
            'communication_room_id' => $other->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '오타 고침',
            'is_pinned' => true,
        ]);

        $this->assertTrue($res['success']);
        $message->refresh();
        $this->assertSame('오타 고침', $message->body);
        $this->assertTrue($message->is_pinned);
        // 방을 옮기면 그 방 사람들이 읽지도 않은 글이 읽은 것으로 남는다.
        $this->assertSame($room->id, $message->communication_room_id);
    }

    public function test_empty_body_is_refused(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();

        $res = $this->svc()->saveMessage([
            'communication_room_id' => $room->id, 'body' => '   ',
        ]);

        $this->assertFalse($res['success']);
        $this->assertSame(0, CommunicationMessage::count());
    }

    public function test_message_with_replies_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        $parent = CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_ANNOUNCEMENT,
            'body' => '안전 교육 안내',
            'status' => 'active',
        ]);
        CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'parent_id' => $parent->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '확인했습니다',
            'status' => 'active',
        ]);

        $this->assertFalse($this->svc()->deleteMessage($parent->id)['success']);
        $this->assertDatabaseHas('communication_messages', ['id' => $parent->id]);
    }

    public function test_message_without_replies_can_be_deleted(): void
    {
        $this->actingAs($this->user('admin'));
        $room = $this->room();
        $message = CommunicationMessage::create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '잘못 올림',
            'status' => 'active',
        ]);

        $this->assertTrue($this->svc()->deleteMessage($message->id)['success']);
        $this->assertDatabaseMissing('communication_messages', ['id' => $message->id]);
    }
}
