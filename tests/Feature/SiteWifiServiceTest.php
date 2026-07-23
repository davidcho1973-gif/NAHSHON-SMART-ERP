<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use App\Services\Attendance\SiteWifiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 WiFi(BSSID) 등록 — 정규화·검증·목록·삭제(하이브리드 출퇴근 실내 확인 기반).
 */
class SiteWifiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_normalizes_bssid_and_lists_by_site(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'AZ', 'timezone' => 'America/Phoenix', 'status' => 'active', 'latitude' => 33.4, 'longitude' => -112.0, 'radius_meters' => 322]);
        $svc = app(SiteWifiService::class);

        $r = $svc->save((string) $site->id, ['bssid' => 'A4-5E-60-11-22-33', 'ssid' => 'NAHSHON', 'label' => '정문']);
        $this->assertTrue($r['success']);
        $this->assertSame('a4:5e:60:11:22:33', $r['bssid']);  // 정규화

        $list = $svc->list((string) $site->id);
        $this->assertTrue($list['success']);
        $this->assertSame(322, $list['geofence']['radius']);
        $this->assertCount(1, $list['aps']);
        $this->assertSame('정문', $list['aps'][0]['label']);
    }

    public function test_invalid_bssid_rejected(): void
    {
        $site = Site::create(['code' => 'AZ-02', 'name' => 'AZ2', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $r = app(SiteWifiService::class)->save((string) $site->id, ['bssid' => 'not-a-mac']);
        $this->assertFalse($r['success']);
        $this->assertSame(0, SiteWifiAccessPoint::count());
    }

    public function test_duplicate_bssid_upserts_not_duplicates(): void
    {
        $site = Site::create(['code' => 'AZ-03', 'name' => 'AZ3', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $svc = app(SiteWifiService::class);
        $svc->save((string) $site->id, ['bssid' => 'a4:5e:60:11:22:33', 'label' => 'A']);
        $svc->save((string) $site->id, ['bssid' => 'A4:5E:60:11:22:33', 'label' => 'B']); // 대소문자만 다름 → 같은 AP

        $this->assertSame(1, SiteWifiAccessPoint::where('site_id', $site->id)->count());
        $this->assertSame('B', SiteWifiAccessPoint::first()->label);
    }

    public function test_list_requires_specific_site(): void
    {
        $r = app(SiteWifiService::class)->list('ALL');
        $this->assertFalse($r['success']);
    }
}
