<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 손님 전용 링크 — 계정 없이 현장 공정 현황만 보는 열람 토큰.
 *
 * 토큰이 곧 열쇠다. 그래서 두 가지를 지킨다:
 *  - 추측 불가: 40자 난수 — 주소를 모르면 못 연다.
 *  - 회수 가능: revoked_at 을 찍으면 이미 퍼진 링크도 그 자리에서 죽는다.
 */
class GuestLink extends Model
{
    protected $fillable = [
        'token', 'site_id', 'label', 'created_by', 'expires_at', 'revoked_at',
        'view_count', 'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 지금 이 링크로 들어올 수 있는가 — 회수되지 않았고, 기한이 남아 있다. */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function issue(int $siteId, ?string $label, ?int $days, ?int $userId): self
    {
        return self::create([
            'token' => Str::random(40),
            'site_id' => $siteId,
            'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
            'created_by' => $userId,
            'expires_at' => $days !== null && $days > 0 ? now()->addDays($days) : null,
        ]);
    }

    public function url(): string
    {
        return route('guest.view', ['token' => $this->token]);
    }
}
