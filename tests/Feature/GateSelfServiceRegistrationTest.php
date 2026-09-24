<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\WorkerDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 벽에 붙은 QR 을 찍은 사람은 <b>그 자리에서</b> 출근을 찍을 수 있어야 한다.
 *
 * 예전에는 등록이 «검증되지 않은» 기기 토큰을 발급했다. 게이트는 검증된 토큰만
 * 받으므로 그 토큰으로는 아무것도 못 찍고, 15분짜리 PIN 설정 링크를 따라가 PIN 을
 * 정해야만 첫 출근이 됐다. 15분을 놓치면 인사담당자가 링크를 다시 보내 줘야 했다 —
 * 등록은 «했는데» 출근은 못 하는 상태가 현장 입구에서 만들어졌다.
 *
 * 신원은 이미 이 자리에서 받는다: 본인이 자기 휴대폰으로 이름과 번호를 적고,
 * 이미 등록된 번호는 거부된다. 확인을 두 번 하지 않는다.
 */
class GateSelfServiceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $own = Company::create([
            'code' => 'OWN', 'name' => 'ABC MEP', 'status' => 'active',
            'company_type' => Company::TYPE_OWN,
        ]);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site', 'company_id' => $own->id,
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->post('/join/w/'.$this->site->id, array_merge([
            'full_name' => '이대웅',
            'phone' => '480-555-0142',
        ], $overrides));
    }

    public function test_registering_links_this_phone_and_opens_the_punch_button(): void
    {
        $response = $this->register()->assertOk();

        $employee = Employee::query()->sole();
        $device = WorkerDevice::query()->sole();

        $this->assertSame($employee->id, $device->employee_id);
        $this->assertNotNull($device->identity_verified_at, '검증되지 않은 토큰으로는 아무것도 못 찍는다');
        $this->assertSame('worker', $employee->user?->access_role);
        $this->assertSame('self', $employee->user?->access_scope);
        $this->assertAuthenticatedAs($employee->user);

        // 15분짜리 링크는 첫 출근의 길목에서 사라졌다.
        $this->assertDatabaseCount('auth_setup_tokens', 0);
        $response->assertSee($employee->user->id ? '출근 화면 열기' : '출근 화면 열기');
    }

    public function test_the_very_next_thing_a_new_worker_does_is_clock_in(): void
    {
        $token = $this->register()->viewData('deviceToken');
        $this->assertNotSame('', $token);

        $punch = $this->postJson('/gate/'.$this->site->id.'/punch', ['device_token' => $token]);

        $punch->assertOk()->assertJson(['success' => true, 'event' => 'clock_in']);
        $this->assertSame(1, AttendanceLog::query()->count());
    }

    /** 20분을 기다렸다 찍어도 된다 — 만료되는 것이 길목에 없다. */
    public function test_waiting_twenty_minutes_does_not_close_the_door(): void
    {
        $token = $this->register()->viewData('deviceToken');

        Carbon::setTestNow(now()->addMinutes(20));
        $punch = $this->postJson('/gate/'.$this->site->id.'/punch', ['device_token' => $token]);
        Carbon::setTestNow();

        $punch->assertOk()->assertJson(['success' => true]);
    }

    /**
     * 반장 폰으로 팀원을 등록해도 그 폰이 팀원의 출퇴근 열쇠가 되면 안 된다.
     * 브라우저가 «이 폰은 이미 다른 사람을 등록했다» 고 알리면 연결하지 않는다.
     */
    public function test_a_shared_phone_is_not_linked_to_the_second_person(): void
    {
        $first = $this->register()->viewData('employee');

        $response = $this->register([
            'full_name' => '박팀원',
            'phone' => '480-555-0199',
            'device_owner' => (string) $first->id,
        ])->assertOk();

        $second = Employee::query()->where('name', '박팀원')->sole();

        $this->assertSame('', $response->viewData('deviceToken'), '공용 휴대폰은 누구의 것으로도 연결하지 않는다');
        $this->assertSame(0, WorkerDevice::query()->where('employee_id', $second->id)->count());
        $this->assertNull($response->viewData('pinSetupUrl'));
        // 그 사람은 명단에는 올라간다 — 현장에 서 있는 사람이니까.
        $this->assertSame('active', $second->employment_status);
    }

    /** 지어낸 값은 «연결 안 됨» 쪽으로만 간다 — 거짓말로 남의 폰을 가져갈 수 없다. */
    public function test_a_made_up_owner_only_costs_the_liar_their_own_link(): void
    {
        $response = $this->register(['device_owner' => '999999'])->assertOk();

        $this->assertSame('', $response->viewData('deviceToken'));
        $this->assertSame(0, WorkerDevice::query()->count());
    }

    /** 등록 문은 출퇴근 문보다 좁다 — 벽에 붙은 QR 로 명단을 채울 수 없다. */
    public function test_the_registration_door_is_narrower_than_the_attendance_door(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->register(['full_name' => "작업자{$i}", 'phone' => '480-555-01'.(10 + $i)])->assertOk();
        }

        $this->register(['full_name' => '여섯번째', 'phone' => '480-555-0116'])->assertStatus(429);

        // 같은 주소에서 출퇴근 문은 계속 열려 있어야 한다 — 현장 WiFi 는 주소 하나를 나눠 쓴다.
        $this->postJson('/gate/'.$this->site->id.'/identify', ['last4' => '0111'])->assertOk();
    }
}
