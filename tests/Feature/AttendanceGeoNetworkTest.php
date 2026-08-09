<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use App\Services\Attendance\AttendanceGeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 자동 출퇴근의 판정 규칙.
 *
 * 두 가지를 지킨다.
 *   ① 못 믿을 위치 신호로 사람을 현장 안이라고 하지 않는다 — 그리고 밖이라고도 하지 않는다.
 *      오차가 반경보다 큰 좌표는 "모른다"이지 "밖"이 아니다. 밖으로 단정하면 실내 근무자가
 *      이탈로 잡혀 근무시간이 끊긴다.
 *   ② 그 "모른다"를 현장 네트워크가 메운다. 현장 WiFi 에 붙어 있으면 GPS 가 죽어도 현장이다.
 */
class AttendanceGeoNetworkTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Employee $employee;

    /** 현장 정중앙과 한참 밖. 애리조나 피닉스 기준. */
    private const SITE_LAT = 33.453316;

    private const SITE_LNG = -112.177502;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));

        $company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $company->id,
            'code' => 'LG_ESS_PH',
            'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 500,
        ]);
        $this->employee = Employee::create([
            'company_id' => $company->id,
            'site_id' => $this->site->id,
            'name' => '김성훈',
            'employment_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): AttendanceGeoService
    {
        return app(AttendanceGeoService::class);
    }

    /** @param array<string, mixed> $signal */
    private function ping(array $signal): array
    {
        return $this->svc()->record($this->employee, $signal);
    }

    private function geoSession(): ?AttendanceSession
    {
        return AttendanceSession::where('employee_id', $this->employee->id)->first();
    }

    // ── 정확도 ─────────────────────────────────────────────────────

    public function test_good_fix_inside_the_fence_clocks_in(): void
    {
        $res = $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 12]);

        $this->assertTrue($res['success']);
        $this->assertSame('on_site', $res['status']);
        $this->assertSame('enter', $res['kind']);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee->id, 'event_type' => 'clock_in', 'source' => 'geo_auto',
        ]);
    }

    public function test_sloppy_fix_inside_the_fence_is_not_trusted(): void
    {
        // 좌표는 현장 정중앙인데 오차가 900m 다. 반경 500m 안팎을 이 신호로는 가릴 수 없다.
        $res = $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 900]);

        $this->assertSame('unknown', $res['gps']);
        $this->assertSame('off_site', $res['status']);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_tiny_site_still_works_with_ordinary_phone_accuracy(): void
    {
        // 반경 40m 현장에서 오차 60m 는 흔하다. 반경만 기준으로 삼으면 아무도 못 찍는다.
        $this->site->update(['radius_meters' => 40]);

        $res = $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 60]);

        $this->assertSame('in', $res['gps']);
        $this->assertSame('on_site', $res['status']);
    }

    public function test_unusable_fix_does_not_push_a_working_person_out(): void
    {
        // 밖에서 일하다 실내로 들어간 상황. 실내 신호는 오차가 커서 못 믿는다.
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);
        $this->assertSame('on_site', $this->geoSession()->status);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:20:00'));
        $res = $this->ping(['lat' => 33.6, 'lng' => -112.4, 'accuracy' => 2500]);

        // 이탈 대기조차 걸리지 않아야 한다 — 근거가 없기 때문이다.
        $this->assertSame('on_site', $res['status']);
        $this->assertNull($this->geoSession()->pending_exit_at);
    }

    public function test_a_clear_fix_far_outside_still_starts_the_exit_countdown(): void
    {
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:20:00'));
        $this->ping(['lat' => 33.6, 'lng' => -112.4, 'accuracy' => 15]);

        $this->assertNotNull($this->geoSession()->pending_exit_at);
    }

    // ── 현장 네트워크 ───────────────────────────────────────────────

    public function test_site_wifi_clocks_in_when_gps_is_useless(): void
    {
        // 철골 건물 안. GPS 는 못 쓰지만 현장 WiFi 에는 붙어 있다.
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_BSSID,
            'bssid' => 'a4:5e:60:11:22:33',
            'label' => '현장사무실',
        ]);

        $res = $this->ping(['bssid' => 'A4-5E-60-11-22-33', 'accuracy' => 3000, 'lat' => 33.9, 'lng' => -112.9]);

        $this->assertTrue($res['network']);
        $this->assertSame('on_site', $res['status']);
        $this->assertSame('wifi', $res['source']);
    }

    public function test_public_ip_range_works_without_any_gps(): void
    {
        // 브라우저는 BSSID 를 못 읽는다. 대신 현장 WiFi 를 타고 나온 공인 IP 를 서버가 본다.
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.0/24',
            'label' => '현장 인터넷 회선',
        ]);

        $res = $this->ping(['ip' => '203.0.113.77']);

        $this->assertTrue($res['network']);
        $this->assertSame('on_site', $res['status']);
        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_in', 'source' => 'geo_auto']);
    }

    public function test_an_ip_outside_the_registered_range_does_nothing(): void
    {
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.0/24',
        ]);

        $res = $this->ping(['ip' => '198.51.100.9']);

        $this->assertFalse($res['network']);
        $this->assertSame('off_site', $res['status']);
    }

    public function test_inactive_network_entries_are_ignored(): void
    {
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.0/24',
            'active' => false,
        ]);

        $this->assertFalse($this->ping(['ip' => '203.0.113.77'])['network']);
    }

    public function test_site_wifi_keeps_a_worker_on_site_when_gps_says_outside(): void
    {
        // 현장이 넓어 반경 밖 좌표가 잡히는 구석이라도, 현장 WiFi 안이면 근무중이다.
        SiteWifiAccessPoint::create([
            'site_id' => $this->site->id,
            'kind' => SiteWifiAccessPoint::KIND_NETWORK,
            'bssid' => '203.0.113.5',
        ]);
        $this->ping(['ip' => '203.0.113.5']);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:30:00'));
        $res = $this->ping(['ip' => '203.0.113.5', 'lat' => 33.6, 'lng' => -112.4, 'accuracy' => 10]);

        $this->assertSame('on_site', $res['status']);
        $this->assertNull($this->geoSession()->pending_exit_at);
    }

    // ── 저녁 마감 ───────────────────────────────────────────────────

    public function test_evening_close_finishes_a_worker_who_already_left(): void
    {
        // 07:00 출근 → 16:20 이탈. 20:00 마감이 그날 안에 퇴근을 남겨야 한다.
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00'));
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 16:20:00'));
        $this->ping(['lat' => 33.6, 'lng' => -112.4, 'accuracy' => 12]);
        Carbon::setTestNow(Carbon::parse('2026-08-10 16:35:00'));
        $this->ping(['lat' => 33.6, 'lng' => -112.4, 'accuracy' => 12]);   // 10분 체류 → 이탈 확정

        Carbon::setTestNow(Carbon::parse('2026-08-10 20:00:00'));
        $r = $this->svc()->finalize(Carbon::parse('2026-08-10'), 30);

        $this->assertSame(1, $r['finalized']);
        $this->assertSame(0, $r['skipped']);
        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_out', 'source' => 'geo_auto']);
    }

    public function test_evening_close_leaves_someone_still_working_alone(): void
    {
        // 저녁에도 현장에 있는 사람을 그 시각으로 끊으면 연장 근무가 잘린다.
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00'));
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 19:55:00'));
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 20:00:00'));
        $r = $this->svc()->finalize(Carbon::parse('2026-08-10'), 30);

        $this->assertSame(0, $r['finalized']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame('on_site', $this->geoSession()->status);
        $this->assertSame(0, AttendanceLog::where('event_type', 'clock_out')->count());
    }

    public function test_midnight_safety_net_closes_what_the_evening_skipped(): void
    {
        // 저녁에 건너뛴 사람은 자정 안전망이 마지막 재실 시각으로 마감한다.
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00'));
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);
        Carbon::setTestNow(Carbon::parse('2026-08-10 21:40:00'));
        $this->ping(['lat' => self::SITE_LAT, 'lng' => self::SITE_LNG, 'accuracy' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-08-11 00:05:00'));
        $r = $this->svc()->finalize(Carbon::parse('2026-08-10'));

        $this->assertSame(1, $r['finalized']);
        $this->assertSame('21:40', $this->geoSession()->last_exit_at->format('H:i'));
        $this->assertDatabaseHas('attendance_logs', ['event_type' => 'clock_out', 'source' => 'geo_auto']);
    }

    // ── CIDR 계산 ──────────────────────────────────────────────────

    public function test_ip_range_matching_handles_the_usual_shapes(): void
    {
        $in = fn (string $ip, string $cidr) => SiteWifiAccessPoint::ipInCidr($ip, $cidr);

        $this->assertTrue($in('203.0.113.77', '203.0.113.0/24'));
        $this->assertFalse($in('203.0.114.77', '203.0.113.0/24'));
        // 접두길이가 8의 배수가 아닌 경우 — 바이트 중간을 비트로 잘라야 맞는다.
        $this->assertTrue($in('203.0.113.130', '203.0.113.128/25'));
        $this->assertFalse($in('203.0.113.126', '203.0.113.128/25'));
        // 접두길이가 없으면 정확히 같은 주소만.
        $this->assertTrue($in('203.0.113.5', '203.0.113.5'));
        $this->assertFalse($in('203.0.113.6', '203.0.113.5'));
        // IPv6 도 같은 방식으로 다룬다.
        $this->assertTrue($in('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse($in('2001:db9::1', '2001:db8::/32'));
        // 종류가 다른 주소끼리는 비교하지 않는다.
        $this->assertFalse($in('203.0.113.5', '2001:db8::/32'));
        $this->assertFalse($in('말이 안 되는 값', '203.0.113.0/24'));
    }

    public function test_cidr_validation_rejects_nonsense(): void
    {
        $this->assertTrue(SiteWifiAccessPoint::isValidCidr('203.0.113.0/24'));
        $this->assertTrue(SiteWifiAccessPoint::isValidCidr('203.0.113.5'));
        $this->assertTrue(SiteWifiAccessPoint::isValidCidr('2001:db8::/32'));
        $this->assertFalse(SiteWifiAccessPoint::isValidCidr('203.0.113.0/33'));
        $this->assertFalse(SiteWifiAccessPoint::isValidCidr('203.0.113.999'));
        $this->assertFalse(SiteWifiAccessPoint::isValidCidr('a4:5e:60:11:22:33'));
    }
}
