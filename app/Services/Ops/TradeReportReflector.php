<?php

namespace App\Services\Ops;

use App\Models\DailyTradeReport;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Alerts\UnifiedAlertService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 제출 = 반영. 반장이 「오늘 보고 제출」을 누르면 그 보고의 내용이 ERP 로 넘어간다.
 *
 * ── 왜 필요한가 ──────────────────────────────────────────────────────────
 * 지금까지 반장이 올린 글·사진은 AI 가 판독해 «제안» 까지만 만들어 두고, 그것을
 * 공정표·조달·검사 대장에 옮기는 일은 사람이 PC 상황실에서 버튼을 눌러야 했다.
 * 현장에서 일어난 사실이 ERP 화면에 뜨기까지 <b>한 사람의 손</b>이 더 필요했고,
 * 그 손이 바쁜 날에는 그날의 진척이 공정표에 없는 채로 마감이 돌았다.
 *
 * ── 무엇을 자동으로 하고, 무엇을 하지 않는가 ─────────────────────────────
 * 자동으로 하는 것은 <b>되돌릴 수 있고, 틀려도 회복되는</b> 것뿐이다.
 *
 *   자동  공정 진척률 / 자재 도착예정·발주상태 / 검사 계획일
 *   수동  지출(돈) — 되돌려도 나간 돈은 돌아오지 않는다
 *   수동  제출물의 «제출»·«승인» — 서류가 있어야 성립하는 준수 기록이다
 *   수동  공정 «완료» — 실적일이 찍히고 CPM 이 그 행을 고정한다. 되돌려도 원상복구되지 않는다
 *   수동  작업 계획일(planned_start/end) — 후속 공정이 통째로 밀린다. 일정은 소장의 몫
 *
 * ── 절대 규칙 ────────────────────────────────────────────────────────────
 * 1. <b>상태를 함부로 낮추지 않는다.</b> 반영하지 못한 항목은 그냥 그대로 둔다
 *    (이미 «확인 대기» 로 상황실 목록에 떠 있다). 이 클래스가 새로 알아낸 것
 *    — 같은 대상에 어긋난 보고가 둘이거나, 진척률이 뒤로 가는 것 — 만 되물음으로 올린다.
 * 2. <b>반영 실패가 제출을 막지 않는다.</b> 반장은 제출을 끝냈다. 뒤에서 무엇이
 *    실패하든 그 사실은 변하지 않는다.
 * 3. <b>업무 규칙을 우회하지 않는다.</b> 반영은 기존 apply 경로를 그대로 탄다 —
 *    TBM 게이트·홀드포인트에 걸리면 걸린 채로 남는다.
 * 4. <b>사람이 봐야 할 것은 묻어 두지 않는다.</b> 못 넘긴 것이 있으면 그 현장의
 *    그날 알림 한 건으로 모아 올린다(공종마다 울리면 아무도 안 본다).
 */
class TradeReportReflector
{
    /**
     * 자동 반영에 요구하는 확신도.
     *
     * 판독 기준(OpsIntakeService::LOW_CONFIDENCE = 60)보다 높다. 사람이 목록을 보고
     * 누를 때는 눈이 한 번 더 걸러 주지만, 여기서는 아무도 안 본다. 아무도 안 보는
     * 길은 더 좁아야 한다.
     */
    public const AUTO_CONFIDENCE = 75;

    /** 제출과 동시에 넘어가는 분류. 여기 없는 것은 사람이 누른다. */
    private const AUTO_CATEGORIES = ['progress', 'procurement', 'inspection', 'submittal'];

    /**
     * 제출물 상태 가운데 사무관리자의 말만으로 넘어가는 것 — «우리가 한 일».
     *
     * 냈다·다시 냈다·쓰고 있다는 우리 쪽 행위라 그 사람이 1차 사실이다. 승인·조건부승인·
     * 반려는 <b>상대의 회신</b>이고 회신 문서가 정본이다 — 말만 듣고 «승인» 으로 넘기면
     * 그 뒤의 발주가 승인 없이 나간다. 그것은 사람이 문서를 보고 누른다.
     */
    private const AUTO_SUBMITTAL_STATUSES = ['작성중', '제출', '재제출'];

    /**
     * 분류별로 자동 반영을 허용하는 필드.
     *
     * 허용 목록에 없는 필드는 제안에 들어 있어도 조용히 뺀다.
     *
     *   progress.status — <b>일부러 뺐다.</b> 공정의 상태는 진척률보다 훨씬 센 레버다.
     *     '완료' 로 넘어가면 진척률이 100 으로 동기화되고, 실적 시작·종료일이 오늘로
     *     찍히고(되돌려도 그 날짜는 남는다), CPM 이 그 행을 고정해 선행이 밀려도
     *     후속이 안 움직인다 — 지연이 화면에서 사라진다. 게다가 AI 가 '마감' 처럼
     *     정규값이 아닌 말을 내면 진척률이 0 으로 초기화된다(WBS 는 상태 화이트리스트가
     *     없다). 완료 처리는 TBM 서명과 같은 종류의 «사람이 책임지는 선언» 이다.
     *   procurement.amount/vendor/po_no — 돈과 계약 상대. 사람이 확인한다.
     *   inspection.status/submitted_on/approved_on — 준수 기록. 서류가 정본이다.
     *   submittal.approved_on — 승인은 회신 문서로 확인한다(AUTO_SUBMITTAL_STATUSES).
     *     submitted_on 과 «제출» 상태는 우리가 한 일이라 낸 사람의 말이 1차 사실이다.
     */
    private const AUTO_FIELDS = [
        'progress' => ['progress'],
        'procurement' => ['eta', 'status', 'ordered_on'],
        'inspection' => ['planned_on', 'notes'],
        'submittal' => ['status', 'submitted_on', 'notes'],
    ];

    /** 이 클래스가 스스로 올린 되물음임을 알아보는 표시 — 되돌리기 때 이것만 푼다. */
    private const HOLD_MARK = '[자동보류] ';

    public function __construct(
        private readonly OpsIntakeService $intake,
    ) {}

    /**
     * 그 보고에 담긴 것을 ERP 로 넘긴다.
     *
     * 제출 직후에 한 번, 그리고 <b>제출 뒤에 판독이 끝난 사진</b>이 있을 때 다시 불린다
     * (사진 판독은 몇 분씩 걸려서, 제출 시점에는 아직 읽히지 않은 것이 있을 수 있다).
     * 두 번 불려도 이미 반영된 것은 건드리지 않으므로 중복 반영이 없다.
     *
     * @return array<string, mixed>
     */
    public function reflect(DailyTradeReport $report, ?User $actor = null): array
    {
        // 되돌린 보고는 반영하지 않는다.
        //
        // 제출 직후에 뜬 잡이 도는 사이에 소장이 「되돌리기」를 누르면, 확인하지
        // 않는 한 잡은 그대로 공정표에 값을 쓴다 — 소장이 "아직 못 받았다" 고
        // 되돌린 보고의 내용이 이미 ERP 에 들어가 있게 된다.
        if (! $report->isSubmitted()) {
            return ['applied' => 0, 'held' => 0, 'skipped' => 'not-submitted', 'note' => null];
        }

        // 한 번에 하나만 돈다. 제출 잡과, 사진 판독이 끝날 때마다 붙는 이어달리기가
        // 겹치면 같은 항목을 둘이 집어 되돌리기 근거(previous)가 오염된다.
        $lock = Cache::lock('trade-report-reflect:'.$report->id, 300);
        if (! $lock->get()) {
            return ['applied' => 0, 'held' => 0, 'skipped' => 'busy', 'note' => null];
        }

        try {
            return $this->run($report->refresh(), $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * 되돌리기 뒤에 자동 보류를 푼다 — 다음 제출에서 다시 시도할 수 있게.
     *
     * 이 클래스가 올린 되물음만 푼다. AI 판독이 원래부터 되물음으로 만든 것(확신
     * 부족·어긋남)은 그대로 둔다 — 그건 반장이 아니라 사람이 답할 질문이다.
     */
    public function releaseHolds(DailyTradeReport $report): int
    {
        $batchIds = $report->batches()->pluck('id');
        if ($batchIds->isEmpty()) {
            return 0;
        }

        return OpsIntakeItem::query()
            ->whereIn('ops_intake_batch_id', $batchIds)
            ->where('status', 'needs_input')
            ->where('result_note', 'like', self::HOLD_MARK.'%')
            ->update(['status' => 'pending', 'question' => null, 'result_note' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(DailyTradeReport $report, ?User $actor): array
    {
        $actor ??= $report->submittedBy;

        // 아직 읽히지 않은 사진이 있으면 <b>아무것도 반영하지 않는다.</b>
        //
        // 같은 공종에 반장이 둘이고 한 사람은 글, 한 사람은 사진을 올렸다고 하자.
        // 글은 즉시 읽히고 사진은 3분 뒤에 읽힌다. 지금 반영해 버리면 나중에 읽힌
        // 쪽은 비교할 상대가 없어(앞 항목은 이미 applied 라 후보에서 빠진다) 어긋난
        // 값이 조용히 덮어쓴다 — 그날의 진척이 판독 순서에 좌우된다. 다 읽힐 때까지
        // 기다렸다가 한 번에 보면 «같은 대상에 두 말» 을 제대로 잡을 수 있다.
        // (마지막 판독이 끝나는 순간 analyze() 가 여기를 다시 부른다.)
        if ($this->analyzingBatches($report) > 0) {
            $this->stamp($report);

            return ['applied' => 0, 'held' => 0, 'waiting' => true, 'note' => (string) $report->refresh()->reflection_note];
        }

        $items = $this->pendingItems($report);
        $candidates = [];
        $held = [];

        foreach ($items as $item) {
            $reason = $this->blocker($item);
            if ($reason !== null) {
                $held[] = ['item' => $item, 'reason' => $reason, 'ask' => false];

                continue;
            }
            $candidates[] = $item;
        }

        // 같은 대상을 서로 다르게 말한 보고는 어느 쪽도 반영하지 않는다. 같은 공종에
        // 반장이 둘이면 실제로 일어나는 일이고, 늦게 올린 쪽이 이기게 두면 그날의
        // 진척이 올린 순서에 좌우된다.
        [$candidates, $clashes] = $this->splitClashes($candidates);
        foreach ($clashes as $clash) {
            $held[] = $clash;
        }

        $applied = [];
        $failed = [];

        foreach ($candidates as $item) {
            $patch = $this->allowedPatch($item);

            // 진척률이 뒤로 가는 보고는 자동으로 덮지 않는다. 앞 보고가 부풀려졌는지
            // 재시공이 있었는지는 사람만 안다 — 조용히 덮으면 한 일이 기록에서 사라진다.
            if ($back = $this->progressGoesBack($item, $patch)) {
                $held[] = ['item' => $item, 'reason' => $back, 'ask' => true];

                continue;
            }

            try {
                $res = $this->intake->apply($item->id, $patch, $actor?->id, OpsIntakeItem::VIA_REPORT);
            } catch (\Throwable $e) {
                report($e);
                $res = ['success' => false, 'error' => $e->getMessage()];
            }

            if ($res['success'] ?? false) {
                $applied[] = $item;

                continue;
            }

            // 게이트에 걸렸거나 대상이 사라진 것. 상태는 그대로 두고(=확인 대기로 남는다)
            // 왜 못 넘어갔는지만 적는다.
            $failed[] = ['item' => $item, 'error' => (string) ($res['error'] ?? '반영 실패')];
            $item->forceFill(['result_note' => mb_substr('자동 반영 보류 — '.($res['error'] ?? ''), 0, 300)])->save();
        }

        foreach ($held as $h) {
            $this->markHeld($h['item'], $h['reason'], (bool) ($h['ask'] ?? false));
        }

        $this->stamp($report);
        $this->raise($report);

        return [
            'applied' => count($applied),
            'held' => count($held) + count($failed),
            'note' => (string) $report->refresh()->reflection_note,
        ];
    }

    /**
     * 반영은 하지 않고 결과 칸만 다시 센다.
     *
     * 사진 판독이 <b>실패</b>했을 때 쓴다. 그때는 반영할 것이 새로 생기지 않지만,
     * 보고에는 「사진 판독 중 — 끝나면 이어서 반영됩니다」가 적혀 있다. 다시 세지
     * 않으면 그 문장이 영원히 남아 반장에게 오지 않을 것을 기다리게 만든다.
     */
    public function restamp(DailyTradeReport $report): void
    {
        $this->stamp($report);
        $this->raise($report);
    }

    /**
     * 이 보고에서 아직 처리되지 않은 판독 항목.
     *
     * @return Collection<int, OpsIntakeItem>
     */
    private function pendingItems(DailyTradeReport $report): Collection
    {
        $batchIds = $report->batches()->pluck('id');
        if ($batchIds->isEmpty()) {
            return collect();
        }

        return OpsIntakeItem::query()
            ->whereIn('ops_intake_batch_id', $batchIds)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();
    }

    /**
     * 자동으로 넘길 수 없는 까닭. null 이면 넘길 수 있다.
     *
     * 여기서 걸린 항목은 <b>상태를 바꾸지 않는다</b>. 이미 «확인 대기» 이고, 사람이
     * 상황실에서 누르면 그대로 반영된다. 잘못된 것이 아니라 아직 사람 차례일 뿐이다.
     */
    private function blocker(OpsIntakeItem $item): ?string
    {
        if ($item->category === 'expense' || $item->category === 'billing') {
            return '금액이 걸린 항목이라 확인 뒤에 반영합니다.';
        }

        if (! in_array($item->category, self::AUTO_CATEGORIES, true)) {
            return $item->categoryLabel().' 은(는) 확인 뒤에 반영합니다.';
        }

        if (blank($item->target_code)) {
            return '어느 작업·발주·검사·제출물인지 특정되지 않았습니다.';
        }

        // 승인·반려는 상대의 회신이다 — 말이 아니라 회신 문서를 보고 사람이 누른다.
        if ($item->category === 'submittal') {
            $status = (string) (((array) ($item->proposed ?? []))['status'] ?? '');
            if ($status !== '' && ! in_array($status, self::AUTO_SUBMITTAL_STATUSES, true)) {
                return sprintf('«%s» 은(는) 회신 문서를 확인한 뒤에 반영합니다.', $status);
            }
        }

        if (! empty($item->conflict)) {
            return '기록과 어긋나는 내용이라 확인이 필요합니다.';
        }

        if (filled($item->question)) {
            return '되물을 것이 남아 있습니다.';
        }

        if ((int) $item->confidence < self::AUTO_CONFIDENCE) {
            return sprintf('AI 확신이 낮습니다(%d%%). 값을 확인해 주세요.', (int) $item->confidence);
        }

        if ($this->allowedPatch($item) === []) {
            return '자동으로 반영할 수 있는 값이 없습니다.';
        }

        if (! $this->targetExists($item)) {
            return '반영할 대상을 이 현장에서 찾지 못했습니다: '.$item->target_code;
        }

        return null;
    }

    /**
     * 제안에서 자동 반영이 허용된 필드만 남긴다.
     *
     * @return array<string, mixed>
     */
    private function allowedPatch(OpsIntakeItem $item): array
    {
        $allowed = self::AUTO_FIELDS[$item->category] ?? [];
        $proposed = (array) ($item->proposed ?? []);

        $patch = [];
        foreach ($allowed as $field) {
            $value = $proposed[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $patch[$field] = $value;
        }

        return $patch;
    }

    /**
     * 대상이 <b>이 현장에</b> 실제로 있는가.
     *
     * 판독 단계에서 이미 현장별 후보 목록으로 걸렀지만, 그 사이에 지워졌을 수 있고
     * 발주번호(po_no)는 현장별로 유일하지 않다 — 없는 것을 향해 반영을 시도하면
     * 엉뚱한 오류만 남는다.
     */
    private function targetExists(OpsIntakeItem $item): bool
    {
        $code = (string) $item->target_code;
        $siteId = $item->site_id;

        // 「이 현장 것, 또는 현장이 안 붙은 본사 공통」 — 반영 경로와 같은 규칙으로
        // 본다. 여기만 더 좁게 보면 실제로는 반영되는 것을 "대상 없음" 이라며 막는다.
        $mine = fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')->orWhere('site_id', $siteId));

        return match ($item->target_type) {
            'procurement' => ProcurementItem::query()->where('po_no', $code)
                ->when($siteId, $mine)->exists(),
            'submittal' => Submittal::query()->where('seq', (int) $code)
                ->when($siteId, $mine)->exists(),
            default => WbsItem::query()->where('wbs_code', $code)
                ->when($siteId, $mine)->exists(),
        };
    }

    /**
     * 같은 대상·같은 칸을 서로 다른 값으로 말한 제안을 갈라낸다.
     *
     * @param  array<int, OpsIntakeItem>  $candidates
     * @return array{0: array<int, OpsIntakeItem>, 1: array<int, array<string, mixed>>}
     */
    private function splitClashes(array $candidates): array
    {
        /** @var array<string, array<int, array{item: OpsIntakeItem, value: mixed}>> $byField */
        $byField = [];

        foreach ($candidates as $item) {
            foreach ($this->allowedPatch($item) as $field => $value) {
                $key = $item->target_type.'|'.$item->target_code.'|'.$field;
                $byField[$key][] = ['item' => $item, 'value' => $value];
            }
        }

        $clashedIds = [];
        $clashes = [];

        foreach ($byField as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $values = array_map(fn (array $r): string => (string) $r['value'], $rows);
            if (count(array_unique($values)) < 2) {
                continue;   // 같은 말을 두 번 한 것뿐이다 — 어긋남이 아니다.
            }

            [, $code, $field] = explode('|', $key);
            $reason = sprintf(
                '같은 대상(%s %s)에 서로 다른 보고가 있습니다: %s. 어느 쪽이 맞는지 정해 주세요.',
                $code,
                $this->fieldLabel($field),
                implode(' / ', array_unique($values)),
            );

            foreach ($rows as $row) {
                $clashedIds[$row['item']->id] = true;
                $clashes[$row['item']->id] = ['item' => $row['item'], 'reason' => $reason, 'ask' => true];
            }
        }

        $kept = array_values(array_filter($candidates, fn (OpsIntakeItem $i): bool => ! isset($clashedIds[$i->id])));

        return [$kept, array_values($clashes)];
    }

    /**
     * 진척률이 기록보다 낮은가.
     *
     * 판독 단계의 어긋남 검사(OpsConflictDetector)는 5% 까지 봐준다 — 사람이 보는
     * 목록에서 1~2% 차이까지 붙잡으면 잔소리가 되기 때문이다. 여기서는 봐주지 않는다.
     * 아무도 안 보는 채로 기록된 진척을 지우는 일만은 없어야 한다.
     *
     * @param  array<string, mixed>  $patch
     */
    private function progressGoesBack(OpsIntakeItem $item, array $patch): ?string
    {
        if ($item->target_type === 'procurement' || $item->target_type === 'submittal') {
            return null;
        }
        if (! isset($patch['progress']) || ! is_numeric($patch['progress'])) {
            return null;
        }

        $wbs = WbsItem::query()
            ->where('wbs_code', (string) $item->target_code)
            ->when($item->site_id, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('site_id')->orWhere('site_id', $item->site_id),
            ))
            ->orderByRaw('case when site_id is null then 1 else 0 end')
            ->first();

        if (! $wbs) {
            return null;
        }

        $now = (int) $wbs->progress;
        $said = (int) $patch['progress'];

        if ($said >= $now) {
            return null;
        }

        return sprintf(
            '기록은 %d%% 인데 %d%% 로 보고됐습니다. 재시공인지, 앞 보고가 잘못됐는지 확인해 주세요.',
            $now,
            $said,
        );
    }

    /**
     * 못 넘긴 항목에 까닭을 적는다.
     *
     * $ask 가 참일 때만 «되물음» 으로 올린다 — 이 클래스가 새로 알아낸 것(어긋난 보고 둘,
     * 뒤로 가는 진척률)만 해당한다. 그 밖의 것은 원래 사람 차례라 상태를 건드리지 않는다.
     */
    private function markHeld(OpsIntakeItem $item, string $reason, bool $ask): void
    {
        $patch = ['result_note' => mb_substr(($ask ? self::HOLD_MARK : '').$reason, 0, 300)];

        if ($ask) {
            $patch['status'] = 'needs_input';
            $patch['question'] = mb_substr($item->question ?: $reason, 0, 300);
        }

        $item->forceFill($patch)->save();
    }

    /** 아직 판독 중인 배치 수 — 있으면 반영이 끝난 것이 아니다. */
    private function analyzingBatches(DailyTradeReport $report): int
    {
        return $report->batches()->where('status', 'analyzing')->count();
    }

    /**
     * 결과를 보고 한 장에 적는다. 적어 두지 않으면 반장은 반영됐는지 알 길이 없다.
     *
     * 세는 방법이 두 가지 점에서 까다롭다.
     *
     * 첫째, <b>반영 건수는 이 보고가 넘긴 것만</b> 센다. 인원 보고와 액션 아이템은
     * 판독 직후에 이미 자동으로 모듈에 들어가 있어(applied_via='auto'), 그것까지 세면
     * 반장은 「반영 1건」을 자기 진척 보고로 읽는다 — 실제로 넘어간 것은 인원수 한 줄이고
     * 그가 오늘 한 일은 공정표에 없는데도.
     *
     * 둘째, <b>확인 필요 건수는 사람이 손댈 수 있는 것만</b> 센다. 계획·이슈 같은 분류는
     * 애초에 반영 대상이 아니라 상황실에서 눌러도 «반영 대상이 지정되지 않았습니다» 로
     * 끝난다. 그것까지 세면 그 숫자는 구조적으로 절대 0 이 되지 않고, 매일 울리는 알림은
     * 곧 아무도 열지 않는 알림이 된다.
     */
    private function stamp(DailyTradeReport $report): void
    {
        $batchIds = $report->batches()->pluck('id');

        $base = fn () => OpsIntakeItem::query()->whereIn('ops_intake_batch_id', $batchIds);

        $applied = $batchIds->isEmpty() ? 0 : $base()
            ->where('status', 'applied')
            ->whereIn('applied_via', [OpsIntakeItem::VIA_REPORT, OpsIntakeItem::VIA_MANUAL])
            ->count();

        // 판독 직후 다른 길로 이미 들어간 것(인원·지시·준비물) — 반장에게는 따로 말한다.
        $routed = $batchIds->isEmpty() ? 0 : $base()
            ->where('status', 'applied')
            ->where(fn ($q) => $q->where('applied_via', OpsIntakeItem::VIA_AUTO)->orWhereNull('applied_via'))
            ->count();

        $held = $batchIds->isEmpty() ? 0 : $base()
            ->whereIn('status', ['pending', 'needs_input'])
            ->whereIn('category', self::AUTO_CATEGORIES)
            ->count();

        // 반영 대상은 아니지만 보고에 담긴 것 — 계획·이슈·지출. 숫자로만 알린다.
        $noted = $batchIds->isEmpty() ? 0 : $base()
            ->whereIn('status', ['pending', 'needs_input'])
            ->whereNotIn('category', array_merge(self::AUTO_CATEGORIES, ['noise']))
            ->count();

        $analyzing = $this->analyzingBatches($report);
        $failed = $batchIds->isEmpty() ? 0 : $report->batches()->where('status', 'failed')->count();

        $parts = [];
        $parts[] = $applied > 0 ? "공정·자재·서류 {$applied}건 반영" : '공정·자재·서류 반영 없음';
        if ($routed > 0) {
            $parts[] = "인원·지시 {$routed}건 기록";
        }
        if ($held > 0) {
            $parts[] = "확인 필요 {$held}건";
        }
        if ($noted > 0) {
            $parts[] = "참고 {$noted}건";
        }
        if ($analyzing > 0) {
            $parts[] = "사진 판독 중 {$analyzing}건 (끝나면 이어서 반영됩니다)";
        }
        if ($failed > 0) {
            // 「판독 중」과 「판독 실패」가 구별되지 않으면 반장은 오지 않을 것을 기다린다.
            $parts[] = "사진 판독 실패 {$failed}건 — 다시 올려 주세요";
        }

        $report->forceFill([
            'applied_count' => $applied,
            'held_count' => $held,
            'reflected_at' => now(),
            'reflection_note' => mb_substr(implode(' · ', $parts), 0, 300),
        ])->save();
    }

    /**
     * 사람이 봐야 할 것이 남았으면 그 현장의 그날 알림 한 건으로 모은다.
     *
     * 공종마다 알림을 울리면 저녁에 다섯 번 울리고, 다섯 번 울리는 알림은 곧
     * 아무도 안 보는 알림이 된다.
     */
    private function raise(DailyTradeReport $report): void
    {
        $date = $report->work_date->toDateString();
        $fingerprint = "trade-report-held:{$report->site_id}:{$date}";

        $reports = DailyTradeReport::query()
            ->where('site_id', $report->site_id)
            ->where('work_date', $date)
            ->get();

        $held = (int) $reports->sum('held_count');

        try {
            // 다 처리됐으면 그 사실도 알림의 일부다. 조용히 반환하면 이미 끝난 일로
            // 「5건이 확인을 기다립니다」가 미해결로 남아 다음 날 알림함을 더럽힌다.
            if ($held === 0) {
                $this->closeHeldAlert($fingerprint);

                return;
            }

            $trades = $reports->where('held_count', '>', 0)
                ->map(fn (DailyTradeReport $r): string => $r->trade.' '.$r->held_count.'건')
                ->values()->all();

            $site = Site::query()->find($report->site_id);

            app(UnifiedAlertService::class)->emit(
                $fingerprint,
                [
                    'company_id' => $site?->company_id,
                    'site_id' => $report->site_id,
                    'source_module' => 'OPS',
                    'source_type' => DailyTradeReport::class,
                    'source_id' => (string) $report->id,
                    'event_type' => 'trade_report_held',
                    'severity' => 'info',
                    // 하루에 한 줄로 모으기로 한 이상, 그 줄의 생애는 코드가 책임져야 한다.
                    // 오전에 처리하고 «완료» 로 닫은 알림에 오후 보류가 얹히면, 상태를
                    // 되살리지 않는 한 그 줄은 완료 목록 맨 아래에 앉아 아무도 안 본다.
                    'status' => 'unresolved',
                    'resolved_at' => null,
                    'title' => sprintf('%s 오늘 보고 중 %d건이 확인을 기다립니다 (%s)', $site?->code ?: '현장', $held, $date),
                    'content' => sprintf(
                        "%s\n\n반장이 올린 내용 가운데 자동으로 넘기지 않은 것들입니다. "
                        .'기록과 어긋나 되물어야 하는 것이거나, 확신이 낮아 값을 확인해야 하는 것입니다. '
                        .'상황실 「확인 대기」에서 값을 보고 반영하세요.',
                        implode(', ', $trades),
                    ),
                    'action_url' => '/?view=opsroom',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('보고 확인대기 알림 실패: '.$e->getMessage());
        }
    }

    /** 보류가 다 풀렸다 — 그 줄을 닫는다. */
    private function closeHeldAlert(string $fingerprint): void
    {
        UnifiedAlert::query()
            ->where('fingerprint', $fingerprint)
            ->where('status', 'unresolved')
            ->update(['status' => 'completed', 'resolved_at' => now()]);
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'progress' => '진척률',
            'status' => '상태',
            'eta' => '도착예정',
            'ordered_on' => '발주일',
            'planned_on' => '계획일',
            'submitted_on' => '제출일',
            'approved_on' => '승인일',
            'notes' => '메모',
            default => $field,
        };
    }
}
