<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\SiteContractor;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_can_track_contract_companies_and_field_teams(): void
    {
        $company = Company::query()->create([
            'code' => 'A-COMPANY',
            'name' => 'A Company',
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'AZ-PHX',
            'name' => 'Arizona Phoenix Plant',
            'address' => 'Phoenix, AZ',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);

        $contractor = $site->contractors()->create([
            'company_id' => $company->id,
            'company_name' => 'A Company',
            'contract_role' => 'subcontractor',
            'contract_number' => 'PO-AZ-1001',
            'scope_of_work' => 'Mechanical installation',
            'primary_contact_name' => 'Site Contact',
            'primary_contact_phone' => '555-1000',
            'status' => 'active',
        ]);

        $team = $site->teams()->create([
            'company_id' => $company->id,
            'code' => 'PIPE-A',
            'name' => '배관팀 A',
            'contract_company_name' => 'A Company',
            'trade_type' => 'piping',
            'foreman_name' => 'Kim Foreman',
            'responsible_manager_name' => 'Lee Manager',
            'supervisor_name' => 'Park Supervisor',
            'supervisor_phone' => '555-2000',
            'planned_headcount' => 12,
            'status' => 'active',
        ]);

        $this->assertInstanceOf(SiteContractor::class, $contractor);
        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame('A Company', $site->contractors()->first()?->company_name);
        $this->assertSame('배관팀 A', $site->teams()->first()?->name);
        $this->assertSame('piping', $site->teams()->first()?->trade_type);
        $this->assertSame('Lee Manager', $site->teams()->first()?->responsible_manager_name);
    }
}
