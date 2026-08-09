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
 * 하이브리드 자동 출퇴근 — 진입/이탈(히스테리시스+체류) 상태머신, 근무중 구간 합, 자정 마감.
 */
class AttendanceGeoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'C1', 'name' => 'Co', 'status' => 'active']);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'AZ', 'timezone' => 'UTC', 'status' => 'active',
            'latitude' => 33.4000000, 'longitude' => -112.0000000, 'radius_meters' => 300,
        ]);
        $this->emp = Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'first_name' => 'A', 'last_name' => 'K', 'email' => 'a@x.com', 'employment_status' => 'active',
        ]);
    }

    private function inside(): array
    {
        return ['lat' => 33.4000000, 'lng' => -112.0000000, 'accuracy' => 10];
    }

    private function outside(): array
    {
        return ['lat' => 33.5000000, 'lng' => -112.0000000, 'accuracy' => 10]; // ~11km 밖
    }

    public function test_enter_ping_exit_computes_worked_time_and_finalizes(): void
    {
        $svc = app(AttendanceGeoService::class);
        $t0 = Carbon::parse('2026-07-21 08:00:00');

        Carbon::setTestNow($t0);
        $r = $svc->record($this->emp, $this->inside());
        $this->assertSame('enter', $r['kind']);
        $this->assertSame('on_site', $r['status']);
        $this->assertSame(1, AttendanceLog::where('event_type', 'clock_in')->count()); // 첫 진입 = clock_in

        // 계속 현장 안(핑)
        Carbon::setTestNow($t0->copy()->addMinutes(30));
        $this->assertSame('ping', $svc->record($this->emp, $this->inside())['kind']);

        // 09:00 정문 밖으로 → 이탈 후보(아직 확정 안 됨: 체류 10분 필요)
        Carbon::setTestNow($t0->copy()->addMinutes(60));
        $this->assertSame('ping', $svc->record($this->emp, $this->outside())['kind']);
        $this->assertSame('on_site', AttendanceSession::first()->status);

        // 09:11 여전히 밖 → 이탈 확정
        Carbon::setTestNow($t0->copy()->addMinutes(71));
        $r = $svc->record($this->emp, $this->outside());
        $this->assertSame('exit', $r['kind']);
        $session = AttendanceSession::first();
        $this->assertSame('left', $session->status);
        $this->assertSame(3600, $session->on_site_seconds); // 08:00~09:00 = 1시간 근무

        // 자정 마감 → 마지막 이탈(09:00)을 퇴근으로
        $fin = $svc->finalize($t0->copy()->startOfDay());
        $this->assertSame(1, $fin['finalized']);
        $this->assertSame('finalized', AttendanceSession::first()->status);
        $this->assertSame(1, AttendanceLog::where('event_type', 'clock_out')->count());

        Carbon::setTestNow();
    }

    public function test_wifi_keeps_on_site_without_gps_indoors(): void
    {
        SiteWifiAccessPoint::create(['site_id' => $this->site->id, 'bssid' => 'a4:5e:60:11:22:33', 'active' => true]);
        $svc = app(AttendanceGeoService::class);
        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00'));

        // GPS 는 밖(실내라 안 잡힘)인데 현장 WiFi 에 붙어 있음 → 현장 내로 유지.
        $r = $svc->record($this->emp, ['lat' => 33.5, 'lng' => -112.0, 'accuracy' => 50, 'bssid' => 'A4:5E:60:11:22:33']);
        $this->assertTrue($r['onSite']);
        $this->assertSame('enter', $r['kind']);
        $this->assertSame('wifi', $r['source']);
        Carbon::setTestNow();
    }

    public function test_finalize_flags_review_when_only_entry_ping_and_no_tracking(): void
    {
        // 입장 핑 1회 뿐 → 언제 나갔는지 알 수 없음 → 미마감(관리자 확인).
        $svc = app(AttendanceGeoService::class);
        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00'));
        $svc->record($this->emp, $this->inside()); // 진입 후 재실 추적 없음

        $fin = $svc->finalize(Carbon::parse('2026-07-21')->startOfDay());
        $this->assertSame(1, $fin['needsReview']);
        $session = AttendanceSession::first();
        $this->assertTrue($session->needs_review);
        $this->assertSame('finalized', $session->status);
        $this->assertSame(0, AttendanceLog::where('event_type', 'clock_out')->count()); // 자동 퇴근 안 함
        Carbon::setTestNow();
    }

    public function test_finalize_auto_clocks_out_at_last_seen_when_app_closed_on_leaving(): void
    {
        // 근본 원인 해결: 낮 동안 현장 재실이 추적됐고, 이탈 신호를 못 받은 채(앱 종료) 퇴근한 경우
        // → 마지막 재실 시각을 퇴근으로 자동 확정한다(미마감 아님).
        $svc = app(AttendanceGeoService::class);
        // 현장 타임존(UTC) 기준으로 하루가 넘어가지 않도록 UTC 로 고정한다.
        $t0 = Carbon::parse('2026-07-21 08:00:00', 'UTC');

        Carbon::setTestNow($t0);
        $svc->record($this->emp, $this->inside()); // 08:00 진입 = clock_in

        // 종일 현장 안에서 간헐 핑 — 마지막 재실 17:00
        Carbon::setTestNow($t0->copy()->addHours(9));
        $svc->record($this->emp, $this->inside());

        // 이후 앱을 닫고 귀가 → 이탈 핑이 서버에 안 옴. 세션은 on_site 로 남는다.
        $this->assertSame('on_site', AttendanceSession::first()->status);

        // 자정 마감 → 마지막 재실(17:00)을 퇴근으로 자동 확정.
        $fin = $svc->finalize(Carbon::parse('2026-07-21', 'UTC')->startOfDay());
        $this->assertSame(1, $fin['finalized']);
        $this->assertSame(0, $fin['needsReview']);

        $session = AttendanceSession::first();
        $this->assertSame('finalized', $session->status);
        $this->assertFalse($session->needs_review);
        $this->assertTrue($session->last_exit_at->equalTo($t0->copy()->addHours(9)), '퇴근 시각은 마지막 재실(17:00 UTC)이어야 한다.');
        $this->assertSame(9 * 3600, $session->on_site_seconds); // 08:00~17:00 = 9시간

        // 자동 퇴근 로그가 남는다(설계상 이탈 누락 시에도 퇴근이 확정됨).
        $this->assertSame(1, AttendanceLog::where('event_type', 'clock_out')->count());
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->emp->id, 'event_type' => 'clock_out', 'source' => 'geo_auto',
        ]);
        Carbon::setTestNow();
    }
}
