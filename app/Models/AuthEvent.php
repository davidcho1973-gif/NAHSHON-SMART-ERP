<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 인증 감사 기록 — 누가 언제 어느 문으로 들어왔는가.
 *
 * 이 앱에 처음 생기는 기록이다. 로그인 성공·실패, 잠금, 초대·재설정 링크 발급과 사용이
 * 남지 않으면 "누가 내 계정으로 들어왔나", "이 링크는 누가 발급했나" 를 나중에 되짚을
 * 방법이 없다. 근태가 급여의 근거인 현장에서는 그 되짚기가 곧 방어 수단이다.
 */
class AuthEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'actor_id', 'event', 'method', 'ip', 'user_agent', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 기록이 실패해도 로그인 자체는 살아야 한다 — 감사가 서비스를 멈추면 안 된다. */
    public static function record(
        string $event,
        ?User $user = null,
        ?User $actor = null,
        ?string $method = null,
        ?Request $request = null,
        ?string $note = null,
    ): void {
        try {
            self::query()->create([
                'user_id' => $user?->id,
                'actor_id' => $actor?->id,
                'event' => $event,
                'method' => $method,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'note' => $note ? Str::limit($note, 250, '') : null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
