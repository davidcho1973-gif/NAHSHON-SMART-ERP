<?php

namespace App\Services\Attendance;

use App\Models\Site;
use App\Models\SiteWifiAccessPoint;

/**
 * 현장 WiFi AP 등록 관리 — 관리자가 현장별 BSSID 화이트리스트를 등록/조회/삭제한다.
 * 이후 하이브리드 출퇴근 판정이 이 목록을 참조한다.
 */
class SiteWifiService
{
    /**
     * @return array<string, mixed>
     */
    public function list(string $siteId): array
    {
        $site = $this->resolveSite($siteId);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 먼저 선택하세요(전체 현장에서는 등록할 수 없습니다).', 'aps' => []];
        }

        $aps = SiteWifiAccessPoint::query()->where('site_id', $site->id)->orderBy('label')->orderBy('bssid')->get()
            ->map(fn (SiteWifiAccessPoint $a) => [
                'id' => $a->id, 'bssid' => $a->bssid, 'ssid' => $a->ssid, 'label' => $a->label, 'active' => $a->active,
            ])->all();

        return [
            'success' => true,
            'site' => ['id' => $site->id, 'code' => $site->code, 'name' => $site->name],
            'geofence' => [
                'lat' => $site->latitude !== null ? (float) $site->latitude : null,
                'lng' => $site->longitude !== null ? (float) $site->longitude : null,
                'radius' => $site->radius_meters !== null ? (int) $site->radius_meters : null,
            ],
            'aps' => $aps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function save(string $siteId, array $data, ?int $userId = null): array
    {
        $site = $this->resolveSite($siteId);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 선택하세요.'];
        }

        $bssid = (string) ($data['bssid'] ?? '');
        if (! SiteWifiAccessPoint::isValidBssid($bssid)) {
            return ['success' => false, 'error' => 'BSSID 형식이 올바르지 않습니다. 예: a4:5e:60:11:22:33 (12자리 MAC).'];
        }
        $bssid = SiteWifiAccessPoint::normalizeBssid($bssid);

        $ap = SiteWifiAccessPoint::query()->updateOrCreate(
            ['site_id' => $site->id, 'bssid' => $bssid],
            [
                'ssid' => ($data['ssid'] ?? null) ?: null,
                'label' => ($data['label'] ?? null) ?: null,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
                'created_by_id' => $userId,
            ]
        );

        return ['success' => true, 'id' => $ap->id, 'bssid' => $ap->bssid];
    }

    public function delete(int $id): array
    {
        $ap = SiteWifiAccessPoint::find($id);
        if (! $ap) {
            return ['success' => false, 'error' => 'AP 를 찾을 수 없습니다.'];
        }
        $ap->delete();

        return ['success' => true];
    }

    private function resolveSite(string $siteId): ?Site
    {
        $siteId = trim($siteId);
        if ($siteId === '' || in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            return null;
        }
        if (is_numeric($siteId)) {
            return Site::find((int) $siteId);
        }
        $code = str_contains($siteId, ' - ') ? trim(strstr($siteId, ' - ', true)) : $siteId;

        return Site::query()->where('code', $siteId)->orWhere('code', $code)->orWhere('name', $siteId)->first();
    }
}
