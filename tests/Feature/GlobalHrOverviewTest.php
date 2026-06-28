<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\GlobalHrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlobalHrOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;
    private Site $usSite;
    private Site $krSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['code' => 'LGES', 'name' => 'LG Energy', 'status' => 'active']);

        $this->usSite = Site::create(['company_id' => $this->client->id, 'code' => 'LGES-AZ', 'name' => 'LG AZ', 'country' => 'US', 'status' => 'active', 'timezone' => 'America/Phoenix']);
        $this->krSite = Site::create(['company_id' => $this->client->id, 'code' => 'SDI-CJ', 'name' => 'SDI Cheongju', 'country' => 'KR', 'status' => 'active', 'timezone' => 'Asia/Seoul']);

        $elec = Team::create(['company_id' => $this->client->id, 'site_id' => $this->usSite->id, 'code' => 'ELEC', 'name' => '전기A', 'status' => 'active']);
        $mech = Team::create(['company_id' => $this->client->id, 'site_id' => $this->krSite->id, 'code' => 'MECH', 'name' => '기계', 'status' => 'active']);

        // US: 2 employees on the electrical team
        $u1 = $this->emp('US-1', $this->usSite, $elec);
        $this->emp('US-2', $this->usSite, $elec);
        // KR: 1 employee on the mechanical team
        $k1 = $this->emp('KR-1', $this->krSite, $mech);

        // Attendance today: US-1 clocked in (present), KR-1 clocked in then out (not present).
        $this->log($u1, $this->usSite, 'clock_in', Carbon::today()->setTime(7, 0));
        $this->log($k1, $this->krSite, 'clock_in', Carbon::today()->setTime(8, 0));
        $this->log($k1, $this->krSite, 'clock_out', Carbon::today()->setTime(17, 0));
    }

    private function emp(string $code, Site $site, Team $team): Employee
    {
        return Employee::create([
            'company_id' => $this->client->id, 'site_id' => $site->id, 'team_id' => $team->id,
            'name' => $code, 'employee_number' => $code, 'employment_status' => 'active',
        ]);
    }

    private function log(Employee $e, Site $s, string $type, Carbon $at): void
    {
        AttendanceLog::create([
            'employee_id' => $e->id, 'company_id' => $this->client->id, 'site_id' => $s->id, 'team_id' => $e->team_id,
            'attendance_date' => $at->toDateString(), 'event_type' => $type, 'event_at' => $at,
            'source' => 'mobile_gps', 'status' => 'approved',
        ]);
    }

    public function test_overview_aggregates_country_site_company_team(): void
    {
        $d = app(GlobalHrService::class)->overview('ALL');

        $this->assertSame(3, $d['totals']['employees']);
        $this->assertSame(2, $d['totals']['sites']);
        $this->assertSame(2, $d['totals']['countries']);

        // US-1 present; US-2 no record; KR-1 clocked out → only 1 present overall.
        $this->assertSame(1, $d['totals']['present']);

        $countries = collect($d['countries'])->keyBy('country');
        $this->assertSame(2, $countries['US']['employees']);
        $this->assertSame(1, $countries['US']['present']);
        $this->assertSame(1, $countries['KR']['employees']);
        $this->assertSame(0, $countries['KR']['present']); // clocked out

        // drilldown: US site → company → team
        $usSite = collect($countries['US']['sites'])->firstWhere('code', 'LGES-AZ');
        $this->assertSame('LG Energy', $usSite['companies'][0]['company']);
        $this->assertSame('전기A', $usSite['companies'][0]['teams'][0]['team']);
        $this->assertSame(2, $usSite['companies'][0]['teams'][0]['total']);
    }

    public function test_overview_filters_by_country(): void
    {
        $d = app(GlobalHrService::class)->overview('KR');

        $this->assertSame(1, $d['totals']['sites']);
        $this->assertSame(1, $d['totals']['employees']);
        $this->assertCount(1, $d['countries']);
        $this->assertSame('KR', $d['countries'][0]['country']);
    }

    public function test_site_client_is_used_as_wonchungsa(): void
    {
        $clientCo = Company::create(['code' => 'LGES-CLIENT', 'name' => 'LG Energy Solution', 'status' => 'active']);
        $this->usSite->update(['client_company_id' => $clientCo->id]);

        $d = app(GlobalHrService::class)->overview('US');
        $usSite = collect($d['countries'][0]['sites'])->firstWhere('code', 'LGES-AZ');
        $this->assertSame('LG Energy Solution', $usSite['client']);          // 원청사 = 현장 client
        $this->assertSame('LG Energy', $usSite['primaryCompany']);            // 소속사 = employer

        $row = collect($d['matrix'])->firstWhere('site', 'LGES-AZ');
        $this->assertSame('LG Energy Solution', $row['client']);
        $this->assertSame('LG Energy', $row['company']);
    }

    public function test_team_board_and_assign_moves_employee(): void
    {
        $mech = Team::where('code', 'MECH')->first();
        $loose = $this->emp('KR-LOOSE', $this->krSite, $mech);
        $loose->update(['team_id' => null]); // 미배치로

        $board = app(GlobalHrService::class)->teamBoard('SDI-CJ');
        $this->assertTrue($board['success']);
        $this->assertCount(1, $board['unassigned']);
        $this->assertSame(1, collect($board['teams'])->firstWhere('name', '기계')['members'] ? count(collect($board['teams'])->firstWhere('name', '기계')['members']) : 0);

        $res = app(GlobalHrService::class)->assignTeam($loose->id, $mech->id);
        $this->assertTrue($res['success']);

        $after = app(GlobalHrService::class)->teamBoard('SDI-CJ');
        $this->assertCount(0, $after['unassigned']);
        $this->assertSame(2, count(collect($after['teams'])->firstWhere('name', '기계')['members']));
    }

    public function test_assign_rejects_team_from_other_site(): void
    {
        $usTeam = Team::where('code', 'ELEC')->first(); // US site team
        $krEmp = Employee::where('name', 'KR-1')->first(); // KR site employee
        $res = app(GlobalHrService::class)->assignTeam($krEmp->id, $usTeam->id);
        $this->assertFalse($res['success']);
    }

    public function test_api_global_overview_endpoint(): void
    {
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        $res = $this->actingAs($user)->postJson('/smart-company-api/api_getGlobalHrOverview', [
            'args' => ['ALL'], 'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('totals.employees', 3);
    }
}
