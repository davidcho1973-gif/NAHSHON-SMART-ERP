<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * 근무일 달력 — 공정 엔진이 «이 날 일할 수 있는가» 를 묻는 유일한 자리.
 *
 * 예전 공정표는 7일 달력이었다. 그래서 수지바닥(Ucrete) 착수가 추수감사절(11/26)에,
 * 보건소 최종검사가 성탄절(12/25)에, 인수가 새해 첫날(1/1)에 잡혀 있었다 — 화면에서는
 * 멀쩡해 보이고 원청 회의에서 그 날짜를 말하는 순간 신뢰가 무너진다.
 *
 * 두 가지를 가른다.
 *  - 휴일: 현장이 실제로 쉬는 날만. 미국 현장 기본값은 노동절·추수감사절(+다음날)·성탄절·새해.
 *    MLK·콜럼버스·재향군인의 날은 대부분의 현장이 일하므로 기본에 넣지 않는다 — 회사가
 *    config 로 더한다(config/org.php 'holidays').
 *  - 주말: 기본은 «근무일» 이다(7일 주). 지금까지의 모든 날짜가 그 전제로 잡혀 있어서,
 *    주말을 갑자기 빼면 표 전체가 몇 주씩 밀린다. 'workweek' 를 6 이나 5 로 바꾸면 적용된다.
 *
 * 양생·대기처럼 «경과 시간» 인 활동은 이 달력을 타지 않는다 — 콘크리트는 휴일에도 굳는다.
 * 그 구분은 활동의 payload.calendar === 'elapsed' 가 말한다.
 */
class WorkCalendar
{
    /** @var array<string, true> */
    private array $holidays;

    /**
     * 회사가 휴일을 적어 두지 않았을 때 — 미국 현장 기본 휴일을 «물어본 해» 마다 만든다.
     *
     * 처음에는 «올해와 내년» 만 만들어 두었는데, 그러면 답이 오늘 날짜에 따라 달라진다.
     * 2028년 공정표를 올해 세우면 2028년 노동절이 근무일로 잡히고, 같은 공정표를 내년에
     * 다시 계산하면 하루가 밀린다. 시험도 마찬가지로 해가 바뀌면 깨졌다. 달력은 «언제
     * 물었는가» 가 아니라 «어느 날인가» 만으로 답해야 한다.
     *
     * @var array<int, true> 이미 만든 해
     */
    private array $builtYears = [];

    private bool $useDefaults;

    private int $workweek;

    public function __construct(?array $holidays = null, ?int $workweek = null)
    {
        $list = $holidays ?? config('org.holidays');
        $this->useDefaults = ! is_array($list) || $list === [];

        $this->holidays = $this->useDefaults ? [] : array_fill_keys(array_map(
            fn ($d) => CarbonImmutable::parse((string) $d)->toDateString(),
            array_filter($list, fn ($d) => is_string($d) && trim($d) !== ''),
        ), true);

        $ww = $workweek ?? (int) config('org.workweek', 7);
        $this->workweek = in_array($ww, [5, 6, 7], true) ? $ww : 7;
    }

    public function isWorkday(CarbonImmutable $d): bool
    {
        $d = $d->startOfDay();
        $this->ensureYear($d->year);
        if (isset($this->holidays[$d->toDateString()])) {
            return false;
        }
        if ($this->workweek === 5 && $d->isWeekend()) {
            return false;
        }
        if ($this->workweek === 6 && $d->isSunday()) {
            return false;
        }

        return true;
    }

    /** 그 날이 근무일이면 그대로, 아니면 다음 근무일. */
    public function nextWorkday(CarbonImmutable $d): CarbonImmutable
    {
        $d = $d->startOfDay();
        $guard = 0;
        while (! $this->isWorkday($d) && $guard++ < 400) {
            $d = $d->addDay();
        }

        return $d;
    }

    /** 그 날이 근무일이면 그대로, 아니면 이전 근무일. */
    public function prevWorkday(CarbonImmutable $d): CarbonImmutable
    {
        $d = $d->startOfDay();
        $guard = 0;
        while (! $this->isWorkday($d) && $guard++ < 400) {
            $d = $d->subDay();
        }

        return $d;
    }

    /** 근무일 기준으로 n일 뒤 (n=0 이면 같은 근무일). 시작일이 휴일이면 먼저 다음 근무일로 옮긴다. */
    public function addWorkdays(CarbonImmutable $from, int $n): CarbonImmutable
    {
        $d = $this->nextWorkday($from);
        $guard = 0;
        while ($n > 0 && $guard++ < 2000) {
            $d = $d->addDay();
            if ($this->isWorkday($d)) {
                $n--;
            }
        }

        return $d;
    }

    /** 근무일 기준으로 n일 앞 (n=0 이면 같은 근무일). */
    public function subWorkdays(CarbonImmutable $from, int $n): CarbonImmutable
    {
        $d = $this->prevWorkday($from);
        $guard = 0;
        while ($n > 0 && $guard++ < 2000) {
            $d = $d->subDay();
            if ($this->isWorkday($d)) {
                $n--;
            }
        }

        return $d;
    }

    /** a 부터 b 까지(둘 다 포함) 사이의 근무일 수에서 1 을 뺀 «폭». a==b 이면 0. */
    public function workdaySpan(CarbonImmutable $a, CarbonImmutable $b): int
    {
        $a = $a->startOfDay();
        $b = $b->startOfDay();
        if ($b->lessThan($a)) {
            return 0;
        }
        $n = 0;
        for ($d = $a->addDay(); $d->lessThanOrEqualTo($b); $d = $d->addDay()) {
            if ($this->isWorkday($d)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<string> 설정에 아무것도 없을 때의 미국 현장 기본 휴일 */
    public static function defaultUsHolidays(int $fromYear, int $toYear): array
    {
        $out = [];
        for ($y = $fromYear; $y <= $toYear; $y++) {
            $laborDay = CarbonImmutable::create($y, 9, 1)->next(CarbonImmutable::MONDAY);
            if (CarbonImmutable::create($y, 9, 1)->isMonday()) {
                $laborDay = CarbonImmutable::create($y, 9, 1);
            }
            $thanksgiving = CarbonImmutable::create($y, 11, 1)->nthOfMonth(4, CarbonImmutable::THURSDAY);
            $out[] = $laborDay->toDateString();
            $out[] = $thanksgiving->toDateString();
            $out[] = $thanksgiving->addDay()->toDateString();
            $out[] = "{$y}-12-25";
            $out[] = "{$y}-01-01";
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /** @return list<string> 설정한 휴일 전부, 또는 기본 휴일이면 지금까지 물어본 해의 것(없으면 올해·내년). */
    public function holidays(): array
    {
        if ($this->useDefaults && $this->builtYears === []) {
            $this->ensureYear((int) date('Y'));
            $this->ensureYear((int) date('Y') + 1);
        }

        $h = array_keys($this->holidays);
        sort($h);

        return $h;
    }

    private function ensureYear(int $year): void
    {
        if (! $this->useDefaults || isset($this->builtYears[$year])) {
            return;
        }
        $this->builtYears[$year] = true;
        foreach (self::defaultUsHolidays($year, $year) as $date) {
            $this->holidays[$date] = true;
        }
    }

    public function workweek(): int
    {
        return $this->workweek;
    }
}
