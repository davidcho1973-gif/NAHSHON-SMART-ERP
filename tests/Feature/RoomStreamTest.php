<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 새로고침 없이 대화가 흐른다.
 *
 * 새로고침해야 새 글이 보이는 대화창은 대화창이 아니다. 그렇다고 방 전체를 몇 초마다
 * 다시 내려받으면 현장 통신망과 요금이 함께 죽는다 — 마지막으로 받은 번호 이후만 준다.
 *
 * 폴링 간격을 서버가 정하는 것도 여기서 지킨다. 화면에 3초를 박아 두면 조용한 방까지
 * 3초마다 두드리고, 앱이 잠들 수 있는 배포에서는 그것이 곧 요금이다.
 */
class RoomStreamTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private Employee $employee;
    private User $user;
    private CommunicationRoom $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'STREAM-CO', 'name' => 'Stream Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'STREAM-SITE', 'name' => '현장', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'Kim', 'last_name' => 'Stream',
            'email' => 'stream@example.com', 'employment_status' => 'active',
        ]);
        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'worker', 'account_status' => 'active',
        ]);
        $this->room = CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장 채팅방', 'status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($this->room, $this->employee);
    }

    private function say(string $body): CommunicationMessage
    {
        return CommunicationMessage::query()->create([
            'communication_room_id' => $this->room->id,
            'sender_employee_id' => $this->employee->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => $body,
            'status' => 'active',
        ]);
    }

    private function stream(int $after)
    {
        return $this->actingAs($this->user)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => $after]));
    }

    public function test_only_what_arrived_after_the_last_one_comes_back(): void
    {
        $first = $this->say('첫 번째');
        $second = $this->say('두 번째');

        $response = $this->stream($first->id)->assertOk();

        $this->assertCount(1, $response->json('messages'), '이미 본 글까지 다시 내려왔습니다 — 통신망과 요금이 함께 샙니다.');
        $this->assertSame('두 번째', $response->json('messages.0.body'));
        $this->assertSame($second->id, $response->json('lastId'));
    }

    public function test_nothing_new_returns_an_empty_list_not_an_error(): void
    {
        $only = $this->say('하나뿐');

        $response = $this->stream($only->id)->assertOk();

        $this->assertSame([], $response->json('messages'));
        $this->assertSame($only->id, $response->json('lastId'), '새 글이 없다고 커서가 0으로 되돌아가면 전부 다시 내려옵니다.');
    }

    public function test_the_server_decides_how_soon_to_ask_again(): void
    {
        // 방금 대화가 오갔으면 빨리, 조용하면 천천히 — 화면이 정하지 않는다.
        $message = $this->say('방금 왔다');
        $hot = $this->stream(0)->assertOk()->json('nextPollMs');
        $this->assertSame(3000, $hot);

        // 오래 조용한 방은 간격이 길어져야 한다.
        $this->room->update(['last_message_at' => now()->subHours(6)]);
        $cold = $this->stream($message->id)->assertOk()->json('nextPollMs');

        $this->assertGreaterThan($hot, $cold, '조용한 방까지 3초마다 두드리면 그대로 요금이 됩니다.');
    }

    public function test_attachments_come_with_the_message_so_photos_appear_live(): void
    {
        $message = $this->say('영수증입니다');
        CommunicationMessageFile::query()->create([
            'communication_message_id' => $message->id,
            'disk' => 'local',
            'path' => 'document-intelligence/inbox/x/receipt.jpg',
            'original_name' => 'receipt.jpg',
            'kind' => CommunicationMessageFile::KIND_IMAGE,
            'file_size' => 2048,
        ]);

        $file = $this->stream(0)->assertOk()->json('messages.0.files.0');

        $this->assertSame('receipt.jpg', $file['name']);
        $this->assertTrue($file['isImage']);
        $this->assertNotNull($file['url']);
    }

    public function test_receiving_the_messages_counts_as_reading_them(): void
    {
        // 화면에 떠 있는데 안 읽음으로 남으면 미읽음 숫자가 영원히 줄지 않는다.
        $this->say('읽음 처리되어야 한다');

        $this->stream(0)->assertOk();

        $this->assertSame(0, app(CommunicationService::class)->unreadCountsForUser($this->user)[$this->room->id] ?? 0);
    }

    public function test_someone_outside_the_room_cannot_listen_in(): void
    {
        $this->say('현장 내부 이야기');
        $outsider = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);

        $this->actingAs($outsider)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertForbidden();
    }
}
