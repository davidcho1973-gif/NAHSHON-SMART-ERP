<?php

namespace App\Services\Attendance;

use App\Models\Site;
use App\Models\SiteWifiAccessPoint;

/**
 * 현장 네트워크 등록 관리 — 관리자가 현장별 화이트리스트를 등록/조회/삭제한다.
 * 하이브리드 출퇴근 판정이 이 목록을 참조한다.
 *
 * 두 가지를 담는다.
 *   공유기 MAC(BSSID) — 가장 정확하지만 네이티브 앱이 나와야 쓸 수 있다.
 *   공인 IP 대역      — 현장 WiFi 를 타고 나온 요청의 주소. 브라우저에서 오늘 바로 동작한다.
 *
 * 공유기 하나가 BSSID 를 여러 개 쓴다는 점에 주의한다 — 2.4GHz 와 5GHz 가 다르고, 메시
 * 공유기는 노드마다 다르다. 하나만 등록하면 폰이 다른 대역에 붙는 날 인식이 안 된다.
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

        $aps = SiteWifiAccessPoint::query()->where('site_id', $site->id)
            ->orderBy('kind')->orderBy('label')->orderBy('bssid')->get()
            ->map(fn (SiteWifiAccessPoint $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'kindLabel' => SiteWifiAccessPoint::KIND_OPTIONS[$a->kind] ?? $a->kind,
                'bssid' => $a->bssid,
                'ssid' => $a->ssid,
                'label' => $a->label,
                'active' => $a->active,
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
            // 지금 이 화면을 보고 있는 사람의 공인 IP. 현장 WiFi 에 붙어서 열었다면
            // 이 주소가 곧 그 현장의 주소다 — 버튼 한 번으로 등록할 수 있게 미리 준다.
            'myIp' => request()->ip(),
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

        $kind = (string) ($data['kind'] ?? SiteWifiAccessPoint::KIND_BSSID);
        if (! array_key_exists($kind, SiteWifiAccessPoint::KIND_OPTIONS)) {
            return ['success' => false, 'error' => '등록 종류가 올바르지 않습니다.'];
        }

        $value = trim((string) ($data['bssid'] ?? ''));

        if ($kind === SiteWifiAccessPoint::KIND_NETWORK) {
            if (! SiteWifiAccessPoint::isValidCidr($value)) {
                return ['success' => false, 'error' => 'IP 대역 형식이 올바르지 않습니다. 예: 203.0.113.24 또는 203.0.113.0/24'];
            }
            $value = SiteWifiAccessPoint::normalizeCidr($value);
        } else {
            if (! SiteWifiAccessPoint::isValidBssid($value)) {
                return ['success' => false, 'error' => 'BSSID 형식이 올바르지 않습니다. 예: a4:5e:60:11:22:33 (12자리 MAC).'];
            }
            $value = SiteWifiAccessPoint::normalizeBssid($value);
        }

        $ap = SiteWifiAccessPoint::query()->updateOrCreate(
            ['site_id' => $site->id, 'bssid' => $value],
            [
                'kind' => $kind,
                'ssid' => ($data['ssid'] ?? null) ?: null,
                'label' => ($data['label'] ?? null) ?: null,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
                'created_by_id' => $userId,
            ]
        );

        return ['success' => true, 'id' => $ap->id, 'kind' => $ap->kind, 'bssid' => $ap->bssid];
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
