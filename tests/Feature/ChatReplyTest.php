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
 * 개인앱을 사용하는 방 구성원은 공지에 답글을 달 수 있다 — 안내한 대로.
 *
 * 화면은 "이 방은 공지 전용입니다. 각 공지에 답글로 이야기해 주세요" 라고 안내하면서
 * 정작 답글 쓸 칸이 없었다. 안내와 화면이 어긋나면 사람들은 앱을 믿지 않는다.
 */
class ChatReplyTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationRoom $room;

    private User $foreman;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'RPL-CO', 'name' => 'Reply Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'RPL', 'name' => '현장', 'status' => 'active']);

        // 공지 전용 방 — 새 글은 관리자만, 답글은 개인앱을 사용하는 방 구성원.
        $this->room = CommunicationRoom::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'type' => CommunicationRoom::TYPE_SITE_ANNOUNCEMENT, 'name' => '공지방',
            'status' => 'active', 'is_read_only' => true,
        ]);

        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => 'W', 'last_name' => 'Kim', 'email' => 'w@example.com', 'employment_status' => 'active',
        ]);
        $this->foreman = User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => 'foreman', 'access_scope' => 'self', 'account_status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($this->room, $employee);

        $bossEmployee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'first_name' => 'B', 'last_name' => 'Lee', 'email' => 'b@example.com', 'employment_status' => 'active',
        ]);
        $this->manager = User::factory()->create([
            'employee_id' => $bossEmployee->id, 'access_role' => 'site_manager', 'account_status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($this->room, $bossEmployee);
    }

    private function announcement(): CommunicationMessage
    {
        return app(CommunicationService::class)->postMessage($this->manager, $this->room, '내일 07시 안전교육', [
            'kind' => CommunicationMessage::KIND_ANNOUNCEMENT,
            'title' => '안전교육 공지',
        ]);
    }

    public function test_a_foreman_can_reply_to_an_announcement_even_in_a_read_only_room(): void
    {
        $notice = $this->announcement();

        $this->actingAsPersonalAppUser($this->foreman)
            ->post(route('communication.store', ['room' => $this->room]), [
                'body' => '확인했습니다',
                'parent_id' => $notice->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('communication_messages', [
            'parent_id' => $notice->id,
            'body' => '확인했습니다',
            'sender_user_id' => $this->foreman->id,
        ]);
    }

    public function test_a_foreman_still_cannot_start_a_new_announcement(): void
    {
        $this->actingAsPersonalAppUser($this->foreman)
            ->post(route('communication.store', ['room' => $this->room]), ['body' => '제 마음대로 공지'])
            ->assertForbidden();
    }

    public function test_the_reply_screen_offers_a_way_to_write_one(): void
    {
        $this->announcement();

        $html = $this->actingAsPersonalAppUser($this->foreman)
            ->get(route('communication.show', ['room' => $this->room]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('id="parent-id"', $html,
            '답글 쓸 칸이 없습니다 — 화면 안내가 거짓말이 됩니다.');
        $this->assertStringContainsString('window.Chat.reply', $html, '[답글] 버튼이 없습니다.');
    }

    public function test_the_stream_says_what_a_reply_answers(): void
    {
        $notice = $this->announcement();
        app(CommunicationService::class)->postMessage($this->foreman, $this->room, '확인했습니다', [
            'parent_id' => $notice->id,
        ]);

        $rows = $this->actingAsPersonalAppUser($this->foreman)
            ->getJson(route('communication.stream', ['room' => $this->room, 'after' => 0]))
            ->assertOk()->json('messages');

        $reply = collect($rows)->firstWhere('body', '확인했습니다');
        $this->assertSame($notice->id, $reply['parentId'], '답글이 어느 공지에 달린 건지 화면이 알 수 없습니다.');
    }
}
