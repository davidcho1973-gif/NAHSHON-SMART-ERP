<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\CommunicationAdminService;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 방이 늘어도 길을 잃지 않는다 — 주제방 · 그룹방 · 스레드.
 *
 *   · 주제방(공개): 같은 회사 사람이면 누구나 "방 찾기" 에서 보고 들어오고 나간다.
 *     들어가기 전에는 내 방 목록을 어지럽히지 않는다.
 *   · 그룹방(비공개): 초대받은 사람만. 관리자라도 명단에 없으면 못 본다 — 1:1 과 같다.
 *   · 스레드: 답글은 원글 아래로 접힌다. "방에도 보내기" 를 고르면 방에도 보인다.
 */
class ChatTopicsAndThreadsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private CommunicationService $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chat = app(CommunicationService::class);
        $this->company = Company::create(['code' => 'TOP-CO', 'name' => 'Topic Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'TOP', 'name' => '현장', 'status' => 'active']);
    }

    private function person(string $name, string $role = 'worker', ?Company $company = null): User
    {
        static $n = 0;
        $n++;
        $company ??= $this->company;
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $company->is($this->company) ? $this->site->id : null,
            'name' => $name, 'email' => "t{$n}@example.com", 'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'email' => "t{$n}@example.com",
            'access_role' => $role, 'access_scope' => 'self', 'account_status' => 'active',
        ]);
    }

    private function makeRoom(User $creator, string $type, string $name = '3층 배관 이야기'): CommunicationRoom
    {
        $this->actingAs($creator)->post(route('communication.room.store'), [
            'name' => $name, 'type' => $type, 'description' => '3층 배관 공정·자재',
        ])->assertRedirect();

        return CommunicationRoom::query()->where('name', $name)->firstOrFail();
    }

    // ── 주제방(공개) ──────────────────────────────────────────────────

    public function test_a_topic_room_is_found_by_anyone_in_the_company_and_joined_by_choice(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $topic = $this->makeRoom($boss, CommunicationRoom::TYPE_TOPIC);

        $this->assertSame($this->company->id, (int) $topic->company_id, '현장을 안 골라도 만든 사람의 회사 방이어야 합니다.');

        // 들어가기 전 — 내 방 목록은 어지럽히지 않고, "방 찾기" 에는 보인다.
        $this->assertFalse($this->chat->roomsForUser($lee)->contains('id', $topic->id));
        $this->actingAs($lee)->get(route('communication.browse'))->assertOk()->assertSee('3층 배관 이야기');

        $this->actingAs($lee)->post(route('communication.join', ['room' => $topic]))
            ->assertRedirect(route('communication.show', ['room' => $topic]));
        $this->assertTrue($this->chat->roomsForUser($lee)->contains('id', $topic->id));

        $this->actingAs($lee)->post(route('communication.leave', ['room' => $topic]))
            ->assertRedirect(route('communication.index'));
        $this->assertFalse($this->chat->roomsForUser($lee)->contains('id', $topic->id), '나간 방이 목록에 남았습니다.');
    }

    public function test_another_companys_topic_room_stays_invisible(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $topic = $this->makeRoom($boss, CommunicationRoom::TYPE_TOPIC);

        $otherCo = Company::create(['code' => 'OTHER-CO', 'name' => 'Other', 'status' => 'active']);
        $stranger = $this->person('남', 'worker', $otherCo);

        $this->actingAs($stranger)->get(route('communication.browse'))->assertOk()->assertDontSee('3층 배관 이야기');
        $this->actingAs($stranger)->post(route('communication.join', ['room' => $topic]))->assertForbidden();
        $this->actingAs($stranger)->get(route('communication.show', ['room' => $topic]))->assertForbidden();
    }

    // ── 그룹방(비공개) ────────────────────────────────────────────────

    public function test_a_private_group_is_for_invited_people_only_even_for_admins(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');

        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        $this->assertFalse($this->chat->canAccessRoom($admin, $group), '관리자가 초대 없이 비공개 방에 들어갔습니다.');
        $this->assertFalse($this->chat->roomsForUser($admin)->contains('id', $group->id));
        $this->actingAs($lee)->get(route('communication.browse'))->assertDontSee('견적 검토');
        $this->actingAs($lee)->post(route('communication.join', ['room' => $group]))->assertForbidden();

        // 방 사람이 초대하면 들어온다 — 초대받은 사람의 활동함에도 남는다.
        $this->actingAs($boss)->postJson(route('communication.invite', ['room' => $group]), ['employee_id' => $lee->employee_id])
            ->assertOk();
        $this->assertTrue($this->chat->canAccessRoom($lee, $group));
        $this->assertSame(1, CommunicationNotification::query()
            ->where('employee_id', $lee->employee_id)->where('type', CommunicationNotification::TYPE_INVITE)->count());
    }

    public function test_only_people_inside_a_room_can_invite(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $kim = $this->person('김철수');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');

        $this->actingAs($lee)->postJson(route('communication.invite', ['room' => $group]), ['employee_id' => $kim->employee_id])
            ->assertForbidden();
        $this->assertFalse($this->chat->canAccessRoom($kim, $group));
    }

    public function test_the_whole_site_is_never_poured_into_a_topic_or_group_room(): void
    {
        // "구성원 동기화" 는 현장 전원을 방에 넣는다 — 비공개 방에 쓰면 비공개가 깨진다.
        $boss = $this->person('박반장', 'site_manager');
        $this->person('이영희');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');
        $group->update(['site_id' => $this->site->id]);

        $this->actingAs($boss);
        $result = app(CommunicationAdminService::class)->syncRoomMembers($group->id);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $group->activeMembers()->count());
    }

    public function test_a_topic_room_nobody_could_find_is_not_created(): void
    {
        // 직원 기록도 회사도 없는 관리자 계정이 현장도 안 고르면, 어느 회사의 방인지 알 수 없다.
        // 그런 방은 아무도 "방 찾기" 에서 못 찾으므로 만들지 않고 현장을 골라 달라고 한다.
        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        $this->actingAs($admin)->post(route('communication.room.store'), [
            'name' => '어디에도 없는 방', 'type' => CommunicationRoom::TYPE_TOPIC,
        ])->assertRedirect();
        $this->assertNull(CommunicationRoom::query()->where('name', '어디에도 없는 방')->first());

        $this->actingAs($admin)->post(route('communication.room.store'), [
            'name' => '현장 고른 방', 'type' => CommunicationRoom::TYPE_TOPIC, 'site_id' => $this->site->id,
        ])->assertRedirect();
        $this->assertSame($this->company->id, (int) CommunicationRoom::query()->where('name', '현장 고른 방')->value('company_id'));
    }

    public function test_an_admin_without_an_employee_record_still_owns_the_room_they_make(): void
    {
        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $group = $this->makeRoom($admin, CommunicationRoom::TYPE_GROUP, '관리자 방');
        $lee = $this->person('이영희');

        $this->assertTrue($this->chat->canAccessRoom($admin, $group), '만든 사람이 자기 비공개 방에 못 들어갑니다.');
        $this->actingAs($admin)->postJson(route('communication.invite', ['room' => $group]), ['employee_id' => $lee->employee_id])
            ->assertOk();
    }

    public function test_the_admin_screen_does_not_list_private_conversations(): void
    {
        // 메신저는 1:1 · 그룹방을 "명단에 있는 사람만" 이라고 약속한다. 관리 화면이 그 글을
        // 보여 주면 그 약속이 거짓말이 된다.
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');
        $this->chat->postMessage($boss, $group, '비공개 단가 논의');
        $dm = $this->chat->directRoomFor($boss->employee, $lee->employee);
        $this->chat->postMessage($lee, $dm, '개인 사정으로 늦습니다');
        $siteRoom = $this->chat->ensureSiteRooms($this->site)['chat'];
        $this->chat->postMessage($lee, $siteRoom, '3층 배관 완료');

        $admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($admin);
        $bodies = array_column(app(CommunicationAdminService::class)->list()['messages'], 'body');

        $this->assertContains('3층 배관 완료', $bodies);
        $this->assertNotContains('비공개 단가 논의', $bodies, '그룹방 글이 관리 화면에 나왔습니다.');
        $this->assertNotContains('개인 사정으로 늦습니다', $bodies, '1:1 글이 관리 화면에 나왔습니다.');
    }

    public function test_a_private_groups_files_stay_out_of_the_company_hub(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake((string) config('document-intelligence.disk', 'local'));
        $boss = $this->person('박반장', 'site_manager');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');
        $message = $this->chat->postMessage($boss, $group, '');

        $file = app(\App\Services\Communication\ChatAttachmentService::class)
            ->attach($message, \Illuminate\Http\UploadedFile::fake()->image('quote.jpg'), $boss);

        $this->assertNull($file->intelligent_document_id, '비공개 방의 파일이 회사 문서함으로 넘어갔습니다.');
    }

    public function test_standing_rooms_cannot_be_left(): void
    {
        // 현장방·공지방은 지시가 오가는 방이다 — 나가면 지시를 못 받는다.
        $lee = $this->person('이영희');
        $siteRoom = $this->chat->ensureSiteRooms($this->site)['chat'];

        $this->actingAs($lee)->post(route('communication.leave', ['room' => $siteRoom]))->assertForbidden();
    }

    public function test_the_room_topic_is_edited_by_its_owner_not_by_everyone(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $topic = $this->makeRoom($boss, CommunicationRoom::TYPE_TOPIC);
        $this->chat->joinRoom($lee, $topic);

        $this->actingAs($lee)->patchJson(route('communication.about', ['room' => $topic]), ['description' => '잡담방'])
            ->assertForbidden();
        $this->actingAs($boss)->patchJson(route('communication.about', ['room' => $topic]), ['description' => '3층 배관 — 도면 Rev 는 고정 글 참고'])
            ->assertOk();

        $this->assertSame('3층 배관 — 도면 Rev 는 고정 글 참고', $topic->fresh()->description);
    }

    // ── 스레드 ────────────────────────────────────────────────────────

    public function test_replies_fold_under_the_original_and_its_count_reaches_open_screens(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $room = $this->chat->ensureSiteRooms($this->site)['chat'];

        $question = $this->chat->postMessage($lee, $room, '3층 슬리브 위치 확인 부탁');
        $first = $this->actingAs($lee)->getJson(route('communication.stream', ['room' => $room, 'after' => 0]))->json();

        $this->travel(10)->seconds();
        $this->chat->postMessage($boss, $room, '도면대로 갑니다', ['parent_id' => $question->id]);

        $next = $this->actingAs($lee)->getJson(route('communication.stream', [
            'room' => $room, 'after' => $first['lastId'], 'changed' => $first['cursor'],
        ]))->json();

        $parentRow = collect($next['messages'])->firstWhere('id', $question->id);
        $this->assertNotNull($parentRow, '답글이 달렸는데 원글이 다시 오지 않아 "답글 N개" 가 그대로입니다.');
        $this->assertSame(1, $parentRow['replyCount']);

        $reply = collect($next['messages'])->firstWhere('body', '도면대로 갑니다');
        $this->assertFalse($reply['broadcast'], '방에도 보내기를 고르지 않은 답글은 접혀야 합니다.');
    }

    public function test_the_thread_view_has_the_original_and_every_reply(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $lee = $this->person('이영희');
        $room = $this->chat->ensureSiteRooms($this->site)['chat'];
        $question = $this->chat->postMessage($lee, $room, '자재 언제 와요?');
        $this->chat->postMessage($boss, $room, '내일 오전', ['parent_id' => $question->id]);
        $this->chat->postMessage($lee, $room, '감사합니다', ['parent_id' => $question->id]);

        $data = $this->actingAs($lee)->getJson(route('communication.thread', ['room' => $room, 'message' => $question]))
            ->assertOk()->json();

        $this->assertSame($question->id, $data['parent']['id']);
        $this->assertSame(['내일 오전', '감사합니다'], array_column($data['replies'], 'body'));
    }

    public function test_a_reply_can_also_be_sent_to_the_room(): void
    {
        $lee = $this->person('이영희');
        $room = $this->chat->ensureSiteRooms($this->site)['chat'];
        $question = $this->chat->postMessage($lee, $room, '3층 출입 통제 언제까지?');

        $this->actingAs($lee)->postJson(route('communication.store', ['room' => $room]), [
            'body' => '17시까지로 확정', 'parent_id' => $question->id, 'broadcast' => '1',
        ])->assertOk()->assertJsonPath('success', true);

        $reply = CommunicationMessage::query()->where('body', '17시까지로 확정')->firstOrFail();
        $this->assertTrue((bool) ($reply->payload['broadcast'] ?? false));

        $row = collect($this->actingAs($lee)->getJson(route('communication.stream', ['room' => $room, 'after' => 0]))->json('messages'))
            ->firstWhere('id', $reply->id);
        $this->assertTrue($row['broadcast']);
    }

    public function test_someone_outside_the_room_cannot_read_a_thread(): void
    {
        $boss = $this->person('박반장', 'site_manager');
        $group = $this->makeRoom($boss, CommunicationRoom::TYPE_GROUP, '견적 검토');
        $question = $this->chat->postMessage($boss, $group, '단가 확인');
        $lee = $this->person('이영희');

        $this->actingAs($lee)->getJson(route('communication.thread', ['room' => $group, 'message' => $question]))->assertForbidden();
    }
}
