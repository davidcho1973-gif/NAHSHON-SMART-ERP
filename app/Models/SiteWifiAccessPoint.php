<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 현장 네트워크 항목 1개. 하이브리드 출퇴근에서 "현장 내" 판정에 쓰인다.
 *
 * 두 종류가 한 표에 산다. 둘 다 "이 네트워크에 붙어 있으면 현장에 있다"는 같은 뜻인데,
 * 알아내는 경로가 달라서 쓸 수 있는 시점이 다르다.
 *
 *   bssid   — AP 의 MAC. 가장 정확하지만 폰이 알려 줘야 한다. 웹 브라우저는 접속한
 *             WiFi 의 BSSID 를 읽는 표준 API 가 없어서, 네이티브 앱이 나와야 쓸 수 있다.
 *   network — 현장 WiFi 를 타고 나온 요청의 공인 IP 대역(CIDR). 폰이 알려 주는 게 아니라
 *             서버가 요청에서 직접 보는 값이라 브라우저에서도 오늘 바로 동작한다.
 *
 * 값은 둘 다 bssid 칸에 담는다 — MAC 과 CIDR 은 글자 모양이 겹치지 않아 섞이지 않는다.
 */
class SiteWifiAccessPoint extends Model
{
    public const KIND_BSSID = 'bssid';

    public const KIND_NETWORK = 'network';

    public const KIND_OPTIONS = [
        self::KIND_BSSID => '공유기 MAC (BSSID)',
        self::KIND_NETWORK => '공인 IP 대역',
    ];

    protected $fillable = ['site_id', 'kind', 'bssid', 'ssid', 'label', 'active', 'created_by_id'];

    protected $attributes = ['kind' => self::KIND_BSSID];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isNetwork(): bool
    {
        return $this->kind === self::KIND_NETWORK;
    }

    /** BSSID 정규화: 소문자, 구분자 콜론 통일(aa:bb:cc:dd:ee:ff). */
    public static function normalizeBssid(string $bssid): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $bssid) ?? '');

        if (strlen($hex) === 12) {
            return implode(':', str_split($hex, 2));
        }

        return strtolower(trim($bssid));
    }

    public static function isValidBssid(string $bssid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', self::normalizeBssid($bssid));
    }

    /**
     * IP 대역 정규화. 접두길이가 없으면 단일 주소로 본다(/32 · /128).
     */
    public static function normalizeCidr(string $value): string
    {
        $value = trim($value);
        if (! str_contains($value, '/')) {
            return $value;
        }
        [$net, $bits] = explode('/', $value, 2);

        return trim($net).'/'.trim($bits);
    }

    public static function isValidCidr(string $value): bool
    {
        $value = self::normalizeCidr($value);
        $net = $value;
        $bits = null;

        if (str_contains($value, '/')) {
            [$net, $rawBits] = explode('/', $value, 2);
            if (! preg_match('/^\d{1,3}$/', $rawBits)) {
                return false;
            }
            $bits = (int) $rawBits;
        }

        $packed = @inet_pton($net);
        if ($packed === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        return $bits >= 0 && $bits <= strlen($packed) * 8;
    }

    /**
     * 이 IP 가 대역 안에 드는가.
     *
     * 접두길이가 없으면 정확히 같은 주소일 때만 참이다. IPv4·IPv6 를 같은 방식으로
     * 다룬다 — inet_pton 이 준 바이트열을 접두길이만큼 비교한다.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        $cidr = self::normalizeCidr($cidr);

        if (! str_contains($cidr, '/')) {
            $a = @inet_pton($ip);
            $b = @inet_pton($cidr);

            return $a !== false && $b !== false && $a === $b;
        }

        [$net, $rawBits] = explode('/', $cidr, 2);
        $ipPacked = @inet_pton($ip);
        $netPacked = @inet_pton($net);

        if ($ipPacked === false || $netPacked === false || strlen($ipPacked) !== strlen($netPacked)) {
            return false;
        }

        $bits = (int) $rawBits;
        if ($bits < 0 || $bits > strlen($netPacked) * 8) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($ipPacked, 0, $wholeBytes) !== substr($netPacked, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($ipPacked[$wholeBytes] & $mask) === ($netPacked[$wholeBytes] & $mask);
    }
}
