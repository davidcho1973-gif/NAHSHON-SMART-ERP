<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use App\Services\Push\ChatPushNotifier;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 폰이 주머니에 있어도 지시가 닿는다.
 *
 * 현장 작업자는 화면을 계속 보고 있지 않다. 긴급 지시가 방에 올라와도 앱을 열어야만
 * 보인다면 그 지시는 전달된 것이 아니다 — 실시간 수신보다 푸시가 먼저인 이유다.
 *
 * 여기서 지키는 것은 "울려야 할 때 울리고, 울리지 말아야 할 때 조용한가" 이다.
 * 알림이 소음이 되면 사람들은 알림을 끄고, 그러면 정작 급한 것도 묻힌다.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.webpush.public_key' => 'test-public-key',
            'services.webpush.private_key' => 'test-private-key',
            'services.webpush.subject' => 'mailto:admin@example.com',
        ]);

        $this->company = Company::create(['code' => 'PUSH-CO', 'name' => 'Push Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'PUSH-SITE', 'name' => '현장', 'status' => 'active',
        ]);
    }

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

        return [$employee, $user];
    }

    private function room(): CommunicationRoom
    {
        return CommunicationRoom::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'type' => CommunicationRoom::TYPE_SITE_CHAT, 'name' => '현장 채팅방', 'status' => 'active',
        ]);
    }

    /** 실제 발송 대신 "누구에게 무엇을 보내려 했는지" 만 붙잡는다. */
    private function captureSends(): object
    {
        $spy = new class
        {
            public array $calls = [];
        };

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('available')->andReturn(true);
        $sender->shouldReceive('sendToUsers')->andReturnUsing(function ($users, $payload) use ($spy): int {
            $spy->calls[] = ['users' => collect($users)->all(), 'payload' => $payload];

            return count(collect($users)->all());
        });
        $this->app->instance(WebPushSender::class, $sender);

        return $spy;
    }

    // ── 기기 등록 ──────────────────────────────────────────────────────

    public function test_a_device_registers_itself_for_notifications(): void
    {
        [, $user] = $this->member('worker1@example.com');

        $this->actingAs($user)->postJson(route('push.subscribe'), [
            'endpoint' => 'https://push.example.com/abc123',
            'keys' => ['p256dh' => 'public-part', 'auth' => 'auth-part'],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, PushSubscription::query()->where('user_id', $user->id)->count());
    }

    public function test_the_same_device_registering_twice_does_not_ring_twice(): void
    {
        [, $user] = $this->member('worker2@example.com');
        $payload = [
            'endpoint' => 'https://push.example.com/same-device',
            'keys' => ['p256dh' => 'public-part', 'auth' => 'auth-part'],
        ];

        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), '같은 기기가 두 번 등록되면 알림이 두 번 옵니다.');
    }

    public function test_the_public_key_is_offered_but_the_private_one_never_is(): void
    {
        [, $user] = $this->member('worker3@example.com');

        $response = $this->actingAs($user)->getJson(route('push.key'))->assertOk();

        $response->assertJsonPath('available', true);
        $response->assertJsonPath('publicKey', 'test-public-key');
        $this->assertStringNotContainsString('test-private-key', $response->getContent(),
            '비밀키가 화면으로 새어 나갑니다.');
    }

    public function test_a_stranger_cannot_register_a_device(): void
    {
        $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://push.example.com/x',
            'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertUnauthorized();
    }

    // ── 언제 울리는가 ──────────────────────────────────────────────────

    public function test_posting_a_message_rings_the_others_but_not_the_sender(): void
    {
        $spy = $this->captureSends();
        $room = $this->room();
        [$senderEmployee, $senderUser] = $this->member('sender@example.com');
        [$otherEmployee, $otherUser] = $this->member('other@example.com');

        $service = app(CommunicationService::class);
        $service->ensureRoomMember($room, $senderEmployee);
        $service->ensureRoomMember($room, $otherEmployee);

        $service->postMessage($senderUser, $room, '3층 동편 배관 완료했습니다');

        $this->assertCount(1, $spy->calls, '메시지를 올렸는데 아무에게도 알림이 가지 않았습니다.');
        $this->assertSame([$otherUser->id], $spy->calls[0]['users']);
        $this->assertStringContainsString('3층 동편', $spy->calls[0]['payload']['body']);
        $this->assertStringContainsString('현장 채팅방', $spy->calls[0]['payload']['title']);
        $this->assertSame("/attendance-app/messages/{$room->id}", $spy->calls[0]['payload']['url']);
    }

    public function test_an_urgent_message_is_marked_so_the_phone_treats_it_differently(): void
    {
        $spy = $this->captureSends();
        $room = $this->room();
        [$senderEmployee, $senderUser] = $this->member('boss@example.com');
        [$otherEmployee] = $this->member('crew@example.com');

        $service = app(CommunicationService::class);
        $service->ensureRoomMember($room, $senderEmployee);
        $service->ensureRoomMember($room, $otherEmployee);

        $service->postMessage($senderUser, $room, '전원 대피', ['priority' => 'urgent']);

        $this->assertSame('urgent', $spy->calls[0]['payload']['priority']);
        $this->assertStringContainsString('🚨', $spy->calls[0]['payload']['title']);
    }

    public function test_routine_robot_replies_stay_quiet(): void
    {
        // AI 가 문서를 읽을 때마다 폰이 울리면 아무도 알림을 안 본다.
        $spy = $this->captureSends();
        $room = $this->room();
        [$employee] = $this->member('quiet@example.com');
        app(CommunicationService::class)->ensureRoomMember($room, $employee);

        $robot = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🤖 문서 AI',
            'body' => "읽었습니다 — 영수증: Sunbelt\n• 재무 · 승인대기",
            'status' => 'active',
        ]);

        app(ChatPushNotifier::class)->notify($robot);

        $this->assertCount(0, $spy->calls, '평범한 로봇 답글까지 폰을 울렸습니다.');
    }

    public function test_but_a_robot_reply_that_needs_a_human_does_ring(): void
    {
        $spy = $this->captureSends();
        $room = $this->room();
        [$employee] = $this->member('needed@example.com');
        app(CommunicationService::class)->ensureRoomMember($room, $employee);

        $robot = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🤖 문서 AI',
            'body' => '⚠️ 두 AI 판독이 다릅니다(금액) — 승인 전에 원본을 확인해 주세요.',
            'status' => 'active',
        ]);

        app(ChatPushNotifier::class)->notify($robot);

        $this->assertCount(1, $spy->calls, '사람 확인이 필요한 경고가 조용히 묻혔습니다.');
    }

    public function test_a_photo_with_no_words_still_says_something_useful(): void
    {
        $spy = $this->captureSends();
        $room = $this->room();
        [$senderEmployee, $senderUser] = $this->member('photo@example.com');
        [$otherEmployee] = $this->member('viewer@example.com');
        $service = app(CommunicationService::class);
        $service->ensureRoomMember($room, $senderEmployee);
        $service->ensureRoomMember($room, $otherEmployee);

        $service->postMessage($senderUser, $room, '');

        $this->assertStringContainsString('새 메시지', $spy->calls[0]['payload']['body']);
    }

    // ── 키가 없는 배포 ─────────────────────────────────────────────────

    public function test_without_keys_everything_still_works_just_silently(): void
    {
        config(['services.webpush.public_key' => '', 'services.webpush.private_key' => '']);

        $room = $this->room();
        [$senderEmployee, $senderUser] = $this->member('nokeys@example.com');
        app(CommunicationService::class)->ensureRoomMember($room, $senderEmployee);

        // 알림이 불가능해도 메시지 전송 자체는 성공해야 한다.
        $message = app(CommunicationService::class)->postMessage($senderUser, $room, '키 없이도 글은 올라간다');

        $this->assertSame('키 없이도 글은 올라간다', $message->body);
        $this->assertSame(0, app(ChatPushNotifier::class)->notify($message));

        // 화면은 눌러도 아무 일 없는 버튼을 보여주면 안 된다.
        $this->actingAs($senderUser)->getJson(route('push.key'))->assertJsonPath('available', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
