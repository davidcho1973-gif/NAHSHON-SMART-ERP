<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 입력·조회 화면의 기본 소속 — 현장 사람은 자기 현장, 전체를 보는 사람
 * (수퍼관리자·고위관리자·회계)은 Global 이 기본이다. 사람이 방금 고른 값이
 * 언제나 이긴다.
 */
class DefaultScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_scoped_manager_defaults_to_their_site_and_single_project(): void
    {
        [$company, $site, $project] = $this->world();
        $manager = $this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $site->id]);

        $this->assertSame($site->id, DefaultScope::siteId($manager));
        $this->assertSame($company->id, DefaultScope::companyId($manager));
        $this->assertSame($project->id, DefaultScope::projectId($manager));
    }

    public function test_employee_link_supplies_the_site_when_scope_does_not(): void
    {
        [$company, $site] = $this->world();
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'name' => '홍길동', 'employment_status' => 'active',
        ]);
        $user = $this->user('hr_manager', ['employee_id' => $employee->id]);

        $this->assertSame($site->id, DefaultScope::siteId($user));
    }

    public function test_global_roles_default_to_global_even_with_an_employee_record(): void
    {
        [$company, $site] = $this->world();
        $employee = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'name' => '대표', 'employment_status' => 'active',
        ]);

        foreach (['super_admin', 'admin', 'payroll'] as $role) {
            $user = $this->user($role, ['employee_id' => $employee->id, 'allowed_site_id' => $site->id]);
            $this->assertNull(DefaultScope::siteId($user), $role.' 은 Global 이 기본이어야 한다');
            $this->assertNull(DefaultScope::projectId($user), $role);
        }
    }

    public function test_project_is_not_guessed_when_the_site_has_several(): void
    {
        [$company, $site] = $this->world();
        Project::create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-002', 'name' => 'Second',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);
        $manager = $this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $site->id]);

        $this->assertNull(DefaultScope::projectId($manager));
    }

    public function test_expense_wizard_preselects_by_the_same_rule(): void
    {
        [, $site] = $this->world();
        $manager = $this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $site->id]);

        $this->actingAs($manager)->get(route('mobile-expense.wizard'))
            ->assertOk()
            ->assertSee('value="'.$site->id.'" selected', false);

        // 수퍼관리자는 Global(지정 안함) 이 기본 — 어떤 현장에도 selected 가 없다.
        $admin = $this->user('super_admin', ['allowed_site_id' => $site->id]);
        $this->actingAs($admin)->get(route('mobile-expense.wizard'))
            ->assertOk()
            ->assertDontSee('value="'.$site->id.'" selected', false);
    }

    public function test_document_hub_dropzone_preselects_by_the_same_rule(): void
    {
        [$company, $site, $project] = $this->world();
        $manager = $this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $site->id]);

        $body = $this->actingAs($manager)
            ->get(route('document-intelligence.index', ['embed' => 1]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$site->id.'" selected', $body);
        $this->assertStringContainsString('value="'.$project->id.'" selected', $body);

        $admin = $this->user('super_admin');
        $adminBody = $this->actingAs($admin)
            ->get(route('document-intelligence.index', ['embed' => 1]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$site->id.'" selected', $adminBody);

        // ERP 상단에서 고른 현장(파라미터)은 수퍼관리자여도 그대로 따른다 — 사람이 고른 값이 이긴다.
        $picked = $this->actingAs($admin)
            ->get(route('document-intelligence.index', ['embed' => 1, 'site_id' => $site->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$site->id.'" selected', $picked);
    }

    /** @return array{0: Company, 1: Site, 2: Project} */
    private function world(): array
    {
        $company = Company::query()->create(['code' => 'XYZ', 'name' => 'XYZ MEP', 'status' => 'active']);
        $site = Site::query()->create([
            'company_id' => $company->id, 'code' => 'LGES-AZ', 'name' => 'LGES Arizona',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $project = Project::query()->create([
            'company_id' => $company->id, 'site_id' => $site->id,
            'project_code' => 'LGES-AZ-2026-001', 'name' => 'Arizona Module',
            'construction_type' => 'equipment_setting', 'project_stage' => 'awarded',
        ]);

        return [$company, $site, $project];
    }

    /** @param array<string, mixed> $extra */
    private function user(string $role, array $extra = []): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'access_role' => $role,
            'access_scope' => 'all_sites',
            'account_status' => 'active',
            ...$extra,
        ]);
    }
}
