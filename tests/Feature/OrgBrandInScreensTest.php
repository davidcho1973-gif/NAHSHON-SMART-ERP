<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OrgSetting;
use App\Models\Site;
use App\Models\User;
use App\Support\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 이름을 바꾸면 <b>화면이</b> 따라오는가.
 *
 * OrgIdentityTest 는 값이 어디서 오는지를 본다. 여기서는 실제로 열리는 화면을 연다.
 * 값은 맞는데 화면이 그 값을 안 쓰는 경우가 가장 흔한 실패라, 한 화면씩 눈으로
 * 확인하는 대신 여기에 적어 둔다.
 *
 * 화면 하나라도 빠지면 그 화면은 다음 고객에게 남의 회사 이름을 보여 준다.
 */
class OrgBrandInScreensTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'ABC 건설';

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        OrgSetting::query()->updateOrCreate(['key' => 'name'], ['value' => self::NAME]);
        Org::forget();

        $this->company = Company::create(['code' => 'ABC', 'name' => self::NAME, 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Org::forget();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '테스터', 'employment_status' => 'active',
        ]);
    }

    public function test_the_login_screen(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_gate_screen_workers_scan_at_the_entrance(): void
    {
        $this->get(route('gate.show', ['site' => $this->site]))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_quick_sign_up_form(): void
    {
        $this->get(route('worker-join.form', ['site' => $this->site]))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_erp_home(): void
    {
        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_w9_screen_a_worker_fills_in(): void
    {
        // 인쇄본(w9.blank)에는 회사 이름이 없다 — 국세청 양식에 없는 문구가 종이에
        // 섞이면 그 종이는 W-9 이 아니게 된다. 이름은 작성 화면에만 있다.
        $this->actingAs($this->admin())
            ->get(\Illuminate\Support\Facades\URL::signedRoute('w9.show', ['employee' => $this->employee()->id]))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_app_install_card_handed_to_a_worker(): void
    {
        $this->actingAs($this->admin())
            ->get(route('attendance-app.employee.install-card', ['employee' => $this->employee()]))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_logo_in_the_top_left_corner(): void
    {
        // 화면을 열면 가장 먼저 보이는 자리다. 여기에 남의 회사 머리글자가 떠 있으면
        // 나머지가 아무리 맞아도 고객은 이 화면이 자기 것이라고 느끼지 않는다.
        $body = $this->actingAs($this->admin())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="brand-logo">ABC</div>', $body);
        $this->assertStringNotContainsString('>NS<', $body, '옛 머리글자가 남아 있습니다.');
    }

    public function test_the_chosen_colour_reaches_the_screen(): void
    {
        OrgSetting::query()->updateOrCreate(['key' => 'color'], ['value' => '#16a34a']);
        Org::forget();

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertSee('--brand-primary: #16a34a;', false);
    }

    public function test_the_messenger_screen(): void
    {
        $this->actingAs($this->admin())->get(route('communication.index'))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_document_intelligence_screen(): void
    {
        $this->actingAs($this->admin())->get(route('document-intelligence.index'))
            ->assertOk()->assertSee(self::NAME, false);
    }

    public function test_the_erp_manifest_the_phone_reads(): void
    {
        $this->get(route('erp.manifest'))->assertOk()
            ->assertJsonPath('name', self::NAME.' SMART ERP');
    }

    public function test_no_screen_still_shows_the_old_name(): void
    {
        // 남은 화면이 하나라도 옛 이름을 보여 주면 이 작업은 절반만 된 것이다.

        // 로그인 화면은 로그인하기 전에 본다 — 로그인한 채로 열면 홈으로 튕긴다.
        $login = $this->get(route('login'))->assertOk()->getContent();
        foreach (['DASOL', 'NAHSHON', 'SMART COMPANY'] as $old) {
            $this->assertStringNotContainsString($old, $login, '로그인 화면에 옛 이름이 남아 있습니다.');
        }

        $this->actingAs($this->admin());

        foreach ([
            '/',
            route('gate.show', ['site' => $this->site]),
            route('worker-join.form', ['site' => $this->site]),
            route('communication.index'),
            route('document-intelligence.index'),
            route('erp.manifest'),
            route('worker-app.manifest'),
        ] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            foreach (['DASOL', 'NAHSHON', 'SMART COMPANY'] as $old) {
                $this->assertStringNotContainsString($old, $body, "옛 이름이 남아 있습니다: {$url}");
            }
        }
    }
}
