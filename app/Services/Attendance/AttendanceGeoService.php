<?php

namespace App\Services\Attendance;

use App\Models\AttendanceGeoEvent;
use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteWifiAccessPoint;
use Illuminate\Support\Carbon;
use App\Support\Org;

/**
 * 하이브리드 자동 출퇴근 판정.
 *
 * 현장 내(on-site) = GPS 부지 반경 안 OR 현장 네트워크(WiFi BSSID · 공인 IP 대역). 진입/이탈을
 * 상태머신으로 처리하되, 정문에서의 GPS 떨림을 막기 위해 이탈 반경 히스테리시스(+버퍼)와
 * 체류시간(dwell)을 둔다. "근무중 구간의 합"을 누적(이탈시간 차감)하고, 첫 진입은 clock_in 으로 기록한다.
 *
 * GPS 는 실내에서 못 믿는다. 위성이 안 잡히면 폰이 주변 신호로 위치를 추정하는데 오차가
 * 수백 m~수 km 씩 나고, 그 좌표가 우연히 반경 안에 떨어지면 밖에 있는 사람이 "현장 안"으로
 * 찍힌다. 그래서 오차(accuracy)가 반경보다 큰 신호는 안팎을 구분할 수 없는 것으로 보고
 * **모른다(unknown)** 로 처리한다 — 밖에 있다고 단정하지 않는다. 단정하면 실내에 있는 사람이
 * 이탈로 잡힌다.
 *
 * 이 "모른다" 를 메우는 것이 현장 네트워크다. 현장 WiFi 에 붙어 있다는 사실은 물리적으로
 * 그 근처에 있다는 뜻이라, GPS 가 죽는 실내에서 오히려 GPS 보다 정확하다.
 */
class AttendanceGeoService
{
    /** 이탈 판정 반경 버퍼(m) — 들어올 땐 반경, 나갈 땐 반경+버퍼(경계 떨림 방지). */
    public const EXIT_BUFFER_METERS = 80;

    /** 이탈 확정 체류시간(초) — 밖에 이만큼 있어야 진짜 이탈. */
    public const DWELL_SECONDS = 600; // 10분

    /**
     * 오차가 이 값보다 크면 그 위치 신호는 쓰지 않는다.
     *
     * 기준은 현장 반경이다 — 반경 500m 짜리 현장에서 오차 600m 인 좌표는 안팎을 가릴 수
     * 없다. 다만 반경이 아주 작은 현장(50m 등)에서는 보통의 휴대폰 오차(30~60m)까지 전부
     * 버리게 되므로 바닥값을 둔다.
     */
    public const MIN_ACCURACY_METERS = 75;

    // 현장 크기와 건물 구조에 따라 다르다. 좁은 현장에 넓은 버퍼를 쓰면 길 건너에서
    // 찍히고, 반대면 현장 안에서 못 찍는다. 배포마다 config/org.php 로 바꾼다.
    private static function exitBufferMeters(): int
    {
        return Org::int('geo.exit_buffer_meters', self::EXIT_BUFFER_METERS);
    }

    private static function dwellSeconds(): int
    {
        return Org::int('geo.dwell_seconds', self::DWELL_SECONDS);
    }

    private static function minAccuracyMeters(): int
    {
        return Org::int('geo.min_accuracy_meters', self::MIN_ACCURACY_METERS);
    }

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
        // 폰이 보내는 값이 아니라 요청에서 서버가 읽은 값이다. 브라우저가 막을 수 없다.
        $ip = isset($signal['ip']) && $signal['ip'] ? trim((string) $signal['ip']) : null;

        // 판정은 probe() 한 곳에서만 한다 — 직접 출퇴근(2단)도 같은 함수를 쓴다.
        // 규칙이 두 벌이면 언젠가 어긋나고, 그때 "자동은 되는데 직접은 안 된다"가 된다.
        $probe = $this->probe($site, ['bssid' => $bssid, 'ip' => $ip, 'lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy]);
        $netIn = $probe['network'];
        $gps = $probe['gps'];                                   // in | in_loose | out | unknown

        $onSiteEnter = $netIn || $gps === 'in';                 // 진입: 반경 안 or 현장 네트워크
        $onSiteStay = $netIn || $gps === 'in' || $gps === 'in_loose';  // 유지: +버퍼(히스테리시스)

        // 아무 단서도 없는 신호. 밖에 있다는 뜻이 아니라 판단할 근거가 없다는 뜻이다.
        // 이때는 상태를 건드리지 않는다 — 건드리면 실내 근무자가 이탈로 잡힌다.
        $noInfo = ! $netIn && $gps === 'unknown';

        $source = $probe['source'];

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
            if ($noInfo) {
                // 판단 근거 없음 — 이번 신호로는 아무것도 바꾸지 않는다.
                $kind = 'ping';
            } elseif ($onSiteStay) {
                if ($session->pending_exit_at) {
                    $session->update(['pending_exit_at' => null]);
                }
            } else {
                if (! $session->pending_exit_at) {
                    $session->update(['pending_exit_at' => $now]);
                } elseif ($session->pending_exit_at->diffInSeconds($now) >= self::dwellSeconds()) {
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
            'occurred_at' => $now,
            'payload' => [
                'ssid' => $signal['ssid'] ?? null,
                'ip' => $ip,
                // 왜 이렇게 판정했는지 나중에 되짚을 수 있어야 한다.
                'gps' => $gps,
                'network' => $netIn,
            ],
        ]);

        return [
            'success' => true,
            'status' => $session?->status ?? 'off_site',
            'onSite' => $onSiteStay,
            'source' => $source,
            'kind' => $kind,
            'onSiteSeconds' => (int) ($session?->on_site_seconds ?? 0),
            // 화면이 "왜 이렇게 됐는지" 를 사람 말로 보여 줄 수 있도록 근거를 같이 준다.
            'gps' => $gps,
            'network' => $netIn,
        ];
    }

    /** 현장 안이 확인됨 — 현장 WiFi/망이 맞거나 GPS 가 반경 안이다. */
    public const ON_SITE = 'on_site';

    /** 현장 밖이 확인됨 — 위치를 읽었고 반경(+완충)을 벗어났다. */
    public const OFF_SITE = 'off_site';

    /**
     * 확인할 수 없음 — 현장에 좌표·WiFi 가 등록되지 않았거나, 위치를 못 읽었거나,
     * 정확도가 너무 낮거나, 완충구간이다. <b>"밖"이 아니라 "모른다"</b>이다.
     */
    public const UNVERIFIED = 'unverified';

    /**
     * 이 신호가 말하는 것 — 현장 안인가, 밖인가, 알 수 없는가.
     *
     * 근태 기록을 만드는 모든 입구(작업자 앱·게이트 QR)가 이 한 줄을 지나게 해서
     * "어디에 있었는가" 의 판정이 한 벌만 존재하게 한다. 규칙이 두 벌이면 언젠가
     * 어긋나고, 그때 같은 사람의 같은 위치가 입구에 따라 다르게 판정된다.
     *
     * 처분(승인할지 보류할지)은 여기서 정하지 않는다 — 그건 입구마다 사정이 달라서
     * 각 서비스가 정한다. 여기가 답하는 것은 사실 하나뿐이다: 어디에 있었는가.
     *
     * @param  array<string, mixed>  $signal  lat,lng,accuracy,bssid,ip
     */
    public function verdict(Site $site, array $signal): string
    {
        $probe = $this->probe($site, $signal);

        if ($probe['network'] || $probe['gps'] === 'in') {
            return self::ON_SITE;
        }

        // 완충구간(in_loose)은 밖이라고 단정하지 않는다 — GPS 는 건물 안에서 수십 미터
        // 씩 튄다. 확실히 벗어난 것만 밖으로 본다.
        return $probe['gps'] === 'out' ? self::OFF_SITE : self::UNVERIFIED;
    }

    /**
     * 신호 하나를 읽어 "지금 이 현장에 있다고 볼 수 있는가"를 판정한다. 상태는 바꾸지 않는다.
     *
     * 자동 기록과 직접 기록이 같은 규칙을 쓰도록 여기 한 곳에 모아 둔다.
     *
     * @param  array<string, mixed>  $signal  lat,lng,accuracy,bssid,ip
     * @return array{gps: string, network: bool, source: string}
     */
    public function probe(Site $site, array $signal): array
    {
        $lat = isset($signal['lat']) && is_numeric($signal['lat']) ? (float) $signal['lat'] : null;
        $lng = isset($signal['lng']) && is_numeric($signal['lng']) ? (float) $signal['lng'] : null;
        $accuracy = isset($signal['accuracy']) && is_numeric($signal['accuracy']) ? (int) $signal['accuracy'] : null;
        $bssid = isset($signal['bssid']) && $signal['bssid'] ? SiteWifiAccessPoint::normalizeBssid((string) $signal['bssid']) : null;
        $ip = isset($signal['ip']) && $signal['ip'] ? trim((string) $signal['ip']) : null;

        $network = $this->networkMatch($site, $bssid, $ip);
        $gps = $this->gpsState($site, $lat, $lng, $accuracy);

        return [
            'gps' => $gps,
            'network' => $network,
            'source' => $network ? ($gps === 'in' ? 'both' : 'wifi') : ($gps === 'unknown' ? 'manual' : 'gps'),
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
     * 하루 마감 — 그날 마지막 이탈을 퇴근으로 확정.
     *
     * 하루가 다 지난 뒤에 마감하면 기록이 하루 늦게 나타난다. 그래서 저녁에 한 번 돌려
     * 그날 안에 끝낸다. 다만 저녁에 돌 때는 아직 일하고 있는 사람이 있다 — 그 사람을
     * 그 시각으로 끊으면 연장 근무가 잘린다.
     *
     * $activeGraceMinutes 를 주면 "방금까지 현장에 있던 것이 확인되는" 세션은 건너뛴다.
     * 건너뛴 것은 자정 안전망이 처리한다.
     *
     * @return array{success: bool, finalized: int, needsReview: int, skipped: int}
     */
    public function finalize(Carbon $date, int $activeGraceMinutes = 0): array
    {
        $ds = $date->toDateString();
        $sessions = AttendanceSession::query()->where('work_date', $ds)->whereIn('status', ['on_site', 'left'])->get();

        $finalized = 0;
        $review = 0;
        $skipped = 0;
        $now = Carbon::now();

        foreach ($sessions as $s) {
            // 아직 근무중인 사람은 건드리지 않는다 — 저녁 마감이 연장 근무를 자르면 안 된다.
            if ($activeGraceMinutes > 0
                && $s->status === 'on_site'
                && $s->last_onsite_at
                && $s->last_onsite_at->diffInMinutes($now) < $activeGraceMinutes) {
                $skipped++;

                continue;
            }

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
            } elseif ($s->employee()->first()?->isHourly()) {
                // 진입 후 재실 추적이 전혀 없음(입장 핑 1회 등) → 퇴근 시각을 알 수 없어 미마감(관리자 확인).
                // 시급 직영만 검토 대상이다 — 퇴근 시각이 임금에 직결되기 때문.
                $s->update(['needs_review' => true, 'status' => 'finalized', 'finalized_at' => now()]);
                $review++;
            } else {
                // 협력사(인원체크만)·월급제(정액)는 출근 확인으로 충분 — 검토 없이 마감한다.
                $s->update(['status' => 'finalized', 'finalized_at' => now()]);
                $finalized++;
            }
        }

        return ['success' => true, 'finalized' => $finalized, 'needsReview' => $review, 'skipped' => $skipped];
    }

    // ───────────────────────── 내부 ─────────────────────────

    /**
     * 위치 신호 하나를 네 가지 중 하나로 읽는다.
     *
     *   in       — 반경 안. 출근을 걸 수 있다.
     *   in_loose — 반경과 이탈 버퍼 사이. 이미 근무중이면 유지하되, 새로 출근을 걸지는 않는다.
     *   out      — 확실히 밖.
     *   unknown  — 좌표가 없거나, 현장에 지오펜스가 없거나, 오차가 커서 안팎을 가릴 수 없다.
     */
    private function gpsState(Site $site, ?float $lat, ?float $lng, ?int $accuracy): string
    {
        if ($lat === null || $lng === null || $site->latitude === null || $site->longitude === null || ! $site->radius_meters) {
            return 'unknown';
        }

        $radius = (float) $site->radius_meters;

        if ($accuracy !== null && $accuracy > max($radius, self::minAccuracyMeters())) {
            return 'unknown';
        }

        $distance = $this->distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude);

        if ($distance <= $radius) {
            return 'in';
        }

        return $distance <= $radius + self::exitBufferMeters() ? 'in_loose' : 'out';
    }

    /**
     * 현장 네트워크에 붙어 있는가.
     *
     * BSSID 는 네이티브 앱만 알려 줄 수 있고, 공인 IP 는 서버가 요청에서 직접 본다.
     * 둘 중 하나만 맞아도 현장으로 인정한다 — 어느 쪽이든 그 네트워크에 물리적으로
     * 닿아 있다는 뜻이기 때문이다.
     */
    private function networkMatch(Site $site, ?string $bssid, ?string $ip): bool
    {
        if ($bssid === null && $ip === null) {
            return false;
        }

        $rows = SiteWifiAccessPoint::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->get(['kind', 'bssid']);

        foreach ($rows as $row) {
            if ($row->isNetwork()) {
                if ($ip !== null && SiteWifiAccessPoint::ipInCidr($ip, $row->bssid)) {
                    return true;
                }

                continue;
            }

            if ($bssid !== null && $row->bssid === $bssid) {
                return true;
            }
        }

        return false;
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
