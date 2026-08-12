<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * 배경에서 도는 부품이 "나 살아 있다"고 남기는 시각.
 *
 * 지금은 스케줄러 하나만 쓴다. 스케줄러는 이 시스템에서 가장 조용하게 실패하는 부품이다 —
 * 꺼져도 화면은 멀쩡하고, 다만 퇴근이 안 찍혀 근무시간이 0 이 되고 문서가 "분석 중"에
 * 머물 뿐이다. 무엇이 안 도는지 알아채는 데 며칠이 걸린다.
 */
class SystemHeartbeat extends Model
{
    public const SCHEDULER = 'scheduler';

    /**
     * 맥박이 이 시간(분)보다 오래되면 멈춘 것으로 본다.
     *
     * 스케줄러가 10분마다 찍으므로 두 번 연속 빠져야 경보가 울린다. 배포 중에는 잠깐
     * 끊기는데 그때마다 울리면 아무도 안 믿게 된다.
     */
    public const STALE_MINUTES = 25;

    protected $fillable = ['key', 'beat_at'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }

    public static function beat(string $key): void
    {
        static::query()->updateOrCreate(['key' => $key], ['beat_at' => Carbon::now()]);
    }

    /** 마지막 맥박. 한 번도 없었으면 null. */
    public static function lastBeat(string $key): ?Carbon
    {
        // 마이그레이션 전이거나 표가 없는 환경에서도 화면이 죽지 않아야 한다.
        if (! Schema::hasTable('system_heartbeats')) {
            return null;
        }

        return static::query()->where('key', $key)->value('beat_at');
    }

    /**
     * 사람이 읽을 수 있는 상태 한 덩어리.
     *
     * @return array{running: bool, last_beat_at: string|null, minutes_ago: int|null}
     */
    public static function health(string $key): array
    {
        $last = static::lastBeat($key);
        $minutes = $last ? (int) Carbon::now()->diffInMinutes($last, true) : null;

        return [
            'running' => $minutes !== null && $minutes <= self::STALE_MINUTES,
            'last_beat_at' => $last?->toIso8601String(),
            'minutes_ago' => $minutes,
        ];
    }
}
