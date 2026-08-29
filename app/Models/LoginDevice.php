<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 로그인해도 되는 폰 1대 = 토큰 1개.
 *
 * PIN 은 4자리라 그것만으로는 열쇠가 될 수 없다. 이 기기 토큰이 나머지 절반이다 —
 * 원문은 그 폰에만 남고(localStorage) 서버는 해시만 갖는다.
 */
class LoginDevice extends Model
{
    /** 한 사람이 등록해 둘 수 있는 폰 수 — 넘으면 가장 오래된 것을 지운다. */
    public const MAX_PER_USER = 2;

    protected $fillable = ['user_id', 'token_hash', 'label', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 새 기기 토큰 발급 — 원문을 돌려주고 서버에는 해시만 남긴다. */
    public static function issueFor(User $user, ?string $label = null): string
    {
        $token = Str::random(48);

        self::query()->create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'label' => $label ? Str::limit($label, 118) : null,
            'last_used_at' => now(),
        ]);

        // 폰을 무한정 쌓아 두지 않는다 — 잃어버린 폰이 계속 열쇠로 남으면 안 된다.
        $extra = self::query()->where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->pluck('id')
            ->slice(self::MAX_PER_USER);
        if ($extra->isNotEmpty()) {
            self::query()->whereIn('id', $extra)->delete();
        }

        return $token;
    }

    /** 토큰으로 사용자를 찾는다. 없으면 null — 이때 PIN 입력창을 보여주지 않는다. */
    public static function resolve(string $token): ?User
    {
        if ($token === '') {
            return null;
        }

        $device = self::query()->where('token_hash', self::hash($token))->first();
        if (! $device) {
            return null;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return $device->user;
    }

    public static function forget(string $token): void
    {
        if ($token !== '') {
            self::query()->where('token_hash', self::hash($token))->delete();
        }
    }
}
