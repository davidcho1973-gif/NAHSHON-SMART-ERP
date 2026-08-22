<?php

namespace Tests\Feature;

use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 방 만들기·정리를 채팅 화면에서 한다.
 *
 * 방을 만들려면 ERP 관리 화면까지 들어가야 했다. 현장 사람은 폰만 보는데 거기서 못
 * 만들면 없는 기능이나 마찬가지다.
 *
 * 다만 규칙은 그대로다 — 아무나 방을 만들면 난립하고, <b>대화가 오간 방은 지워지지
 * 않는다</b>(현장 지시·확인의 유일한 기록일 수 있다). 그런 방은 보관으로 내려간다.
 */
class ChatRoomManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'RM-CO', 'name' => 'Room Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'RM-SITE', 'name' => '현장', 'status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => $role, 'last_name' => 'Kim',
            'email' => $role.'@example.com', 'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'email' => $role.'@example.com',
            'access_role' => $role, 'account_status' => 'active',
        ]);
    }

    public function test_a_site_manager_can_create_a_room_from_the_phone(): void
    {
        $manager = $this->user('site_manager');

        $this->actingAs($manager)->post(route('communication.room.store'), [
            'name' => '3층 배관팀',
            'type' => CommunicationRoom::TYPE_TEAM,
            'site_id' => $this->site->id,
        ])->assertRedirect();

        $room = CommunicationRoom::query()->where('name', '3층 배관팀')->first();
        $this->assertNotNull($room, '채팅 화면에서 만든 방이 생기지 않았습니다.');

        // 만든 사람이 그 방에 없으면 앞뒤가 안 맞는다.
        $this->assertTrue($room->activeMembers()->where('employee_id', $manager->employee_id)->exists());
    }

    public function test_a_worker_cannot_create_rooms(): void
    {
        // 아무나 방을 만들면 난립한다 — 목록이 쓰레기가 되면 아무도 안 본다.
        $this->actingAs($this->user('worker'))->post(route('communication.room.store'), [
            'name' => '내 마음대로 방', 'type' => CommunicationRoom::TYPE_TEAM,
        ])->assertRedirect();

        $this->assertNull(CommunicationRoom::query()->where('name', '내 마음대로 방')->first());
    }

    public function test_an_empty_room_is_deleted_outright(): void
    {
        $manager = $this->user('site_manager');
        $room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_TEAM, 'name' => '빈 방', 'status' => 'active',
        ]);

        $this->actingAs($manager)
            ->delete(route('communication.room.destroy', ['room' => $room]))
            ->assertRedirect(route('communication.index'));

        $this->assertNull(CommunicationRoom::query()->find($room->id));
    }

    public function test_a_room_with_history_is_archived_not_erased(): void
    {
        $manager = $this->user('site_manager');
        $room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '대화가 오간 방', 'status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($room, $manager->employee);
        app(CommunicationService::class)->postMessage($manager, $room, '원청 지시: 내일 07시 안전교육');

        $this->actingAs($manager)
            ->delete(route('communication.room.destroy', ['room' => $room]))
            ->assertRedirect(route('communication.index'));

        $room->refresh();
        $this->assertSame('archived', $room->status, '대화가 오간 방이 목록에서 안 내려갔습니다.');
        $this->assertDatabaseHas('communication_messages', ['communication_room_id' => $room->id]);
    }

    public function test_a_worker_cannot_take_a_room_down(): void
    {
        $room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_TEAM, 'name' => '남의 방', 'status' => 'active',
        ]);

        $this->actingAs($this->user('worker'))
            ->delete(route('communication.room.destroy', ['room' => $room]))
            ->assertRedirect();

        $this->assertNotNull(CommunicationRoom::query()->find($room->id));
        $this->assertSame('active', $room->fresh()->status);
    }

    public function test_the_button_only_shows_for_those_who_may_use_it(): void
    {
        // 눌러도 막히는 버튼은 만들지 않는다.
        $managerHtml = $this->actingAs($this->user('site_manager'))
            ->get(route('communication.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="btn-new-room"', $managerHtml);

        $workerHtml = $this->actingAs($this->user('worker'))
            ->get(route('communication.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="btn-new-room"', $workerHtml);
    }
}
