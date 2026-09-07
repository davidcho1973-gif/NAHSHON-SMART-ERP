<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 「누가 알림을 켰는가」 — 직원 목록이 답한다.
 *
 * 출근 독려·퇴근·보고 알림은 그 사람이 자기 폰에서 <b>한 번 허락해야만</b> 닿는다.
 * 브라우저 규칙이라 관리자가 대신 켤 수 없다. 그래서 남는 질문은 하나다 —
 * <b>누구를 도와줘야 하는가.</b> 그게 안 보이면 소장은 전원에게 같은 말을 반복하거나
 * 아무에게도 안 하게 된다.
 *
 * 여기서 지키는 것 셋:
 *   1. 켠 사람과 안 켠 사람이 구분된다
 *   2. «계정 없음»과 «꺼짐»이 구분된다 — 할 일이 다르다(계정 만들기 vs 설치 도와주기)
 *   3. 인원이 늘어도 질의가 안 늘어난다 — 수백 명 현장에서 목록이 죽지 않는다
 */
class EmployeePushVisibilityTest extends TestCase
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
        ]);

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function svc(): EmployeeAdminService
    {
        return app(EmployeeAdminService::class);
    }

    private function employee(string $name): Employee
    {
        return Employee::create([
            'name' => $name, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'employment_status' => 'active', 'employment_type' => 'direct',
        ]);
    }

    /** 이 직원에게 로그인 계정을 붙인다 — 계정이 있어야 알림을 켤 수 있다. */
    private function account(Employee $employee): User
    {
        return User::factory()->create([
            'access_role' => 'worker', 'account_status' => 'active', 'employee_id' => $employee->id,
        ]);
    }

    private function device(User $user, string $endpoint): void
    {
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'p256dh', 'auth_token' => 'auth',
            'last_used_at' => now(),
        ]);
    }

    private function rowFor(array $result, string $name): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("목록에 {$name} 이 없습니다.");
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]));
    }

    public function test_it_separates_who_turned_notifications_on_from_who_did_not(): void
    {
        $on = $this->employee('켠 사람');
        $this->device($this->account($on), 'https://push.example/on-1');

        $off = $this->employee('안 켠 사람');
        $this->account($off);

        $this->actAsAdmin();
        $result = $this->svc()->list();

        $this->assertSame(1, $this->rowFor($result, '켠 사람')['pushDevices']);
        $this->assertSame(0, $this->rowFor($result, '안 켠 사람')['pushDevices']);
    }

    public function test_a_person_with_two_devices_is_counted_as_two(): void
    {
        // 폰과 태블릿을 함께 쓰면 줄이 둘이다. 사람 수가 아니라 기기 수를 센다.
        $employee = $this->employee('두 기기');
        $user = $this->account($employee);
        $this->device($user, 'https://push.example/phone');
        $this->device($user, 'https://push.example/tablet');

        $this->actAsAdmin();

        $this->assertSame(2, $this->rowFor($this->svc()->list(), '두 기기')['pushDevices']);
    }

    public function test_no_account_is_not_the_same_as_notifications_off(): void
    {
        // 둘 다 알림은 안 오지만 소장이 할 일이 다르다 —
        // 계정이 없으면 계정부터 만들어야 하고, 있으면 설치를 도와주면 된다.
        $this->employee('계정 없는 사람');

        $this->actAsAdmin();
        $row = $this->rowFor($this->svc()->list(), '계정 없는 사람');

        $this->assertFalse($row['hasAccount']);
        $this->assertSame(0, $row['pushDevices']);
    }

    public function test_it_says_when_the_server_itself_cannot_send(): void
    {
        // 열쇠가 없으면 전원이 «꺼짐» 으로 보인다. 그건 사람들이 안 켠 게 아니라
        // 서버가 못 보내는 것이다 — 화면이 그 차이를 말하지 않으면 소장이 애먼
        // 사람들을 쫓아다니게 된다.
        $this->actAsAdmin();
        $this->assertTrue($this->svc()->list()['pushReady']);

        config(['services.webpush.public_key' => '', 'services.webpush.private_key' => '']);
        $this->assertFalse($this->svc()->list()['pushReady']);
    }

    public function test_more_people_does_not_mean_more_queries(): void
    {
        // 직원 줄마다 구독을 세면 수백 명 현장에서 목록이 죽는다. 한 번에 세어 붙인다.
        $this->actAsAdmin();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->svc()->list();
            $queries = count(DB::getRawQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $this->employee('한 명');
        $this->device($this->account($one), 'https://push.example/1');
        $withOne = $count();

        for ($i = 2; $i <= 6; $i++) {
            $e = $this->employee('사람 '.$i);
            $this->device($this->account($e), 'https://push.example/'.$i);
        }
        $withSix = $count();

        // 세는 장치가 죽으면 둘 다 0 이 되어 이 시험이 조용히 통과한다 — 그건 시험이 아니다.
        $this->assertGreaterThan(0, $withOne, '질의를 하나도 못 셌다 — 이 시험은 아무것도 지키지 못하고 있다.');
        $this->assertSame($withOne, $withSix, '인원이 늘었는데 질의가 늘었다 — 구독을 줄마다 세고 있다(N+1).');
    }
}
