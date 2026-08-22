<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\ChatFactFinder;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 대화방 AI 도우미 — 부를 때만 오고, 물어본 사람의 권한을 넘지 않는다.
 *
 * 여기서 가장 중요한 시험은 마지막 두 개다. 협력사 소장이 화면에서 못 보는 금액을
 * 대화방에서 물었다고 AI 가 답해 버리면, 권한 설계 전체가 대화방 하나로 무너진다.
 */
class ChatAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Company $ourCompany;
    private Company $partner;
    private Site $site;
    private CommunicationRoom $room;
    private User $manager;
    private User $vendorAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-key']);

        $this->ourCompany = Company::create(['code' => 'ASK-CO', 'name' => 'Own Co', 'status' => 'active']);
        $this->partner = Company::create(['code' => 'ASK-PT', 'name' => 'Partner Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->ourCompany->id, 'code' => 'ASK', 'name' => '3층 현장', 'status' => 'active',
        ]);

        $this->room = CommunicationRoom::query()->create([
            'company_id' => $this->ourCompany->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장 채팅방', 'status' => 'active',
        ]);

        $this->manager = $this->userIn($this->ourCompany, 'site_manager', 'boss@example.com');
        $this->vendorAdmin = $this->userIn($this->partner, 'vendor_admin', 'partner@example.com');
    }

    private function userIn(Company $company, string $role, string $email): User
    {
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'first_name' => 'A', 'last_name' => 'B', 'email' => $email, 'employment_status' => 'active',
        ]);
        app(CommunicationService::class)->ensureRoomMember($this->room, $employee);

        return User::factory()->create([
            'employee_id' => $employee->id,
            'access_role' => $role,
            'allowed_company_id' => $company->id,
            'account_status' => 'active',
        ]);
    }

    /** Claude 가 이렇게 답했다고 치고 — 실제 호출은 하지 않는다. */
    private function fakeClaude(string $answer = '공정표 기준 62% 입니다.'): void
    {
        Http::fake([
            '*api.anthropic.com*' => Http::response([
                'content' => [['type' => 'text', 'text' => $answer]],
                'stop_reason' => 'end_turn',
            ]),
        ]);
    }

    private function say(User $user, string $body): CommunicationMessage
    {
        return app(CommunicationService::class)->postMessage($user, $this->room, $body);
    }

    private function botReplies(): \Illuminate\Support\Collection
    {
        return CommunicationMessage::query()
            ->where('communication_room_id', $this->room->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->get()
            ->filter(fn (CommunicationMessage $m): bool => is_array($m->payload)
                && ($m->payload['bot'] ?? null) === ChatAssistant::BOT_MARKER);
    }

    // ── 부르는 방법 ────────────────────────────────────────────────────

    public function test_it_recognises_the_ways_people_call_the_assistant(): void
    {
        $assistant = app(ChatAssistant::class);

        $this->assertTrue($assistant->mentioned('@AI 3층 배관 몇 %야?'));
        $this->assertTrue($assistant->mentioned('@ai 자재 언제 와?'));
        $this->assertTrue($assistant->mentioned('@에이아이 알려줘'));
        $this->assertTrue($assistant->mentioned('반장님 @AI 한테 물어보죠'));

        // 이메일 주소나 평범한 대화가 AI 를 부르는 것으로 오해되면 안 된다.
        $this->assertFalse($assistant->mentioned('kim@aircon.com 으로 보내주세요'));
        $this->assertFalse($assistant->mentioned('오늘 자재 들어왔습니다'));
        $this->assertFalse($assistant->mentioned(''));
    }

    public function test_it_strips_the_handle_so_only_the_question_is_asked(): void
    {
        $this->assertSame('3층 배관 몇 %야?', app(ChatAssistant::class)->questionOf('@AI 3층 배관 몇 %야?'));
    }

    public function test_it_stays_silent_when_nobody_called_it(): void
    {
        $this->fakeClaude();

        $this->say($this->manager, '오늘 배관 작업 끝냈습니다');

        $this->assertCount(0, $this->botReplies());
        Http::assertNothingSent();
    }

    // ── 답하기 ─────────────────────────────────────────────────────────

    public function test_it_answers_in_the_room_as_a_reply_to_the_question(): void
    {
        $this->fakeClaude('공정표 기준 62% 입니다.');

        $question = $this->say($this->manager, '@AI 3층 배관 공정 진행률 알려줘');
        app(ChatAssistant::class)->answer($question->fresh());

        $replies = $this->botReplies();
        $this->assertCount(1, $replies);
        $this->assertSame('공정표 기준 62% 입니다.', $replies->first()->body);
        $this->assertSame($question->id, $replies->first()->parent_id);
        $this->assertSame(ChatAssistant::DISPLAY_NAME, $replies->first()->title);
    }

    public function test_it_never_answers_the_same_question_twice(): void
    {
        $this->fakeClaude();

        $question = $this->say($this->manager, '@AI 공정 진행률?');
        app(ChatAssistant::class)->answer($question->fresh());
        app(ChatAssistant::class)->answer($question->fresh());

        $this->assertCount(1, $this->botReplies());
    }

    public function test_it_does_not_answer_its_own_reply(): void
    {
        $this->fakeClaude('@AI 라고 쓰면 부를 수 있습니다.');

        $question = $this->say($this->manager, '@AI 어떻게 부르지?');
        $reply = app(ChatAssistant::class)->answer($question->fresh());

        // 답변 안에 @AI 가 들어 있어도 되묻지 않는다 — 그러면 방이 끝없이 돈다.
        $this->assertNotNull($reply);
        $this->assertFalse(app(ChatAssistant::class)->shouldAnswer($reply->fresh()));
    }

    public function test_it_asks_back_when_the_handle_arrives_with_no_question(): void
    {
        $this->fakeClaude();

        $question = $this->say($this->manager, '@AI');
        app(ChatAssistant::class)->answer($question->fresh());

        $this->assertStringContainsString('무엇을 도와드릴까요', (string) $this->botReplies()->first()->body);
        Http::assertNothingSent();
    }

    public function test_it_says_nothing_when_the_deployment_has_no_key(): void
    {
        config(['services.anthropic.api_key' => '']);

        $question = $this->say($this->manager, '@AI 공정 진행률?');

        $this->assertFalse(app(ChatAssistant::class)->shouldAnswer($question->fresh()));
        $this->assertNull(app(ChatAssistant::class)->answer($question->fresh()));
    }

    // ── 참여자 목록 ────────────────────────────────────────────────────

    public function test_the_assistant_appears_as_a_participant_but_is_not_counted_as_a_person(): void
    {
        $presence = app(CommunicationService::class)->presence($this->room);

        $this->assertSame(ChatAssistant::DISPLAY_NAME, $presence[0]['name']);
        $this->assertTrue($presence[0]['bot']);

        $stream = app(\App\Services\Communication\RoomStreamService::class)->since($this->room, $this->manager, 0);
        $this->assertSame(2, $stream['membersCount'], 'AI 는 참여자 목록에는 있지만 "몇 명" 에는 세지 않는다');
    }

    public function test_the_assistant_is_hidden_when_the_deployment_has_no_key(): void
    {
        config(['services.anthropic.api_key' => '']);

        $names = array_column(app(CommunicationService::class)->presence($this->room), 'name');

        $this->assertNotContains(ChatAssistant::DISPLAY_NAME, $names);
    }

    // ── 권한 ───────────────────────────────────────────────────────────

    public function test_it_refuses_money_questions_from_someone_without_finance_rights(): void
    {
        MobileExpense::create([
            'company_id' => $this->ourCompany->id, 'site_id' => $this->site->id,
            'employee_id' => $this->manager->employee_id, 'payment_type' => 'personal', 'category' => 'material',
            'description' => '배관 자재', 'amount' => 4200, 'expense_date' => now()->toDateString(),
            'status' => 'submitted',
        ]);

        $gathered = app(ChatFactFinder::class)->gather('이번 달 비용 얼마야?', $this->room, $this->vendorAdmin);

        $this->assertArrayNotHasKey('비용', $gathered['facts'], '재무 권한이 없는 사람에게 금액이 새면 안 된다');
        $this->assertNotEmpty($gathered['denied'], '막았으면 막았다고 알려야 한다 — 조용히 빼면 거짓말이 된다');
    }

    public function test_it_hands_money_facts_to_someone_who_may_see_them(): void
    {
        MobileExpense::create([
            'company_id' => $this->ourCompany->id, 'site_id' => $this->site->id,
            'employee_id' => $this->manager->employee_id, 'payment_type' => 'personal', 'category' => 'material',
            'description' => '배관 자재', 'amount' => 4200, 'expense_date' => now()->toDateString(),
            'status' => 'submitted',
        ]);

        $admin = $this->userIn($this->ourCompany, 'admin', 'admin@example.com');
        $gathered = app(ChatFactFinder::class)->gather('이번 달 비용 얼마야?', $this->room, $admin);

        $this->assertSame(1, $gathered['facts']['비용']['승인 대기 건수']);
        $this->assertSame([], $gathered['denied']);
    }

    public function test_a_partner_admin_cannot_reach_another_companys_site_through_the_assistant(): void
    {
        WbsItem::create([
            'project_code' => 'P-1', 'site_id' => $this->site->id, 'company_id' => $this->ourCompany->id,
            'wbs_code' => '3.1', 'name' => '3층 배관', 'progress' => 62, 'status' => 'in_progress',
        ]);

        // 현장은 우리 회사 것이다 — 협력사 관리자에게는 그 현장 자체가 보이지 않는다.
        $gathered = app(ChatFactFinder::class)->gather('3층 배관 공정 진행률?', $this->room, $this->vendorAdmin);

        $this->assertNull($gathered['site']);
        $this->assertArrayNotHasKey('공정', $gathered['facts']);
    }

    public function test_a_worker_gets_only_their_own_attendance_not_the_whole_roster(): void
    {
        $worker = $this->userIn($this->ourCompany, 'worker', 'worker@example.com');

        $gathered = app(ChatFactFinder::class)->gather('오늘 출근 인원 몇 명이야?', $this->room, $worker);

        $this->assertArrayNotHasKey('오늘 출역', $gathered['facts'], '옆 사람이 몇 시에 왔는지는 그 사람의 일이다');
        $this->assertNotEmpty($gathered['denied']);
    }

    // ── 무엇을 뒤질지 고르기 ───────────────────────────────────────────

    public function test_it_only_looks_up_the_modules_the_question_is_about(): void
    {
        $finder = app(ChatFactFinder::class);

        $this->assertSame(['wbs'], $finder->topicsIn('3층 배관 공정 진행률?'));
        $this->assertSame(['procurement'], $finder->topicsIn('자재 언제 들어와?'));
        $this->assertSame(['money'], $finder->topicsIn('이번 달 경비 얼마야?'));
        $this->assertSame([], $finder->topicsIn('수고하셨습니다'));
    }
}
