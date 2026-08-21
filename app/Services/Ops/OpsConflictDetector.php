<?php

namespace App\Services\Ops;

use App\Models\ProcurementItem;
use App\Models\WbsItem;

/**
 * "확정된 것과 다르게 말했다" 를 코드가 잡아낸다.
 *
 * AI 에게도 어긋남을 찾게 하지만(도면 사양 대조), 숫자가 뒤로 가는 것처럼 <b>코드가
 * 확실히 아는</b> 어긋남은 AI 판단에 맡기지 않는다. 진행률이 80% 에서 30% 로 내려가는
 * 제안은 셋 중 하나다: 앞 보고가 부풀려졌거나, 지금 잘못 말했거나, 재시공이 있었거나.
 * 어느 쪽인지는 사람만 안다 — 그래서 조용히 덮어쓰지 않고 물어본다.
 *
 * 여기서 <b>고치지 않는다</b>는 점이 핵심이다. 이 서비스는 "다르다" 만 말하고,
 * 무엇이 맞는지는 사람이 정한다.
 */
class OpsConflictDetector
{
    /** 이만큼 넘게 뒤로 가면 확인한다. 1~2% 오차까지 붙잡으면 잔소리가 된다. */
    private const PROGRESS_DROP_TOLERANCE = 5;

    /** 납기가 이만큼 넘게 움직이면 확인한다(일). */
    private const ETA_SHIFT_DAYS = 21;

    /**
     * 제안이 이미 기록된 값과 어긋나는지 본다.
     *
     * @param  array<string, mixed>  $proposed
     * @return array{with: string, expected: string, heard: string, note: string}|null
     */
    public function check(string $targetType, string $targetCode, array $proposed, ?int $siteId = null): ?array
    {
        if ($targetCode === '' || $proposed === []) {
            return null;
        }

        return match ($targetType) {
            'wbs' => $this->checkWbs($targetCode, $proposed, $siteId),
            'procurement' => $this->checkProcurement($targetCode, $proposed, $siteId),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array{with: string, expected: string, heard: string, note: string}|null
     */
    private function checkWbs(string $code, array $proposed, ?int $siteId): ?array
    {
        $item = WbsItem::query()
            ->where('wbs_code', $code)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->first();

        if (! $item) {
            return null;
        }

        // 진행률이 뒤로 간다.
        if (isset($proposed['progress']) && is_numeric($proposed['progress'])) {
            $now = (int) $item->progress;
            $said = (int) $proposed['progress'];

            if ($now - $said > self::PROGRESS_DROP_TOLERANCE) {
                return [
                    'with' => "{$code} {$item->name}",
                    'expected' => "진행률 {$now}%",
                    'heard' => "진행률 {$said}%",
                    'note' => '기록보다 낮게 보고됐습니다. 앞 보고가 잘못됐거나 재시공이 있었는지 확인이 필요합니다.',
                ];
            }
        }

        // 끝난 작업이 다시 진행중으로 돌아간다.
        if (isset($proposed['status'])
            && (string) $item->status === WbsItem::STATUS_DONE
            && (string) $proposed['status'] !== WbsItem::STATUS_DONE) {
            return [
                'with' => "{$code} {$item->name}",
                'expected' => '완료',
                'heard' => (string) $proposed['status'],
                'note' => '완료된 작업이 다시 진행중으로 바뀝니다. 하자·재시공인지 확인이 필요합니다.',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array{with: string, expected: string, heard: string, note: string}|null
     */
    private function checkProcurement(string $poNo, array $proposed, ?int $siteId): ?array
    {
        if (blank($proposed['eta'] ?? null)) {
            return null;
        }

        $item = ProcurementItem::query()
            ->where('po_no', $poNo)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->first();

        if (! $item || ! $item->eta) {
            return null;
        }

        try {
            $said = \Illuminate\Support\Carbon::parse((string) $proposed['eta']);
        } catch (\Throwable) {
            return null;
        }

        $shift = (int) $item->eta->diffInDays($said, false);

        if (abs($shift) < self::ETA_SHIFT_DAYS) {
            return null;
        }

        return [
            'with' => "발주 {$poNo} ".($item->vendor ?: ''),
            'expected' => '도착예정 '.$item->eta->toDateString(),
            'heard' => '도착예정 '.$said->toDateString(),
            'note' => $shift > 0
                ? '납기가 크게 밀립니다. 후속 공정 일정에 영향이 있는지 확인이 필요합니다.'
                : '납기가 크게 당겨집니다. 출고일과 도착일을 혼동한 것은 아닌지 확인이 필요합니다.',
        ];
    }

    /**
     * 방에 띄울 한 문장. 무엇과 무엇이 다른지 근거를 그대로 보여 주고 묻는다 —
     * 근거 없는 지적은 사람을 설득하지 못하고 잔소리로만 남는다.
     *
     * @param  array{with: string, expected: string, heard: string, note: string}  $conflict
     */
    public function question(array $conflict): string
    {
        return sprintf(
            '⚠️ %s 은(는) 기록상 %s 인데 %s 로 말씀하셨습니다. 바뀐 것이 맞나요?',
            $conflict['with'],
            $conflict['expected'],
            $conflict['heard'],
        );
    }
}
