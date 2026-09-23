<?php

namespace App\Services\Wbs;

use App\Models\OpsIntakeBatch;
use App\Models\Site;
use App\Models\User;
use App\Models\WeekBoardLine;
use App\Services\Ocr\OcrEngine;
use App\Support\SiteClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 상황실 글 → 이번 주 작업판 — 반장이 「급탕 배관 끝났습니다」 라고 올리면 그 줄이 완료로.
 *
 * ── 왜 ───────────────────────────────────────────────────────────────
 * 사장 말: 「진짜 매일매일 살아 움직이는 공정표를 만들고 싶다. 죽은 공정표 말고」.
 * 작업판이 살아 있으려면 날마다 누군가 상태를 눌러야 하는데, 반장은 이미 상황실에
 * 그 말을 하고 있다. 같은 말을 두 곳에 하게 하면 한 곳은 반드시 죽는다. 그래서
 * 상황실에 올라온 말을 듣고 작업판을 움직인다.
 *
 * ── 지키는 것 ──────────────────────────────────────────────────────────
 *  1. <b>말이 있어야 움직인다.</b> 사진만 올라온 글은 건드리지 않는다 — 배관 사진 한 장은
 *     «배관이 있다» 만 말할 뿐, 끝났는지 진행 중인지 말해 주지 않는다(상황실 판독기와
 *     같은 규칙, 사장 지적에서 나온 것). 사진은 글의 증거로만 따라간다.
 *  2. <b>앞으로만 간다.</b> 사람이 완료로 둔 줄은 건드리지 않는다. 확신이 낮은 판단(60 미만)도
 *     버린다. 잘못 들은 한마디가 완료를 예정으로 되돌리면 안 된다.
 *  3. <b>흔적을 남긴다.</b> 어느 글의 어느 문장을 듣고 바꿨는지 줄에 적는다. 화면에서
 *     «상황실에서 자동» 으로 보이고, 사람이 버튼을 누르면 사람의 결정이 이긴다(흔적은 지워진다).
 *  4. <b>줄이 없으면 부르지 않는다.</b> 이번 주에 적힌 줄이 없는 현장은 AI 를 부르지 않는다.
 */
class WeekBoardReflector
{
    /** 이 값 미만은 «들은 것 같다» 수준이다 — 작업판을 움직이지 않는다. */
    public const MIN_CONFIDENCE = 60;

    public function __construct(
        private readonly OcrEngine $engine,
        private readonly WeekBoardService $board,
    ) {}

    /**
     * @return array{updated: int, lines: array<int, array{id: int, trade: string, task: string, status: string, statusLabel: string, quote: string}>}
     */
    public function reflect(OpsIntakeBatch $batch): array
    {
        $none = ['updated' => 0, 'lines' => []];

        $text = trim((string) $batch->raw_text);
        if ($text === '' || $batch->source === 'meeting') {
            return $none;   // 사진만, 또는 회의(그건 초안 경로로 따로 간다).
        }

        $site = $batch->site_id ? Site::query()->find($batch->site_id) : null;
        if ($site === null) {
            return $none;
        }

        $lines = $this->openLinesThisWeek($site, $batch->created_at);
        if ($lines->isEmpty()) {
            return $none;
        }

        $result = $this->engine->analyze([], $this->prompt($text, $lines, $batch), $this->schema());
        $marks = is_array($result['data']['marks'] ?? null) ? $result['data']['marks'] : [];

        $who = $batch->created_by_id ? User::query()->whereKey($batch->created_by_id)->value('name') : null;
        $source = mb_substr(trim('상황실 '.SiteClock::show($site, Carbon::parse($batch->created_at), 'm/d').' '.($who ?: '')), 0, 120);

        $out = [];
        foreach ($marks as $m) {
            if (! is_array($m)) {
                continue;
            }
            $line = $lines->firstWhere('id', (int) ($m['id'] ?? 0));
            $status = (string) ($m['status'] ?? 'none');
            if ($line === null || ! in_array($status, [WeekBoardLine::STATUS_DOING, WeekBoardLine::STATUS_DONE, WeekBoardLine::STATUS_BLOCKED], true)) {
                continue;
            }
            if ((int) ($m['confidence'] ?? 0) < self::MIN_CONFIDENCE || $status === $line->status) {
                continue;
            }

            $quote = mb_substr(trim((string) ($m['quote'] ?? '')), 0, 500);
            $reason = $status === WeekBoardLine::STATUS_BLOCKED ? (trim((string) ($m['reason'] ?? '')) ?: null) : null;

            $this->board->applyStatus($line, $status, $reason, null, [
                'auto_source' => $source,
                'auto_quote' => $quote ?: null,
                'auto_batch_id' => $batch->id,
                'auto_at' => Carbon::now(),
            ]);

            $out[] = [
                'id' => $line->id,
                'trade' => $line->trade,
                'task' => $line->task,
                'status' => $status,
                'statusLabel' => WeekBoardLine::STATUSES[$status],
                'quote' => $quote,
            ];
        }

        if ($out !== [] && $batch->exists) {
            $batch->forceFill(['week_board_updated' => count($out)])->save();
        }

        return ['updated' => count($out), 'lines' => $out];
    }

    /**
     * 이번 주(글이 올라온 날이 속한 주, 현장 시계)의 아직 안 끝난 줄.
     *
     * @return Collection<int, WeekBoardLine>
     */
    private function openLinesThisWeek(Site $site, mixed $at): Collection
    {
        $day = Carbon::parse($at ?? Carbon::now())->setTimezone(SiteClock::zone($site));
        $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

        return WeekBoardLine::query()
            ->where('site_id', $site->id)
            ->whereDate('week_start', $weekStart)
            ->where('status', '!=', WeekBoardLine::STATUS_DONE)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /** @param Collection<int, WeekBoardLine> $lines */
    private function prompt(string $text, Collection $lines, OpsIntakeBatch $batch): string
    {
        $list = $lines->map(fn (WeekBoardLine $l): string => sprintf(
            '- id %d · %s · %s (지금 %s)', $l->id, $l->trade, $l->task, WeekBoardLine::STATUSES[$l->status] ?? $l->status,
        ))->implode("\n");

        $photos = collect(is_array($batch->photo_kinds) ? $batch->photo_kinds : [])
            ->map(fn ($k, $i): string => sprintf('- %d번째 사진: %s%s', $i + 1, $k['label'] ?? '', ($k['summary'] ?? '') !== '' ? ' — '.$k['summary'] : ''))
            ->implode("\n");
        $photoBlock = $photos === '' ? '' : "\n[함께 올라온 사진 — 참고용. 사진만 보고 상태를 정하지 마세요]\n{$photos}\n";

        return <<<PROMPT
당신은 미국 건설 현장 소장의 비서입니다. 아래는 이번 주 작업판의 줄(공종 · 하는 일 · 지금 상태)이고,
그 아래는 반장이 현장 상황실에 방금 올린 글입니다. 글에서 **작업판의 어느 줄이 어떻게 됐다고 말했는지**만 찾아 JSON 으로 돌려주세요.

[이번 주 작업판]
{$list}
{$photoBlock}
규칙:
- status 는 doing(진행중 — 시작했다·하고 있다), done(완료 — 끝냈다·다 했다), blocked(못함 — 못 했다·막혔다·자재 없다), none(글에 그 줄 얘기가 없다) 중 하나.
- 글에 **분명히** 그 줄 얘기가 있을 때만 doing/done/blocked. 비슷한 낱말만 나오면 none. 추측 금지.
- «절반 했다» «반쯤» «하고 있다» 는 doing 이지 done 이 아닙니다. done 은 끝났다는 말이 있을 때만.
- blocked 이면 reason 에 이유를 짧게(자재 미입고 / 앞 공정 안 끝남 / 인원 부족 …).
- quote 에는 그렇게 판단한 글의 문장을 그대로 옮깁니다.
- confidence 는 0~100. 확실하지 않으면 낮게.
- 글에 없는 줄은 목록에 넣지 않아도 됩니다.

[상황실 글]
{$text}
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'marks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['doing', 'done', 'blocked', 'none']],
                            'reason' => ['type' => 'string'],
                            'quote' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer'],
                        ],
                        'required' => ['id', 'status', 'confidence'],
                    ],
                ],
            ],
            'required' => ['marks'],
        ];
    }
}
