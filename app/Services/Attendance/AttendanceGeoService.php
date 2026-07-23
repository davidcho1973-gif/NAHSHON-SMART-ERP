<?php

namespace App\Services\Attendance;

use App\Models\AttendanceGeoEvent;
use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use Illuminate\Support\Carbon;

/**
 * 하이브리드 자동 출퇴근 판정.
 *
 * 현장 내(on-site) = GPS 부지 반경 안 OR 현장 WiFi(BSSID) 연결. 진입/이탈을 상태머신으로 처리하되,
 * 정문에서의 GPS 떨림을 막기 위해 이탈 반경 히스테리시스(+버퍼)와 체류시간(dwell)을 둔다.
 * "근무중 구간의 합"을 누적(이탈시간 차감)하고, 첫 진입은 clock_in 으로 기록한다.
 */
class AttendanceGeoService
{
    /** 이탈 판정 반경 버퍼(m) — 들어올 땐 반경, 나갈 땐 반경+버퍼(경계 떨림 방지). */
    public const EXIT_BUFFER_METERS = 80;

    /** 이탈 확정 체류시간(초) — 밖에 이만큼 있어야 진짜 이탈. */
    public const DWELL_SECONDS = 600; // 10분

    /**
     * 위치/네트워크 신호 1건을 받아 상태를 갱신한다.
     *
     * @param  array<string, mixed>  $signal  lat,lng,accuracy,bssid,ssid,isMocked,clientTs
     * @return array<string, mixed>
     */
    public function record(Employee $employee, array $signal): array
    {
        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        if (! $site) {
            return ['success' => false, 'error' => '배정된 현장이 없습니다.'];
        }

        // 안티 스푸핑 — 가상 위치·시간 조작.
        if (($signal['isMocked'] ?? false) === true || ($signal['isMocked'] ?? null) === 'true') {
            return ['success' => false, 'error' => '가상 위치(Fake GPS)가 감지되어 기록이 차단되었습니다.'];
        }
        if (! empty($signal['clientTs']) && abs(time() - (int) $signal['clientTs']) > 300) {
            return ['success' => false, 'error' => '기기 시간 조작이 감지되었습니다. 자동 시간설정을 켜주세요.'];
        }

        $now = Carbon::now();
        $tz = $site->timezone ?: config('app.timezone');
        $workDate = $now->copy()->timezone($tz)->toDateString();

        $lat = isset($signal['lat']) && is_numeric($signal['lat']) ? (float) $signal['lat'] : null;
        $lng = isset($signal['lng']) && is_numeric($signal['lng']) ? (float) $signal['lng'] : null;
        $accuracy = isset($signal['accuracy']) && is_numeric($signal['accuracy']) ? (int) $signal['accuracy'] : null;
        $bssid = isset($signal['bssid']) && $signal['bssid'] ? SiteWifiAccessPoint::normalizeBssid((string) $signal['bssid']) : null;

        $gpsIn = $this->gpsWithin($site, $lat, $lng, false);
        $gpsInLoose = $this->gpsWithin($site, $lat, $lng, true);
        $wifiIn = $this->wifiMatch($site, $bssid);
        $onSiteEnter = $gpsIn || $wifiIn;             // 진입: 반경 안 or WiFi
        $onSiteStay = $gpsInLoose || $wifiIn;         // 유지: 반경+버퍼 or WiFi(히스테리시스)
        $source = $wifiIn ? ($gpsIn ? 'both' : 'wifi') : (($gpsIn || $gpsInLoose) ? 'gps' : 'manual');

        $session = AttendanceSession::query()->where('employee_id', $employee->id)->where('work_date', $workDate)->first();
        $kind = 'ping';

        if (! $session || $session->status === 'finalized') {
            if ($onSiteEnter && ! $session) {
                $session = AttendanceSession::create([
                    'employee_id' => $employee->id, 'site_id' => $site->id, 'work_date' => $workDate,
                    'status' => 'on_site', 'first_enter_at' => $now, 'last_enter_at' => $now,
                ]);
                $this->openAttendanceLog($employee, $site, $now);
                $kind = 'enter';
            }
        } elseif ($session->status === 'on_site') {
            if ($onSiteStay) {
                if ($session->pending_exit_at) {
                    $session->update(['pending_exit_at' => null]);
                }
            } else {
                if (! $session->pending_exit_at) {
                    $session->update(['pending_exit_at' => $now]);
                } elseif ($session->pending_exit_at->diffInSeconds($now) >= self::DWELL_SECONDS) {
                    $exitAt = $session->pending_exit_at;
                    $session->on_site_seconds += max(0, $session->last_enter_at->diffInSeconds($exitAt));
                    $session->last_exit_at = $exitAt;
                    $session->status = 'left';
                    $session->pending_exit_at = null;
                    $session->save();
                    $kind = 'exit';
                }
            }
        } elseif ($session->status === 'left') {
            if ($onSiteEnter) {
                $session->update(['last_enter_at' => $now, 'status' => 'on_site', 'pending_exit_at' => null]);
                $kind = 'enter';
            }
        }

        // 현장 안이 확인될 때마다 "마지막 재실 시각"을 남긴다 — 이탈 신호를 놓쳐도(앱 종료 등)
        // 자정 마감에서 이 시각을 퇴근으로 추정할 수 있게 하는 안전장치.
        if ($session && $onSiteStay) {
            $session->last_onsite_at = $now;
            $session->save();
        }

        AttendanceGeoEvent::create([
            'employee_id' => $employee->id, 'site_id' => $site->id, 'kind' => $kind, 'source' => $source,
            'on_site' => $onSiteStay, 'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'bssid' => $bssid,
            'occurred_at' => $now, 'payload' => ['ssid' => $signal['ssid'] ?? null],
        ]);

        return [
            'success' => true,
            'status' => $session?->status ?? 'off_site',
            'onSite' => $onSiteStay,
            'source' => $source,
            'kind' => $kind,
            'onSiteSeconds' => (int) ($session?->on_site_seconds ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Employee $employee): array
    {
        $site = $employee->site_id ? Site::find($employee->site_id) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate = Carbon::now()->timezone($tz)->toDateString();
        $s = AttendanceSession::query()->where('employee_id', $employee->id)->where('work_date', $workDate)->first();

        return [
            'success' => true,
            'date' => $workDate,
            'state' => $s?->status ?? 'off_site',        // on_site / left / finalized / off_site
            'onSiteSeconds' => (int) ($s?->on_site_seconds ?? 0),
            'firstEnterAt' => $s?->first_enter_at?->toDateTimeString(),
            'lastExitAt' => $s?->last_exit_at?->toDateTimeString(),
            'needsReview' => (bool) ($s?->needs_review),
            'site' => $site ? ['code' => $site->code, 'name' => $site->name, 'radius' => $site->radius_meters] : null,
        ];
    }

    /**
     * 자정 마감 — 그날 마지막 이탈을 퇴근으로 확정. 아직 현장 안이면 미마감(관리자 확인).
     *
     * @return array{success: bool, finalized: int, needsReview: int}
     */
    public function finalize(Carbon $date): array
    {
        $ds = $date->toDateString();
        $sessions = AttendanceSession::query()->where('work_date', $ds)->whereIn('status', ['on_site', 'left'])->get();

        $finalized = 0;
        $review = 0;
        foreach ($sessions as $s) {
            if ($s->status === 'left') {
                $this->closeAttendanceLog($s);
                $s->update(['status' => 'finalized', 'finalized_at' => now()]);
                $finalized++;
            } elseif ($s->pending_exit_at) {
                // 자정 직전에 나가는 중이었으면 그 시각을 퇴근으로 확정.
                $s->on_site_seconds += max(0, $s->last_enter_at->diffInSeconds($s->pending_exit_at));
                $s->last_exit_at = $s->pending_exit_at;
                $s->pending_exit_at = null;
                $s->save();
                $this->closeAttendanceLog($s);
                $s->update(['status' => 'finalized', 'finalized_at' => now()]);
                $finalized++;
            } elseif ($s->last_onsite_at && $s->last_enter_at && $s->last_onsite_at->gt($s->last_enter_at)) {
                // 이탈 신호는 못 받았지만(앱을 닫고 퇴근한 경우), 낮 동안 현장 재실이 추적됐다.
                // → 마지막 재실 시각을 퇴근으로 자동 확정한다. (근본 원인 해결: 자동 퇴근 누락 방지)
                $s->on_site_seconds += max(0, $s->last_enter_at->diffInSeconds($s->last_onsite_at));
                $s->last_exit_at = $s->last_onsite_at;
                $s->pending_exit_at = null;
                $s->status = 'finalized';
                $s->finalized_at = now();
                $s->save();
                $this->closeAttendanceLog($s);
                $finalized++;
            } else {
                // 진입 후 재실 추적이 전혀 없음(입장 핑 1회 등) → 퇴근 시각을 알 수 없어 미마감(관리자 확인).
                $s->update(['needs_review' => true, 'status' => 'finalized', 'finalized_at' => now()]);
                $review++;
            }
        }

        return ['success' => true, 'finalized' => $finalized, 'needsReview' => $review];
    }

    // ───────────────────────── 내부 ─────────────────────────

    private function gpsWithin(Site $site, ?float $lat, ?float $lng, bool $loose): bool
    {
        if ($lat === null || $lng === null || $site->latitude === null || $site->longitude === null || ! $site->radius_meters) {
            return false;
        }
        $threshold = (float) $site->radius_meters + ($loose ? self::EXIT_BUFFER_METERS : 0);

        return $this->distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude) <= $threshold;
    }

    private function wifiMatch(Site $site, ?string $bssid): bool
    {
        if (! $bssid) {
            return false;
        }

        return SiteWifiAccessPoint::query()->where('site_id', $site->id)->where('bssid', $bssid)->where('active', true)->exists();
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function openAttendanceLog(Employee $employee, Site $site, Carbon $at): void
    {
        AttendanceLog::create([
            'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'site_id' => $site->id,
            'attendance_date' => $at->copy()->timezone($site->timezone ?: config('app.timezone'))->toDateString(),
            'event_type' => 'clock_in', 'event_at' => $at, 'source' => 'geo_auto', 'status' => 'approved',
        ]);
    }

    private function closeAttendanceLog(AttendanceSession $s): void
    {
        if (! $s->last_exit_at) {
            return;
        }
        $employee = $s->employee()->first();
        AttendanceLog::create([
            'employee_id' => $s->employee_id, 'company_id' => $employee?->company_id, 'site_id' => $s->site_id,
            'attendance_date' => $s->work_date->toDateString(),
            'event_type' => 'clock_out', 'event_at' => $s->last_exit_at, 'source' => 'geo_auto', 'status' => 'approved',
        ]);
    }
}
