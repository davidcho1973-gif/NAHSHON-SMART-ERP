<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 작업자 휴대폰 1대 = 토큰 1개.
 *
 * 원문 토큰은 기기(localStorage)에만 남고 서버는 해시만 갖는다 — DB 가 새어도 남의 출퇴근을
 * 대신 찍을 수 없게 하기 위해서다.
 */
class WorkerDevice extends Model
{
    protected $fillable = [
        'employee_id',
        'token_hash',
        'label',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 이 직원용 새 기기 토큰을 발급한다. 원문 토큰을 돌려주며, 서버에는 해시만 남는다.
     */
    public static function issueFor(Employee $employee, ?string $label = null): string
    {
        $token = Str::random(48);

        self::query()->create([
            'employee_id' => $employee->id,
            'token_hash' => self::hash($token),
            'label' => $label ? Str::limit($label, 118) : null,
            'last_used_at' => now(),
        ]);

        return $token;
    }

    /** 토큰으로 직원을 찾고 마지막 사용 시각을 갱신한다. */
    public static function resolve(string $token): ?Employee
    {
        $device = self::query()->where('token_hash', self::hash($token))->first();
        if (! $device) {
            return null;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return $device->employee;
    }

    public static function forget(string $token): void
    {
        self::query()->where('token_hash', self::hash($token))->delete();
    }
}
