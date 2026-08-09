<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 자동 출퇴근이 "지금 바로" 동작하는지 — 관리자가 현장 지오펜스를 현재 위치로 설정하고,
 * 브라우저 위치(ping)로 출근(clock_in)이 기록되는 전 과정(엔드포인트 레벨).
 */
class AttendanceGeoFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_geofence_and_clock_in_via_ping(): void
    {
        $company = Company::create(['code' => 'C1', 'name' => 'Co', 'status' => 'active']);
        $site = Site::create(['code' => 'AZ-01', 'name' => 'AZ', 'timezone' => 'UTC', 'status' => 'active']); // 지오펜스 미설정
        $emp = Employee::create(['company_id' => $company->id, 'site_id' => $site->id, 'first_name' => 'D', 'last_name' => 'C', 'email' => 'd@x.com', 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $emp->id, 'access_role' => 'admin', 'account_status' => 'active']);

        $this->actingAs($user);

        // 1) 현재 위치를 현장 중심으로 설정(반경 322m).
        $set = $this->postJson('/smart-company-api/api_setMySiteGeofence', ['args' => [33.4, -112.0, 322]]);
        $set->assertOk()->assertJson(['success' => true, 'radius' => 322]);
        $this->assertSame(322, (int) $site->fresh()->radius_meters);

        // 2) 현장 반경 안 좌표로 ping → 출근(enter/on_site) + clock_in 기록.
        $ping = $this->postJson('/attendance-geo/ping', ['lat' => 33.4, 'lng' => -112.0, 'accuracy' => 10]);
        $ping->assertOk()->assertJson(['success' => true, 'status' => 'on_site', 'kind' => 'enter']);
        $this->assertSame(1, AttendanceLog::where('employee_id', $emp->id)->where('event_type', 'clock_in')->count());

        // 3) 상태 조회 → 근무중.
        $this->getJson('/attendance-geo/status')->assertOk()->assertJson(['success' => true, 'state' => 'on_site']);
    }

    public function test_ping_without_geofence_is_off_site(): void
    {
        $company = Company::create(['code' => 'C2', 'name' => 'Co2', 'status' => 'active']);
        $site = Site::create(['code' => 'AZ-02', 'name' => 'AZ2', 'timezone' => 'UTC', 'status' => 'active']);
        $emp = Employee::create(['company_id' => $company->id, 'site_id' => $site->id, 'first_name' => 'E', 'last_name' => 'C', 'email' => 'e@x.com', 'employment_status' => 'active']);
        $user = User::factory()->create(['employee_id' => $emp->id, 'access_role' => 'worker', 'account_status' => 'active']);

        // 지오펜스 미설정 → 현장 판정 불가 → off_site(세션 미생성).
        $this->actingAs($user)->postJson('/attendance-geo/ping', ['lat' => 33.4, 'lng' => -112.0, 'accuracy' => 10])
            ->assertOk()->assertJson(['success' => true, 'onSite' => false]);
    }
}
