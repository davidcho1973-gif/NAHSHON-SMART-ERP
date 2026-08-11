<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OrgSetting;
use App\Models\User;
use App\Services\Admin\OrgSettingService;
use App\Support\Org;
use App\Support\WorkerLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 이 배포가 누구의 것인가 — 코드 하나로 고객마다 배포하기 위한 밑작업.
 *
 * 지키려는 것은 하나다. <b>고객사 이름이 코드에 없다.</b> 코드에 있으면 새 고객마다
 * 코드를 고치게 되고, 고친 코드는 갈라진다. 한 곳을 고쳐서 전부에 반영하는 길이
 * 막히는 순간 제품이 아니라 프로젝트 여러 개가 된다.
 *
 * 그래서 여기서는 "이름이 바뀌면 화면·문서·이메일이 따라 바뀌는가" 를 본다.
 * 화면 하나라도 안 따라오면 그 화면은 다음 고객에게 남의 회사 이름을 보여 준다.
 */
class OrgIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Org::forget();
    }

    protected function tearDown(): void
    {
        Org::forget();
        parent::tearDown();
    }

    private function rename(string $name): void
    {
        OrgSetting::query()->updateOrCreate(['key' => 'name'], ['value' => $name]);
        Org::forget();
    }

    // ── 값이 어디서 오는가 ──────────────────────────────────────────────

    public function test_it_falls_back_to_the_deployment_setting_when_nothing_is_saved(): void
    {
        config(['org.name' => 'ABC CONSTRUCTION']);
        Org::forget();

        $this->assertSame('ABC CONSTRUCTION', Org::name());
    }

    public function test_a_saved_value_wins_over_the_deployment_setting(): void
    {
        config(['org.name' => 'ABC CONSTRUCTION']);
        $this->rename('ABC 건설');

        $this->assertSame('ABC 건설', Org::name());
    }

    public function test_clearing_a_value_returns_to_the_deployment_setting(): void
    {
        // 빈 줄을 남기면 기본값으로 돌아갈 길이 없어진다 — 잘못 지운 이름을
        // 되돌릴 방법이 사라진다.
        config(['org.name' => 'ABC CONSTRUCTION']);
        $this->rename('오타');

        Org::put('name', '');

        $this->assertSame('ABC CONSTRUCTION', Org::name());
        $this->assertDatabaseMissing('org_settings', ['key' => 'name']);
    }

    public function test_the_short_and_legal_names_fall_back_to_the_main_name(): void
    {
        // 짧은 이름·법인명을 안 넣은 고객이 대부분이다. 비면 홈 화면 아이콘 밑과
        // 계약서가 빈칸이 된다.
        config(['org.short_name' => null, 'org.legal_name' => null]);
        $this->rename('ABC 건설');

        $this->assertSame('ABC 건설', Org::shortName());
        $this->assertSame('ABC 건설', Org::legalName());
    }

    public function test_it_survives_a_missing_table(): void
    {
        // 설치 전·마이그레이션 도중에도 이름을 묻는 코드가 있다. 여기서 예외가
        // 나면 앱이 통째로 안 뜬다.
        \Illuminate\Support\Facades\Schema::drop('org_settings');
        Org::forget();

        $this->assertNotSame('', Org::name());
    }

    // ── 이름이 바뀌면 따라오는가 ────────────────────────────────────────

    public function test_the_worker_sign_up_form_speaks_the_customers_name(): void
    {
        // 간편등록에서 "누가 급여를 주는가" 를 고르는 자리. 남의 회사 이름이
        // 있으면 작업자가 자기 소속을 잘못 고른다.
        $this->rename('ABC 건설');

        $join = WorkerLang::join();
        $this->assertStringContainsString('ABC 건설', $join['ko']['askDirect']);
        $this->assertSame('ABC 건설', $join['en']['askDirect']);
        $this->assertStringContainsString('ABC 건설', $join['es']['askDirectSub']);

        foreach (['ko', 'en', 'es'] as $lang) {
            $this->assertStringNotContainsString('DASOL', json_encode($join[$lang], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_the_worker_app_manifest_carries_the_customers_name(): void
    {
        $this->rename('ABC 건설');

        $this->get(route('worker-app.manifest'))->assertOk()->assertJsonFragment([
            'name' => '내 출퇴근 · ABC 건설',
        ]);
    }

    public function test_the_application_invitation_email_carries_the_customers_name(): void
    {
        $this->rename('ABC 건설');

        $registration = \App\Models\MemberRegistration::create([
            'full_name' => 'Test Applicant',
            'email' => 'applicant@example.com',
            'member_type' => 'worker',
        ]);

        $url = app(\App\Services\ApplicantInvitationService::class)
            ->mailtoUrl($registration, 'applicant@example.com');

        $this->assertStringContainsString(rawurlencode('ABC 건설'), $url);
        $this->assertStringNotContainsString('DASOL', urldecode($url));
    }

    public function test_the_seeder_names_the_own_company_from_settings(): void
    {
        // 시드가 회사 이름을 코드에서 가져오면, 새 고객 배포에 우리 회사가
        // 먼저 들어가 앉는다.
        config(['org.name' => 'ABC CONSTRUCTION', 'org.code' => 'ABC']);
        Org::forget();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $own = Company::query()->where('code', 'ABC')->first();
        $this->assertNotNull($own);
        $this->assertSame('ABC CONSTRUCTION', $own->name);
        $this->assertSame(Company::TYPE_OWN, $own->company_type);
    }

    // ── 코드에 이름이 남아 있지 않은가 ──────────────────────────────────

    public function test_no_customer_name_is_left_in_the_source(): void
    {
        // 이 테스트가 이 작업 전체의 목적이다. 한 줄이라도 남으면 그 줄은
        // 다음 고객에게 남의 회사 이름을 보여 준다.
        //
        // config/org.php 는 뺀다 — 기본값이 사는 곳이고, 거기 있어야 새 배포가
        // 환경변수 없이도 뜬다.
        $roots = [
            base_path('app'),
            base_path('routes'),
            base_path('resources/views'),
            base_path('public/js'),
            base_path('database/seeders'),
        ];

        // 예외는 이 둘뿐이다 — 휴대폰 안에만 있는 저장소 키다. 화면에도, 문서에도,
        // 이메일에도 나가지 않고 고객마다 도메인이 다르니 겹치지도 않는다. 반면
        // 이름을 바꾸면 이미 등록된 휴대폰이 "이 휴대폰 기억"을 잃어, 작업자가
        // 게이트에서 자기 이름을 다시 찾게 된다. 얻는 것 없이 잃기만 한다.
        $allowed = ['dasolWorkerDevice', 'dasolWorkerLang'];

        $hits = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $body = str_replace($allowed, '', (string) file_get_contents($file->getPathname()));
                if (stripos($body, 'DASOL') !== false) {
                    $hits[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $hits,
            "고객사 이름이 코드에 남아 있습니다:\n  ".implode("\n  ", $hits));
    }

    // ── 근무 규칙도 배포마다 다르다 ────────────────────────────────────

    public function test_the_indirect_cutoff_hour_comes_from_the_deployment_setting(): void
    {
        config(['org.attendance.indirect_cutoff_hour' => 18]);

        $this->assertSame(18, \App\Services\Attendance\AutoClockOutService::cutoffHour());
    }

    public function test_policy_numbers_fall_back_when_the_setting_is_junk(): void
    {
        // 환경변수는 사람이 손으로 넣는다. 오타 하나로 근무시간이 0 이 되면 안 된다.
        config(['org.attendance.indirect_cutoff_hour' => 'abc']);

        $this->assertSame(16, \App\Services\Attendance\AutoClockOutService::cutoffHour());
    }

    public function test_a_broken_time_setting_does_not_break_the_scheduler(): void
    {
        config(['org.attendance.evening_finalize_at' => '팔시']);

        $this->assertSame('20:00', Org::time('attendance.evening_finalize_at', '20:00'));
    }

    // ── 화면 ────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::factory()->create([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_only_the_top_administrator_may_change_who_this_deployment_is(): void
    {
        $svc = app(OrgSettingService::class);

        $this->actingAs($this->user('admin'));
        $this->assertFalse($svc->canManage());
        $this->assertFalse($svc->save(['name' => '남의 회사'])['success']);

        $this->actingAs($this->user('super_admin'));
        $this->assertTrue($svc->canManage());
    }

    public function test_the_screen_saves_and_reads_back(): void
    {
        $this->actingAs($this->user('super_admin'));
        $svc = app(OrgSettingService::class);

        $res = $svc->save([
            'name' => 'ABC 건설', 'short_name' => 'ABC',
            'support_email' => 'help@abc.com', 'color' => '#ff8800',
        ]);

        $this->assertTrue($res['success']);
        Org::forget();
        $this->assertSame('ABC 건설', Org::name());
        $this->assertSame('ABC', Org::shortName());
        $this->assertSame('#ff8800', Org::color());

        $names = collect($svc->load()['fields'])->keyBy('key');
        $this->assertSame('ABC 건설', $names['name']['value']);
    }

    public function test_the_name_may_not_be_emptied(): void
    {
        // 이름이 비면 화면·이메일·앱 이름이 통째로 빈다. 다른 항목과 다르다.
        $this->actingAs($this->user('super_admin'));

        $res = app(OrgSettingService::class)->save(['name' => '   ']);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('name', $res['errors']);
    }

    public function test_it_rejects_a_bad_colour_and_a_bad_email(): void
    {
        $this->actingAs($this->user('super_admin'));

        $res = app(OrgSettingService::class)->save([
            'name' => 'ABC', 'color' => 'red', 'support_email' => 'not-an-email',
        ]);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('color', $res['errors']);
        $this->assertArrayHasKey('support_email', $res['errors']);
    }

    public function test_it_does_not_save_the_fields_nobody_touched(): void
    {
        // 화면은 배포 설정에서 온 값을 미리 채워 보여 준다. 색 하나 고치려고 저장을
        // 눌렀을 뿐인데 회사 이름까지 표에 박히면, 나중에 환경변수로 이름을 바꿔도
        // 표에 남은 값이 이겨서 반영되지 않는다. 화면은 멀쩡해 보인다.
        config(['org.name' => 'ABC CONSTRUCTION']);
        Org::forget();
        $this->actingAs($this->user('super_admin'));

        app(OrgSettingService::class)->save([
            'name' => 'ABC CONSTRUCTION',   // 화면이 채워 준 그대로 — 안 건드렸다
            'color' => '#ff8800',           // 이것만 고쳤다
        ]);

        $this->assertDatabaseMissing('org_settings', ['key' => 'name']);
        $this->assertDatabaseHas('org_settings', ['key' => 'color']);

        // 나중에 배포 설정으로 이름을 바꾸면 그대로 반영된다.
        config(['org.name' => 'ABC 건설']);
        Org::forget();
        $this->assertSame('ABC 건설', Org::name());
    }

    public function test_an_empty_field_is_not_called_a_default(): void
    {
        // 빈칸에 "기본값 사용 중" 이라고 적으면 어딘가에 값이 있는 줄 알고 찾으러 간다.
        config(['org.support_phone' => null, 'org.legal_name' => null]);
        Org::forget();
        $this->actingAs($this->user('super_admin'));

        $fields = collect(app(OrgSettingService::class)->load()['fields'])->keyBy('key');

        $this->assertNull($fields['support_phone']['note']);
        $this->assertSame('회사 이름을 따라갑니다', $fields['legal_name']['note']);
        $this->assertSame('배포 설정값 사용 중', $fields['color']['note']);
    }

    public function test_a_saved_field_is_not_called_a_default(): void
    {
        $this->actingAs($this->user('super_admin'));
        app(OrgSettingService::class)->save(['name' => 'ABC 건설', 'color' => '#ff8800']);

        $fields = collect(app(OrgSettingService::class)->load()['fields'])->keyBy('key');

        $this->assertNull($fields['name']['note']);
        $this->assertNull($fields['color']['note']);
    }

    public function test_the_screen_shows_what_it_cannot_change(): void
    {
        // 화면에 아예 없으면 사람들은 있을 거라 생각하고 한참 찾는다.
        $this->actingAs($this->user('super_admin'));

        $labels = array_column(app(OrgSettingService::class)->load()['readOnly'], 'label');

        $this->assertContains('앱 주소', $labels);
        $this->assertContains('협력사 자동 퇴근', $labels);
    }

    public function test_the_deployment_reports_who_it_belongs_to(): void
    {
        // 배포를 열어 놓고 "이게 어느 고객 것이더라" 를 모르는 순간이 온다.
        $this->rename('ABC 건설');

        $this->get('/build-version')->assertOk()->assertJsonPath('org.name', 'ABC 건설');
    }

    // ── 새 고객 세우기 ──────────────────────────────────────────────────

    public function test_provisioning_creates_the_own_company_and_a_top_administrator(): void
    {
        config(['org.name' => 'ABC CONSTRUCTION', 'org.code' => 'ABC']);
        Org::forget();

        $this->artisan('org:provision', ['--admin' => 'boss@abc.com'])->assertSuccessful();

        $this->assertDatabaseHas('companies', ['code' => 'ABC', 'name' => 'ABC CONSTRUCTION']);
        $this->assertDatabaseHas('users', ['email' => 'boss@abc.com', 'access_role' => 'super_admin']);
    }

    public function test_provisioning_twice_changes_nothing_the_second_time(): void
    {
        // 두 번째 실행이 위험하면 아무도 다시 안 돌린다. 그러면 확인 수단이 없어진다.
        config(['org.name' => 'ABC CONSTRUCTION', 'org.code' => 'ABC']);
        Org::forget();

        $this->artisan('org:provision', ['--admin' => 'boss@abc.com'])->assertSuccessful();
        $this->artisan('org:provision', ['--admin' => 'boss@abc.com'])->assertSuccessful();

        $this->assertSame(1, Company::query()->where('code', 'ABC')->count());
        $this->assertSame(1, User::query()->where('email', 'boss@abc.com')->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config(['org.name' => 'ABC CONSTRUCTION', 'org.code' => 'ABC']);
        Org::forget();

        $this->artisan('org:provision', ['--admin' => 'boss@abc.com', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('companies', ['code' => 'ABC']);
        $this->assertDatabaseMissing('users', ['email' => 'boss@abc.com']);
    }

    public function test_provisioning_warns_when_another_customers_settings_came_along(): void
    {
        // 다른 고객의 데이터베이스를 복제해 새 배포를 세우면, 표에 남은 옛 이름이
        // 환경변수를 이긴다. 화면은 멀쩡하고 고객만 남의 이름을 본다.
        $this->rename('옛 고객');

        $this->artisan('org:provision')
            ->expectsOutputToContain('화면에서 고친 설정이 표에 남아 있습니다')
            ->assertSuccessful();
    }
}
