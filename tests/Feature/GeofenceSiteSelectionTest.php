<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 지오펜스를 "현장을 골라서" 등록하는 흐름.
 *
 * 관리자는 배정 현장이 아닌 다른 현장의 지오펜스도 설정할 수 있어야 하고(전 현장 관리),
 * 현장관리자(site_manager)는 자기 배정 현장만 건드릴 수 있어야 한다.
 */
class GeofenceSiteSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $siteA;
    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'ABC ENG', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->siteA = Site::create(['company_id' => $this->company->id, 'code' => 'SITE-A', 'name' => '가 현장', 'status' => 'active']);
        $this->siteB = Site::create(['company_id' => $this->company->id, 'code' => 'SITE-B', 'name' => '나 현장', 'status' => 'active']);
    }

    private function userWith(string $role, ?Site $site): User
    {
        $emp = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $site?->id,
            'first_name' => ucfirst($role), 'last_name' => 'Tester',
            'email' => $role . '@example.com', 'employment_status' => 'active',
        ]);

        return User::factory()->create([
            'email' => $role . '@example.com', 'employee_id' => $emp->id,
            'access_role' => $role, 'account_status' => 'active',
        ]);
    }

    private function setGeofence(User $user, float $lat, float $lng, int $radius, int|string|null $siteId)
    {
        return $this->actingAs($user)->postJson('/smart-company-api/api_setMySiteGeofence', [
            'args' => [$lat, $lng, $radius, $siteId],
            'siteId' => 'ALL',
        ]);
    }

    public function test_admin_can_set_geofence_for_a_chosen_other_site(): void
    {
        // 관리자는 A 현장에 배정돼 있지만, B 현장을 골라 등록할 수 있어야 한다.
        $admin = $this->userWith('admin', $this->siteA);

        $res = $this->setGeofence($admin, 33.4255, -111.9400, 322, $this->siteB->id);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('site', 'SITE-B');
        $this->siteB->refresh();
        $this->assertEqualsWithDelta(33.4255, (float) $this->siteB->latitude, 0.0001);
        $this->assertEqualsWithDelta(-111.9400, (float) $this->siteB->longitude, 0.0001);
        $this->assertSame(322, (int) $this->siteB->radius_meters);
        // A 현장은 건드리지 않는다.
        $this->siteA->refresh();
        $this->assertNull($this->siteA->latitude);
    }

    public function test_radius_is_clamped_to_safe_bounds(): void
    {
        $admin = $this->userWith('admin', $this->siteA);

        $this->setGeofence($admin, 33.4, -111.9, 999999, $this->siteB->id)->assertJsonPath('radius', 5000);
        $this->setGeofence($admin, 33.4, -111.9, 5, $this->siteB->id)->assertJsonPath('radius', 30);
    }

    public function test_site_manager_cannot_set_geofence_of_another_site(): void
    {
        // 현장관리자는 A 배정 → B 현장 등록 시도는 거부돼야 한다.
        $manager = $this->userWith('site_manager', $this->siteA);

        $res = $this->setGeofence($manager, 33.4255, -111.9400, 322, $this->siteB->id);

        $res->assertStatus(200)->assertJsonPath('success', false);
        $this->siteB->refresh();
        $this->assertNull($this->siteB->latitude);
    }

    public function test_site_manager_can_set_geofence_of_own_site(): void
    {
        $manager = $this->userWith('site_manager', $this->siteA);

        $this->setGeofence($manager, 33.4255, -111.9400, 322, $this->siteA->id)
            ->assertJsonPath('success', true)->assertJsonPath('site', 'SITE-A');
    }

    public function test_worker_cannot_set_geofence(): void
    {
        $worker = $this->userWith('worker', $this->siteA);

        $this->setGeofence($worker, 33.4255, -111.9400, 322, $this->siteA->id)
            ->assertJsonPath('success', false);
    }

    public function test_admin_lists_all_active_sites_with_geo_status(): void
    {
        $this->siteA->update(['latitude' => 33.4, 'longitude' => -111.9, 'radius_meters' => 200]);
        $admin = $this->userWith('admin', $this->siteA);

        $res = $this->actingAs($admin)->postJson('/smart-company-api/api_getGeofenceSites', ['args' => [], 'siteId' => 'ALL']);

        $res->assertStatus(200)->assertJsonPath('canManage', true);
        $sites = collect($res->json('sites'));
        $this->assertCount(2, $sites);
        $this->assertTrue($sites->firstWhere('code', 'SITE-A')['hasGeo']);
        $this->assertFalse($sites->firstWhere('code', 'SITE-B')['hasGeo']);
    }

    public function test_site_manager_only_sees_own_site_in_list(): void
    {
        $manager = $this->userWith('site_manager', $this->siteA);

        $res = $this->actingAs($manager)->postJson('/smart-company-api/api_getGeofenceSites', ['args' => [], 'siteId' => 'ALL']);

        $sites = collect($res->json('sites'));
        $this->assertCount(1, $sites);
        $this->assertSame('SITE-A', $sites->first()['code']);
    }

    public function test_worker_cannot_manage_sites(): void
    {
        $worker = $this->userWith('worker', $this->siteA);

        $res = $this->actingAs($worker)->postJson('/smart-company-api/api_getGeofenceSites', ['args' => [], 'siteId' => 'ALL']);

        $res->assertStatus(200)->assertJsonPath('canManage', false);
    }

    public function test_admin_can_manually_finalize_attendance_as_scheduler_fallback(): void
    {
        // 자정 스케줄러가 안 돌았을 때의 수동 마감(관리자 fallback).
        $admin = $this->userWith('admin', $this->siteA);

        $res = $this->actingAs($admin)->postJson('/smart-company-api/api_finalizeAttendanceNow', [
            'args' => ['2026-07-21'], 'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true)->assertJsonPath('date', '2026-07-21');
    }

    public function test_worker_cannot_finalize_attendance(): void
    {
        $worker = $this->userWith('worker', $this->siteA);

        $res = $this->actingAs($worker)->postJson('/smart-company-api/api_finalizeAttendanceNow', [
            'args' => ['2026-07-21'], 'siteId' => 'ALL',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', false);
    }
}
