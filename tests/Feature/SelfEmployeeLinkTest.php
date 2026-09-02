<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자도 현장 사람이다 — 자기 직원 정보를 여기서 만든다.
 *
 * 관리자 계정에는 직원 기록이 안 붙어 있어서 앱을 처음 열면 «연결 대기 중» 이 뜨는데,
 * 화면이 하던 말은 «ERP 로 가세요» 또는 «관리자에게 부탁하세요» 였다. 앱 관리를 겸하는
 * 소장에게는 둘 다 쓸모가 없다 — 그 사람이 바로 그 관리자이고, 그 사람도 출퇴근을
 * 찍고 보고를 올리고 영수증을 낸다.
 */
class SelfEmployeeLinkTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'SL-CO', 'name' => 'Self Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'SL', 'name' => '자가현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function actor(string $role, ?string $email = null): User
    {
        return User::factory()->create([
            'access_role' => $role, 'account_status' => 'active',
            'employee_id' => null, 'email' => $email ?: $role.'@example.com',
            'name' => '조사장',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => '조사장',
            'siteId' => $this->site->id,
            'position' => 'superintendent',
        ], $over);
    }

    public function test_an_admin_can_make_their_own_worker_record_and_use_the_app(): void
    {
        $admin = $this->actor('super_admin');

        $res = $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload());

        $res->assertOk()->assertJson(['success' => true]);

        $admin->refresh();
        $this->assertNotNull($admin->employee_id);

        $employee = $admin->employee;
        $this->assertSame('조사장', $employee->name);
        $this->assertSame($this->site->id, $employee->site_id);
        $this->assertSame($this->company->id, $employee->company_id);
        // 관리직이다. 시급 근로자로 잡히면 급여 계산에 잘못 들어간다.
        $this->assertSame(Employee::TYPE_STAFF, $employee->employment_type);
        $this->assertSame('superintendent', $employee->position);
    }

    public function test_making_a_worker_record_never_changes_my_permissions(): void
    {
        // 최고 관리자가 자기 손으로 자기 권한을 떨어뜨리는 일이 생기면 안 된다.
        $admin = $this->actor('super_admin');

        $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload())->assertOk();

        $this->assertSame('super_admin', $admin->fresh()->access_role);
    }

    public function test_a_trade_gives_me_my_own_slot_in_the_daily_report(): void
    {
        $admin = $this->actor('admin');

        $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload([
            'trade' => 'Piping',
        ]))->assertOk();

        $this->assertSame('Piping', $admin->fresh()->employee->role);
    }

    public function test_a_plain_worker_cannot_write_themselves_into_the_payroll(): void
    {
        // 직원 기록은 급여가 매달리는 자리다. 로그인만 하면 스스로 직원이 될 수 있다면,
        // 구글 계정 하나로 급여 대장에 줄을 만들 수 있다는 뜻이 된다.
        $worker = $this->actor('worker');

        $this->actingAs($worker)->postJson(route('attendance-app.self-link'), $this->payload())
            ->assertOk()->assertJson(['success' => false]);

        $this->assertNull($worker->fresh()->employee_id);
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_it_links_an_existing_record_instead_of_making_a_second_one(): void
    {
        // 누군가 ERP 에서 먼저 만들어 두었을 수 있다. 그때 둘이 되면 급여가 갈린다.
        $admin = $this->actor('super_admin', 'boss@example.com');
        $existing = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '조사장', 'email' => 'boss@example.com', 'employment_status' => 'active',
        ]);

        $res = $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload());

        $res->assertOk()->assertJson(['success' => true, 'linkedExisting' => true]);
        $this->assertSame($existing->id, $admin->fresh()->employee_id);
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_a_site_must_be_chosen_because_work_is_filed_by_site(): void
    {
        $admin = $this->actor('super_admin');

        $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload(['siteId' => 0]))
            ->assertOk()->assertJson(['success' => false]);

        $this->assertNull($admin->fresh()->employee_id);
    }

    public function test_doing_it_twice_does_not_make_a_second_record(): void
    {
        $admin = $this->actor('super_admin');

        $this->actingAs($admin)->postJson(route('attendance-app.self-link'), $this->payload())->assertOk();
        $this->actingAs($admin->fresh())->postJson(route('attendance-app.self-link'), $this->payload())
            ->assertOk()->assertJson(['success' => false]);

        $this->assertSame(1, Employee::query()->count());
    }

    public function test_the_screen_offers_the_form_instead_of_telling_me_to_ask_someone_else(): void
    {
        $admin = $this->actor('super_admin');

        $res = $this->actingAs($admin)->getJson(route('attendance-app.home'));

        $res->assertStatus(422)->assertJson(['code' => 'no_employee', 'canSelfLink' => true]);
        // 현장 목록이 함께 와야 화면이 고를 것을 그릴 수 있다.
        $this->assertNotEmpty($res->json('selfLink.sites'));
    }

    public function test_a_worker_is_still_told_to_ask_someone(): void
    {
        $worker = $this->actor('worker');

        $this->actingAs($worker)->getJson(route('attendance-app.home'))
            ->assertStatus(422)
            ->assertJson(['code' => 'no_employee', 'canSelfLink' => false]);
    }
}
