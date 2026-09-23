<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * 근무 규칙 — 「몇 시부터 몇 시까지, 얼마가 정규이고, 얼마가 급여에서 빠지는가」.
 *
 * ── 왜 한 곳인가 ───────────────────────────────────────────────────────
 * 같은 질문에 두 곳이 서로 다르게 답하고 있었다. 급여 쪽(`AttendanceTimesheetSync`)은
 * 「8시간 정규, 4시간 넘게 일한 날은 점심 1시간 무급」 을 상수로 갖고 있었고, 출퇴근
 * 기록 화면은 <b>점심을 빼지 않은</b> 시간을 「근무」 로 보여 줬다. 한 사람의 하루가
 * 화면에서는 9시간 30분, 급여에서는 8시간 30분이다. 둘 다 맞아 보이지만 하나는 틀렸고,
 * 그 차이가 임금이라 언젠가 반드시 다툼이 된다.
 *
 * 인원 세는 규칙을 `DailyHeadcountService` 하나로 모았던 것과 같은 이유다 —
 * <b>같은 사실을 두 곳에서 계산하면 언젠가 갈라진다.</b>
 *
 * ── 왜 현장마다인가 ────────────────────────────────────────────────────
 * 나손 현장은 여러 주에 있고 프로젝트마다 작업 시간이 다르다. 규칙이 코드 상수면
 * 모든 현장이 남의 시간표로 정산된다. 그래서 값은 현장(sites)에 두고, 읽는 방법만
 * 여기 둔다. 현장에 값이 없으면 지금까지 쓰던 기본값이 그대로 쓰인다.
 */
final class WorkRules
{
    public const DEFAULT_REGULAR_MINUTES = 480;   // 8시간

    public const DEFAULT_BREAK_MINUTES = 60;      // 점심 1시간 — 급여 제외

    public const DEFAULT_BREAK_AFTER_MINUTES = 240;   // 4시간 넘게 일한 날만 공제

    public const DEFAULT_START = '07:00';

    /** @var array<int, self> */
    private static array $cache = [];

    private function __construct(
        public readonly ?int $siteId,
        public readonly string $start,
        public readonly string $end,
        public readonly int $regularMinutes,
        public readonly int $breakMinutes,
        public readonly int $breakAfterMinutes,
        /** 주 작업일 5·6·7 — 공정 달력(WorkCalendar)이 «이 날 일하는가» 를 물을 때 쓴다. */
        public readonly int $workweekDays,
    ) {}

    /** 회사 전체 기본 주 작업일(config org.workweek). 현장에 값이 없을 때. */
    public static function defaultWorkweek(): int
    {
        $ww = (int) config('org.workweek', 7);

        return in_array($ww, [5, 6, 7], true) ? $ww : 7;
    }

    public static function forSite(Site|int|null $site): self
    {
        $id = $site instanceof Site ? $site->id : ($site ? (int) $site : null);

        if ($id !== null && isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        // 규칙 칸이 실려 있지 않은 현장(일부 칼럼만 select 한 관계)을 그대로 읽으면
        // 값이 전부 null 로 보이고, 조용히 기본 규칙으로 임금이 계산된다. 그럴 때는
        // 현장을 다시 읽는다 — 임금을 «아마 이 값일 것» 으로 계산하지 않는다.
        $row = $site instanceof Site && array_key_exists('regular_minutes', $site->getAttributes())
            ? $site
            : ($id ? Site::query()->find($id) : null);

        $rules = new self(
            siteId: $row?->id,
            start: self::clock($row?->work_start) ?? self::DEFAULT_START,
            // 현장에 종료 시각이 없으면 회사 전체의 자동 퇴근 마감 시각을 쓴다 —
            // 설정을 안 한 현장이 지금까지와 다르게 동작하면 안 된다.
            end: self::clock($row?->work_end) ?? sprintf('%02d:00', Org::int('attendance.indirect_cutoff_hour', 16)),
            regularMinutes: (int) ($row?->regular_minutes ?? self::DEFAULT_REGULAR_MINUTES),
            breakMinutes: (int) ($row?->break_minutes ?? self::DEFAULT_BREAK_MINUTES),
            breakAfterMinutes: (int) ($row?->break_after_minutes ?? self::DEFAULT_BREAK_AFTER_MINUTES),
            workweekDays: in_array((int) ($row?->workweek_days ?? 0), [5, 6, 7], true) ? (int) $row->workweek_days : self::defaultWorkweek(),
        );

        if ($row) {
            self::$cache[$row->id] = $rules;
        }

        return $rules;
    }

    /**
     * 일한 시간을 급여가 보는 대로 나눈다.
     *
     * 무급 휴게는 <b>오래 일한 날에만</b> 뺀다. 반나절 일한 사람의 점심까지 빼면
     * 일하지 않은 시간을 근거로 임금을 깎는 셈이 된다.
     *
     * @return array{worked:int, break:int, payable:int, regular:int, overtime:int}
     */
    public function split(int|float $workedMinutes): array
    {
        $worked = max(0, (int) $workedMinutes);
        $break = $worked > $this->breakAfterMinutes ? min($this->breakMinutes, $worked) : 0;
        $payable = max(0, $worked - $break);

        return [
            'worked' => $worked,
            'break' => $break,
            'payable' => $payable,
            'regular' => min($payable, $this->regularMinutes),
            'overtime' => max(0, $payable - $this->regularMinutes),
        ];
    }

    /** 그날 그 현장의 작업 종료 시각 — 현장 시계로 읽는다. */
    public function endOfWorkDay(string $workDate): Carbon
    {
        return SiteClock::read($workDate.' '.$this->end.':00', $this->siteId);
    }

    /** 사람이 읽는 한 줄 — 「07:00–16:00 · 정규 8시간 · 점심 1시간 무급」. */
    public function summary(): string
    {
        $bits = [$this->start.'–'.$this->end, '정규 '.self::hours($this->regularMinutes)];
        if ($this->breakMinutes > 0) {
            $bits[] = '점심 '.self::hours($this->breakMinutes).' 무급';
        }
        // 주 7일은 «쉬는 날 없음» 이라 굳이 적지 않는다 — 6일·5일일 때만 눈에 띄어야 한다.
        if ($this->workweekDays !== 7) {
            $bits[] = '주 '.$this->workweekDays.'일';
        }

        return implode(' · ', $bits);
    }

    /** 분을 「8시간 30분」 으로. 0 은 빈 문자열이 아니라 「0분」 이다. */
    public static function hours(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'분';
        }

        return intdiv($minutes, 60).'시간'.($minutes % 60 ? ' '.($minutes % 60).'분' : '');
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** 'HH:MM' 로 — 'HH:MM:SS' 도 시각 칸도 받아 준다. 값이 없으면 null. */
    private static function clock(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }
        $text = trim((string) $value);

        return preg_match('/^(\d{2}:\d{2})/', $text, $m) ? $m[1] : null;
    }
}
