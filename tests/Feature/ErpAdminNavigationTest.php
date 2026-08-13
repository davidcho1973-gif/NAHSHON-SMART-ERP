<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리 화면은 전부 ERP(SPA) 안에 있고 별도 관리자 패널(/admin)은 없다.
 *
 * 예전에는 두 개의 화면이 같은 데이터를 각자 다르게 보여 줬다 — 어느 쪽에서 고쳐야
 * 하는지 아무도 몰랐고, 같은 것을 두 번 등록하는 일이 생겼다. 그래서 하나로 합쳤다.
 * 여기서는 그 결정이 되돌아가지 않았는지를 지킨다.
 */
class ErpAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);
    }

    public function test_erp_home_no_longer_links_to_a_separate_admin_panel(): void
    {
        $this->actingAs($this->admin())
            ->get('/')
            ->assertOk()
            ->assertDontSee('data-admin-entry', escape: false)
            ->assertDontSee(url('/admin'));
    }

    public function test_admin_screens_live_in_the_erp_sidebar(): void
    {
        $this->actingAs($this->admin())
            ->get('/')
            ->assertOk()
            // 마지막으로 옮겨 온 네 화면 — 현장·프로젝트, 임금 프로필, 메신저.
            ->assertSee('data-view="site-admin"', escape: false)
            ->assertSee('data-view="pay-profiles"', escape: false)
            ->assertSee('data-view="messenger-admin"', escape: false)
            // 먼저 옮겨 온 화면들도 그대로 있어야 한다.
            ->assertSee('data-view="access-control"', escape: false)
            ->assertSee('data-view="employee-admin"', escape: false);
    }

    public function test_old_admin_bookmarks_land_on_the_erp_home(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertRedirect('/');
    }

    public function test_old_admin_subpaths_land_on_the_erp_home(): void
    {
        // /admin/sites 처럼 안쪽까지 저장해 둔 북마크도 404 대신 홈으로 보낸다.
        $this->actingAs($this->admin())
            ->get('/admin/sites')
            ->assertRedirect('/');
    }

    public function test_company_switcher_appears_only_for_multi_company_users(): void
    {
        // 회사 전환은 예전 관리자 패널에만 있었다. 패널이 없어졌으니 ERP 안에 있어야
        // 여러 법인을 오가는 사람이 갇히지 않는다. 회사가 하나면 감춘다.
        $a = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $b = Company::create(['code' => 'C2', 'name' => 'XYZ MEP', 'status' => 'active']);

        $admin = $this->admin();   // super_admin 은 모든 회사를 본다
        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee(route('company.switch'))
            ->assertSee('ABC ENG')
            ->assertSee('XYZ MEP');

        // 한 회사에만 속한 사람에게는 고를 것이 없다.
        $single = User::factory()->create([
            'access_role' => 'site_manager', 'access_scope' => 'site', 'account_status' => 'active',
        ]);
        $single->companies()->attach($a->id);
        $this->actingAs($single)
            ->get('/')
            ->assertOk()
            ->assertDontSee(route('company.switch'));

        $this->assertNotNull($b->id);
    }

    public function test_erp_home_redirects_expired_api_sessions_to_login(): void
    {
        $this->actingAs($this->admin())
            ->get('/')
            ->assertOk()
            ->assertSee("credentials: 'same-origin'", escape: false)
            ->assertSee('status !== 401 && status !== 419', escape: false)
            ->assertSee("window.location.replace('/login?expired=1')", escape: false);
    }
}
