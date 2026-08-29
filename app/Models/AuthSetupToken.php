<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PIN 초대·재설정 링크 한 장.
 *
 * 원문 토큰은 링크에만 있고 서버는 해시만 갖는다. 한 번 쓰면 폐기되고, 새로 발급하면
 * 그 사람의 이전 링크는 전부 무효가 된다 — 문자에 남은 옛 링크가 계속 열쇠로
 * 남지 않게 하기 위해서다.
 */
class AuthSetupToken extends Model
{
    public const PURPOSE_INVITE = 'invite';

    public const PURPOSE_RESET = 'reset';

    /** 초대는 사흘, 재설정은 30분 — 재설정은 현장에서 그 자리에 쓰는 링크다. */
    private const TTL_MINUTES = [self::PURPOSE_INVITE => 4320, self::PURPOSE_RESET => 30];

    protected $fillable = ['user_id', 'token_hash', 'purpose', 'issued_by_id', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 새 링크 발급 — 이전 미사용 링크는 전부 무효로 만든다. */
    public static function issue(User $user, string $purpose, ?int $issuedById = null): string
    {
        self::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

        $token = Str::random(48);

        self::query()->create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'purpose' => $purpose,
            'issued_by_id' => $issuedById,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES[$purpose] ?? 30),
        ]);

        return $token;
    }

    /** 살아 있는 링크인가 — 만료·사용 여부를 함께 본다. */
    public static function findUsable(string $token): ?self
    {
        $row = self::query()->where('token_hash', self::hash($token))->first();

        if (! $row || $row->used_at !== null || $row->expires_at->isPast()) {
            return null;
        }

        return $row;
    }

    public function consume(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }
}
