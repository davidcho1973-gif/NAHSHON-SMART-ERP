<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장이 둘 이상일 때 — 새 현장에 남의 현장 것이 따라 들어오지 않는가.
 *
 * 현장이 하나뿐일 때는 "빈 것보다 낫다"로 넘어가던 편의들이, 현장이 둘이 되는 순간
 * 남의 데이터가 새 현장 화면에 앉는 통로가 된다.
 */
class SiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $az;

    private Site $ga;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->az = Site::create(['company_id' => $company->id, 'code' => 'AZ-01', 'name' => '애리조나', 'status' => 'active']);
        $this->ga = Site::create(['company_id' => $company->id, 'code' => 'GA-01', 'name' => '조지아', 'status' => 'active']);
    }

    public function test_새_현장의_등록_폼에_남의_현장_공정이_뜨지_않는다(): void
    {
        // 애리조나에는 공정표가 있고, 조지아는 오늘 연 현장이라 아직 없다.
        WbsItem::create([
            'project_code' => 'AZ-P1', 'level' => 'subtask', 'wbs_code' => 'AZ-1',
            'name' => '배선', 'trade' => 'AZ-ONLY-ELEC', 'site_id' => $this->az->id,
        ]);

        $this->get('/join/w/'.$this->ga->id)
            ->assertOk()
            ->assertDontSee('AZ-ONLY-ELEC', false, '새 현장이 남의 현장 공종을 빌려오면 안 된다');

        // 대신 기본 직군을 보여 준다 — 빈 목록으로 두지는 않는다.
        $this->get('/join/w/'.$this->ga->id)->assertSee('<datalist id="trade-list">', false);
    }

    public function test_자기_현장_공정은_그대로_보인다(): void
    {
        WbsItem::create([
            'project_code' => 'GA-P1', 'level' => 'subtask', 'wbs_code' => 'GA-1',
            'name' => '배관', 'trade' => 'GA-PIPING', 'site_id' => $this->ga->id,
        ]);

        $this->get('/join/w/'.$this->ga->id)->assertOk()->assertSee('value="GA-PIPING"', false);
    }

    public function test_프로젝트를_안_고르면_남의_공정표로_넘어가지_않는다(): void
    {
        // 예전에는 특정 고객사의 프로젝트 코드가 기본값이라, 코드가 빠지면 새 현장
        // 화면에 남의 공정표가 뜰 수 있었다. 이제는 이유를 말한다.
        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);

        foreach (['api_getProjectWbsTree', 'api_getProjectProgressSummary'] as $method) {
            $res = $this->actingAs($admin)->postJson('/smart-company-api/'.$method, ['args' => [], 'siteId' => 'ALL']);

            $res->assertOk()->assertJsonPath('success', false);
            $this->assertStringContainsString('프로젝트', (string) $res->json('error'), "{$method} 가 조용히 넘어갑니다");
        }
    }

    public function test_고객사_프로젝트_코드가_기본값으로_남아_있지_않다(): void
    {
        // 한 줄이라도 남으면 그 줄은 다음 고객에게 남의 프로젝트를 보여 준다.
        $body = (string) file_get_contents(app_path('Support/SmartCompanyData.php'));

        $this->assertStringNotContainsString("?? 'HFF-02'", $body);
    }

    // ── 본사 공통 규약(①안) ────────────────────────────────────────────

    public function test_현장을_고르면_본사_공통이_섞였다고_말한다(): void
    {
        // 규약은 유지한다(현장 + 본사 공통). 다만 침묵하지 않는다 — 표시가 없으면
        // 현장별 숫자를 더해서 공통분을 현장 수만큼 겹쳐 세게 된다.
        $this->assertTrue(SmartCompanyData::financeStats('AZ-01')['includesHqCommon']);
    }

    public function test_전체를_보면_섞였다는_말이_필요_없다(): void
    {
        $this->assertFalse(SmartCompanyData::financeStats('ALL')['includesHqCommon']);
    }
}
