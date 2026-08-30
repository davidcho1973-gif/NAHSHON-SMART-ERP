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

    // ── 대장(물량·제출물)은 그 현장의 것이다 ──────────────────────────

    public function test_애리조나_현장에서_조지아_물량이_보이지_않는다(): void
    {
        // 실제로 겪은 것: 현장 전환기는 애리조나인데 화면에는 조지아 주방 물량이 떴다.
        // 대장 조회가 "선택한 현장" 을 아예 받지 않아, 프로젝트가 안 정해지면 전체에서
        // 첫 번째(코드순)로 넘어갔기 때문이다.
        $azProject = \App\Models\Project::create([
            'project_code' => 'ZZ-AZ', 'name' => '애리조나 공사',
            'construction_type' => 'equipment_setting', 'site_id' => $this->az->id,
        ]);
        $gaProject = \App\Models\Project::create([
            'project_code' => 'AA-GA', 'name' => '조지아 공사',   // 코드순으로 먼저 온다
            'construction_type' => 'equipment_setting', 'site_id' => $this->ga->id,
        ]);

        foreach ([[$azProject, $this->az, '애리조나 배관'], [$gaProject, $this->ga, '조지아 주방 오수관']] as [$p, $site, $name]) {
            \App\Models\BoqItem::create([
                'site_id' => $site->id, 'project_id' => $p->id, 'seq' => 1,
                'discipline_code' => '05', 'discipline' => '배관', 'name_kr' => $name,
                'unit' => 'LF', 'qty' => 100, 'unit_price' => 10,
            ]);
        }

        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin);

        $names = collect(app(\App\Services\Admin\ProjectRegisterService::class)->listBoq(null, 'AZ-01')['rows'])
            ->pluck('nameKr');

        $this->assertTrue($names->contains('애리조나 배관'));
        $this->assertFalse($names->contains('조지아 주방 오수관'), '남의 현장 물량이 그대로 보인다');
    }

    public function test_그_현장에_프로젝트가_없으면_비어_있는_것이_맞다(): void
    {
        // 조지아에만 프로젝트가 있고 애리조나에는 없다. 예전에는 이때 조지아 대장이 떴다.
        $ga = \App\Models\Project::create([
            'project_code' => 'GA-P', 'name' => '조지아', 'construction_type' => 'equipment_setting',
            'site_id' => $this->ga->id,
        ]);
        \App\Models\BoqItem::create([
            'site_id' => $this->ga->id, 'project_id' => $ga->id, 'seq' => 1,
            'discipline_code' => '05', 'discipline' => '배관', 'name_kr' => '조지아 오수관',
            'unit' => 'LF', 'qty' => 10, 'unit_price' => 1,
        ]);

        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $res = $this->actingAs($admin)->postJson('/smart-company-api/api_getBoq', ['args' => [], 'siteId' => 'AZ-01']);

        $res->assertOk();
        $this->assertSame([], $res->json('rows'));
        $this->assertSame([], $res->json('projects'), '고를 수 있는 프로젝트도 그 현장 것뿐이어야 한다');
    }

    public function test_제출물_대장도_같은_규칙을_따른다(): void
    {
        // 두 대장이 같은 구조라 구멍도 같았다. 한쪽만 고치면 다음에 또 갈라진다.
        $ga = \App\Models\Project::create([
            'project_code' => 'GA-S', 'name' => '조지아', 'construction_type' => 'equipment_setting',
            'site_id' => $this->ga->id,
        ]);
        \App\Models\Submittal::create([
            'site_id' => $this->ga->id, 'project_id' => $ga->id, 'seq' => 1,
            'csi' => '22 13 16', 'section' => '위생기구', 'category' => '자재승인', 'title' => '조지아 자재승인원',
            'status' => array_key_first(\App\Models\Submittal::STATUS_OPTIONS),
        ]);

        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $rows = $this->actingAs($admin)
            ->postJson('/smart-company-api/api_getSubmittals', ['args' => [], 'siteId' => 'AZ-01'])
            ->assertOk()->json('rows');

        $this->assertSame([], $rows);
    }

    public function test_전체_현장에서는_모두_보인다(): void
    {
        $ga = \App\Models\Project::create([
            'project_code' => 'GA-A', 'name' => '조지아', 'construction_type' => 'equipment_setting',
            'site_id' => $this->ga->id,
        ]);
        \App\Models\BoqItem::create([
            'site_id' => $this->ga->id, 'project_id' => $ga->id, 'seq' => 1,
            'discipline_code' => '05', 'discipline' => '배관', 'name_kr' => '조지아 오수관',
            'unit' => 'LF', 'qty' => 10, 'unit_price' => 1,
        ]);

        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin);

        $rows = app(\App\Services\Admin\ProjectRegisterService::class)->listBoq(null, 'ALL')['rows'];
        $this->assertCount(1, $rows, '전체를 보면 그대로 다 보여야 한다');
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
