<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 한 사람의 한 기기로 가는 알림 주소.
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token',
        'content_encoding', 'user_agent', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 같은 기기가 두 번 등록되면 알림이 두 번 온다 — endpoint 로 찾아 갱신한다.
     * (endpoint 는 길어서 유니크 인덱스를 걸 수 없으므로 해시를 열쇠로 쓴다.)
     */
    public static function remember(User $user, array $subscription, ?string $userAgent = null): self
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');

        return static::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => (string) ($subscription['keys']['p256dh'] ?? ''),
                'auth_token' => (string) ($subscription['keys']['auth'] ?? ''),
                'content_encoding' => (string) ($subscription['contentEncoding'] ?? 'aes128gcm'),
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'last_used_at' => now(),
            ],
        );
    }
}
