<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 현장 WiFi AP 1개(BSSID). 하이브리드 출퇴근에서 "현장 내" 판정에 쓰인다.
 */
class SiteWifiAccessPoint extends Model
{
    protected $fillable = ['site_id', 'bssid', 'ssid', 'label', 'active', 'created_by_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
}
