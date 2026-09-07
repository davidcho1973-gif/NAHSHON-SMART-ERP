<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 알림을 켜는 문은 <b>출퇴근 화면</b>에 있어야 한다.
 *
 * 겪은 일 — 아침 출근 알림·퇴근 알림·보고 독촉은 전부 만들어져 있었고 스케줄러도
 * 정상이었는데, 알림을 켜는 종이 «메시지» 화면에만 있었다. 알림이 가장 필요한 사람은
 * 출근을 까먹는 사람이고 그 사람은 메시지 화면을 열지 않는다. 그래서 등록된 기기가
 * 0대였고, 스케줄러는 매일 정확히 돌면서 아무에게도 아무것도 보내지 않았다 —
 * 화면은 어디를 봐도 멀쩡했다.
 *
 * 증상은 «알림이 안 온다» 였지만 원인은 «켤 자리가 없다» 였다. 그래서 고친 것은
 * 발송이 아니라 종의 위치이고, 여기서 잠그는 것도 그 위치다. 머리띠를 다시 손보는
 * 사람이 종을 지워도 이 시험이 먼저 깨진다.
 */
class WorkerAppPushOptInTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        // 열쇠가 있는 배포를 전제한다 — 없으면 종은 스스로 숨는 것이 정상이다(아래 마지막 시험).
        config([
            'services.webpush.public_key' => 'test-public-key',
            'services.webpush.private_key' => 'test-private-key',
        ]);

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function worker(): User
    {
        $employee = Employee::create([
            'site_id' => $this->site->id,
            'name' => '작업자',
            'email' => 'worker@example.com',
            'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'access_role' => 'worker',
            'account_status' => 'active',
            'employee_id' => $employee->id,
        ]);
    }

    public function test_the_attendance_screen_carries_the_bell_that_turns_notifications_on(): void
    {
        $this->actingAs($this->worker())->get('/attendance-app')
            ->assertStatus(200)
            // 종 자리. 이것이 없으면 partials/push-optin 이 살릴 버튼을 못 찾는다.
            ->assertSee('id="push-bell"', false)
            // 켜는 데 필요한 두 문 — 열쇠를 받아 오는 곳과 이 기기를 등록하는 곳.
            ->assertSee(route('push.key'), false)
            ->assertSee(route('push.subscribe'), false);
    }

    public function test_the_reason_to_turn_them_on_is_the_one_that_fits_this_screen(): void
    {
        // 출퇴근 화면에서 «새 메시지를 받습니다» 라고 하면, 정작 출근을 까먹는 사람은
        // 자기와 상관없는 안내로 읽고 닫는다.
        $this->actingAs($this->worker())->get('/attendance-app')
            ->assertStatus(200)
            ->assertSee('출근·퇴근 알림을 받습니다')
            ->assertDontSee('새 메시지를 받습니다');
    }

    public function test_the_messenger_screen_still_has_its_own_bell(): void
    {
        // 출퇴근 화면에 종을 옮긴 것이 아니라 더한 것이다. 원래 있던 문을 닫으면
        // 메시지 알림을 켜 둔 사람들의 경로가 사라진다.
        $this->actingAs($this->worker())->get(route('communication.index'))
            ->assertStatus(200)
            ->assertSee('id="push-bell"', false);
    }

    public function test_without_keys_the_screen_still_renders(): void
    {
        // 열쇠가 없는 배포(= 지금의 나손)에서도 출퇴근 화면은 멀쩡해야 한다.
        // 종은 push.key 응답을 보고 스스로 숨는다 — 눌러도 아무 일 없는 버튼은 만들지 않는다.
        config(['services.webpush.public_key' => '', 'services.webpush.private_key' => '']);

        $this->actingAs($this->worker())->get('/attendance-app')
            ->assertStatus(200)
            ->assertSee('id="push-bell"', false);
    }
}
