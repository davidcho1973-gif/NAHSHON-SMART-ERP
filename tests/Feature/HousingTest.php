<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Housing;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousingTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private Site $siteA1;
    private Site $siteA2;
    private Site $siteB1;
    private Housing $housingA1;
    private Housing $housingA2;
    private Housing $housingB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['code' => 'CO-A', 'name' => 'Company A', 'status' => 'active']);
        $this->companyB = Company::create(['code' => 'CO-B', 'name' => 'Company B', 'status' => 'active']);

        $this->siteA1 = Site::create(['company_id' => $this->companyA->id, 'code' => 'A1', 'name' => 'Site A1', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->siteA2 = Site::create(['company_id' => $this->companyA->id, 'code' => 'A2', 'name' => 'Site A2', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->siteB1 = Site::create(['company_id' => $this->companyB->id, 'code' => 'B1', 'name' => 'Site B1', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $this->housingA1 = Housing::create(['company_id' => $this->companyA->id, 'site_id' => $this->siteA1->id, 'code' => 'H-A1', 'name' => 'House A1', 'beds' => 4, 'occupied' => 3, 'status' => 'available']);
        $this->housingA2 = Housing::create(['company_id' => $this->companyA->id, 'site_id' => $this->siteA2->id, 'code' => 'H-A2', 'name' => 'House A2', 'beds' => 3, 'occupied' => 3, 'status' => 'full']);
        $this->housingB1 = Housing::create(['company_id' => $this->companyB->id, 'site_id' => $this->siteB1->id, 'code' => 'H-B1', 'name' => 'House B1', 'beds' => 2, 'occupied' => 1, 'status' => 'maintenance']);
    }

    private function makeUser(array $attributes): User
    {
        static $counter = 0;
        $counter++;

        return User::create(array_merge([
            'name' => 'User ' . $counter,
            'email' => 'user' . $counter . '@example.com',
            'password' => 'password',
            'account_status' => 'active',
        ], $attributes));
    }

    /**
     * @param  list<string>  $expectedCodes
     */
    private function assertVisible(User $user, array $expectedCodes): void
    {
        $codes = Housing::visibleTo($user)->pluck('code')->sort()->values()->all();
        sort($expectedCodes);

        $this->assertSame($expectedCodes, $codes);
    }

    public function test_super_admin_sees_all_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'super_admin', 'access_scope' => 'self']);

        // Privileged role overrides a narrow scope.
        $this->assertVisible($user, ['H-A1', 'H-A2', 'H-B1']);
    }

    public function test_admin_sees_all_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'admin', 'access_scope' => 'company', 'allowed_company_id' => $this->companyB->id]);

        $this->assertVisible($user, ['H-A1', 'H-A2', 'H-B1']);
    }

    public function test_all_sites_scope_sees_all_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'viewer', 'access_scope' => 'all_sites']);

        $this->assertVisible($user, ['H-A1', 'H-A2', 'H-B1']);
    }

    public function test_company_scope_sees_only_company_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'site_manager', 'access_scope' => 'company', 'allowed_company_id' => $this->companyA->id]);

        $this->assertVisible($user, ['H-A1', 'H-A2']);
    }

    public function test_site_scope_sees_only_site_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $this->siteA1->id]);

        $this->assertVisible($user, ['H-A1']);
    }

    public function test_team_scope_resolves_to_its_site(): void
    {
        $user = $this->makeUser(['access_role' => 'foreman', 'access_scope' => 'team', 'allowed_site_id' => $this->siteA2->id]);

        $this->assertVisible($user, ['H-A2']);
    }

    public function test_self_scope_sees_no_housings(): void
    {
        $user = $this->makeUser(['access_role' => 'worker', 'access_scope' => 'self']);

        $this->assertVisible($user, []);
    }

    public function test_company_scope_without_allowed_company_sees_nothing(): void
    {
        $user = $this->makeUser(['access_role' => 'viewer', 'access_scope' => 'company', 'allowed_company_id' => null]);

        $this->assertVisible($user, []);
    }

    public function test_guest_sees_no_housings(): void
    {
        $codes = Housing::visibleTo(null)->pluck('code')->all();

        $this->assertSame([], $codes);
    }
}
