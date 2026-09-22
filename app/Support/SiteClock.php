<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 현장 시계 — 사람에게 보여 주는 시각은 <b>그 일이 일어난 곳의 시계</b> 로 쓴다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 저장은 순간(instant)이고 화면은 벽시계다. 둘 사이를 옮기려면 «어느 곳의 시계인가»
 * 가 필요한데, 지금까지 화면마다 그 질문을 빠뜨리고 `format('H:i')` 만 불렀다.
 * 그러면 서버 시계(앱 시간대)가 그대로 나온다 — 사바나에서 아침 7시 50분에 찍은
 * 출근이 화면에 <b>04:50</b> 으로 뜬다. 기록은 옳은데 화면만 거짓말을 한다.
 *
 * 2026-09 에 이 일이 두 번 일어났다. 한 번은 저장에서(게이트가 현장 벽시계를 저장),
 * 한 번은 화면에서(같은 3시간이 반대 방향으로). 원인은 같다 — <b>시간대 없는 시각</b>.
 *
 * 그래서 규칙을 한 곳으로 모은다. 출퇴근처럼 «현장에서 일어난 일» 의 시각은 전부
 * 여기를 지난다. 화면마다 각자 변환하면 한 곳은 반드시 빠지고, 그 화면만 3시간
 * 어긋난 채 몇 달을 간다.
 *
 * 읽기(read)도 같은 곳에 둔다. 보여 줄 때만 현장 시계로 바꾸고 입력은 서버 시계로
 * 읽으면, 사람이 화면에서 본 07:50 을 그대로 다시 적었을 때 값이 3시간 움직인다.
 */
final class SiteClock
{
    /** @var array<int, Site|null> 한 요청 안에서 같은 현장을 여러 번 묻는다(목록 500줄). */
    private static array $cache = [];

    /** 이 현장의 시계. 현장을 모르면 앱 시계. */
    public static function zone(Site|int|null $site): string
    {
        $site = self::site($site);

        return (string) ($site?->timezone ?: config('app.timezone'));
    }

    /**
     * 그 순간을 현장 시계로 쓴 문자열. 순간이 없으면 null.
     */
    public static function show(Site|int|null $site, ?Carbon $moment, string $format = 'H:i'): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->copy()->setTimezone(self::zone($site))->format($format);
    }

    /**
     * 사람이 현장 시계로 적은 벽시계 문자열을 순간으로 읽는다.
     *
     * 화면이 현장 시계로 보여 주므로 입력도 현장 시계여야 한다. 짝을 맞추지 않으면
     * 기록을 열어서 아무것도 안 고치고 저장만 해도 시각이 움직인다.
     *
     * 돌려주는 값은 <b>앱 시간대</b>로 맞춰 둔다. 같은 순간이지만, 시간대 없는 칸
     * (2026-07-24 마이그레이션)에 저장될 때 Laravel 은 Carbon 이 들고 있는 시계로
     * 문자열을 만든다 — 현장 시계를 든 채로 넣으면 현장 벽시계 숫자가 그대로 저장되고,
     * 그게 게이트가 3시간 밀렸던 바로 그 사고다. 순간은 하나, 저장은 한 시계로.
     */
    public static function read(string $wallClock, Site|int|null $site): Carbon
    {
        return Carbon::parse(trim($wallClock), self::zone($site))
            ->setTimezone(config('app.timezone'));
    }

    /**
     * 화면에 붙이는 시계 이름 — 'EDT' 처럼. 어느 시계로 보고 있는지 말해 준다.
     *
     * 이것이 화면에 없으면 «3시간 차이» 를 발견하는 사람은 늘 사후에 발견한다.
     */
    public static function label(Site|int|null $site, ?Carbon $moment = null): string
    {
        return ($moment ?? Carbon::now())->copy()->setTimezone(self::zone($site))->format('T');
    }

    /** 시험에서 현장을 바꿔 가며 볼 때 캐시가 남지 않게. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    private static function site(Site|int|null $site): ?Site
    {
        if ($site instanceof Site) {
            return $site;
        }
        if (! $site) {
            return null;
        }

        return self::$cache[$site] ??= Site::query()->find($site);
    }
}
