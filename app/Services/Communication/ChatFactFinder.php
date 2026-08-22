<?php

namespace App\Services\Communication;

use App\Models\AttendanceLog;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Support\AccessPolicy;

/**
 * AI 가 대화방에서 질문을 받았을 때 <b>대신 조회해 주는</b> 자리.
 *
 * 대화 내용만 보고 답하는 도우미는 금방 바닥이 드러난다. "3층 배관 몇 %야?" 는
 * 방 안에 답이 없고 공정표에 있다. 그래서 질문에 맞는 모듈을 실제로 뒤져 사실을
 * 뽑아 준다.
 *
 * ── 지키는 것 ──────────────────────────────────────────────────────────
 *
 * <b>1. 질문한 사람의 권한을 넘지 않는다.</b> 이것이 이 파일의 존재 이유다.
 * 협력사 소장이 화면에서 못 보는 인건비를, 대화방에서 물었다고 AI 가 술술 답하면
 * 권한 설계 전체가 무의미해진다. 그래서 조회는 전부 AccessPolicy 를 거친다 —
 * 화면이 쓰는 것과 <b>같은</b> 규칙이다(규칙이 두 벌이 되면 언젠가 한쪽만 고쳐진다).
 *
 * <b>2. 막힌 것은 막혔다고 알린다.</b> 조용히 빼면 AI 는 "그런 자료 없습니다" 라고
 * 답하게 되고, 그건 거짓말이다. denied 목록에 담아 "권한이 없어 못 본다" 고
 * 말하게 한다.
 *
 * <b>3. 질문과 상관없는 것은 긁어 오지 않는다.</b> 매번 회사 전체를 실어 보내면
 * 요금이 대화량에 비례해 늘고, 정작 중요한 사실이 잡동사니에 묻힌다.
 */
class ChatFactFinder
{
    /** 어떤 낱말이 나오면 어느 모듈을 뒤질 것인가 — 라우팅 표 한 곳. */
    private const TOPICS = [
        'wbs' => ['공정', '진행', '진척', '일정', '스케줄', '공기', '언제 끝', '마감일', '작업 순서', 'wbs', 'schedule', 'progress'],
        'procurement' => ['자재', '발주', '납품', '납기', '입고', '구매', '주문', 'po', 'eta', 'material', 'delivery'],
        'equipment' => ['장비', '렌탈', '임대', '중장비', '크레인', '리프트', '지게차', 'equipment', 'rental'],
        'attendance' => ['출근', '퇴근', '출역', '인원', '몇 명', '몇명', '근태', '출퇴근', 'attendance', 'headcount'],
        'money' => ['비용', '경비', '영수증', '지출', '금액', '얼마', '예산', '급여', '인건비', '단가', '정산', 'cost', 'expense', 'payroll'],
        'documents' => ['문서', '도면', '계약서', '서류', '스펙', '사양', 'drawing', 'document', 'spec'],
    ];

    /** 한 종류당 몇 줄까지 실어 보낼 것인가. 길어지면 AI 가 핵심을 놓친다. */
    private const ROWS = 12;

    /**
     * 질문에 답하는 데 쓸 사실들.
     *
     * @return array{site: ?Site, facts: array<string, mixed>, denied: array<int, string>}
     */
    public function gather(string $question, CommunicationRoom $room, User $asker): array
    {
        $site = $this->siteFor($room, $asker);
        $topics = $this->topicsIn($question);

        $facts = [];
        $denied = [];

        if ($site) {
            $facts['현장'] = [
                '코드' => $site->code,
                '이름' => $site->name,
                '주소' => $site->address,
                '상태' => $site->status,
            ];
        }

        foreach ($topics as $topic) {
            match ($topic) {
                'wbs' => $facts['공정'] = $this->wbs($site, $asker),
                'procurement' => $facts['조달·발주'] = $this->procurement($site, $asker),
                'equipment' => $facts['장비'] = $this->equipment($site, $asker),
                'attendance' => $this->attendance($site, $asker, $facts, $denied),
                'money' => $this->money($site, $asker, $facts, $denied),
                'documents' => $facts['최근 문서'] = $this->documents($site, $asker),
                default => null,
            };
        }

        return [
            'site' => $site,
            'facts' => array_filter($facts, fn ($v): bool => $v !== [] && $v !== null),
            'denied' => $denied,
        ];
    }

    /** 질문에 걸리는 주제들. 아무것도 안 걸리면 아무것도 뒤지지 않는다. */
    public function topicsIn(string $question): array
    {
        $needle = mb_strtolower($question);
        $hits = [];

        foreach (self::TOPICS as $topic => $words) {
            foreach ($words as $word) {
                if (str_contains($needle, $word)) {
                    $hits[] = $topic;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * 어느 현장 이야기인가 — 방에 걸린 현장이 우선, 없으면 물어본 사람의 현장.
     *
     * 회사 울타리에 갇힌 사람(협력사 관리자)은 남의 회사 현장을 집어 오지 않는다.
     */
    private function siteFor(CommunicationRoom $room, User $asker): ?Site
    {
        $siteId = $room->site_id ?: $asker->employee?->site_id;

        if (! $siteId) {
            return null;
        }

        $query = Site::query()->whereKey($siteId);
        AccessPolicy::applyCompanyLock($query, $asker);

        return $query->first();
    }

    /** 공정 — 진행 중인 것과 늦은 것. 100% 끝난 줄까지 실어 보내지 않는다. */
    private function wbs(?Site $site, User $asker): array
    {
        if (! $site) {
            return [];
        }

        $query = WbsItem::query()->where('site_id', $site->id);
        AccessPolicy::applyCompanyLock($query, $asker);

        $items = (clone $query)
            ->where('progress', '<', 100)
            ->orderByDesc('is_critical')
            ->orderBy('planned_end')
            ->limit(self::ROWS)
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        return [
            '전체 항목' => (clone $query)->count(),
            '평균 진행률(%)' => (int) round((float) (clone $query)->avg('progress')),
            '진행 중' => $items->map(fn (WbsItem $w): array => array_filter([
                '코드' => $w->wbs_code,
                '이름' => $w->name,
                '진행률(%)' => (int) $w->progress,
                '계획 종료' => $w->planned_end?->toDateString(),
                '지연' => $w->planned_end && $w->planned_end->isPast() && $w->progress < 100 ? '예' : null,
                '주공정' => $w->is_critical ? '예' : null,
            ]))->all(),
        ];
    }

    /** 조달 — 아직 안 들어온 것과 납기가 지난 것. */
    private function procurement(?Site $site, User $asker): array
    {
        if (! $site) {
            return [];
        }

        $query = ProcurementItem::query()->where('site_id', $site->id);
        // 조달 행에는 회사 열이 없다 — 현장으로 이미 좁혀져 있고, 그 현장을 볼 수
        // 있는지는 siteFor() 에서 이미 걸렀다.

        $open = (clone $query)
            ->whereNotIn('status', ['received', 'cancelled'])
            ->orderBy('eta')
            ->limit(self::ROWS)
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        return [
            '미입고 건수' => (clone $query)->whereNotIn('status', ['received', 'cancelled'])->count(),
            '목록' => $open->map(fn (ProcurementItem $p): array => array_filter([
                '발주번호' => $p->po_no,
                '거래처' => $p->vendor,
                '상태' => $p->status,
                '납기' => $p->eta?->toDateString(),
                '납기 경과' => $p->eta && $p->eta->isPast() ? '예' : null,
                'WBS' => $p->wbs_code,
            ]))->all(),
        ];
    }

    /** 장비 — 이 현장에 지금 있는 것. */
    private function equipment(?Site $site, User $asker): array
    {
        if (! $site) {
            return [];
        }

        $query = Equipment::query()->where('site_id', $site->id);
        AccessPolicy::applyCompanyLock($query, $asker);

        $rows = (clone $query)->orderBy('equipment_type')->limit(self::ROWS)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return [
            '총 대수' => (clone $query)->count(),
            '목록' => $rows->map(fn (Equipment $e): array => array_filter([
                '코드' => $e->equipment_code,
                '종류' => $e->equipment_type,
                '모델' => $e->model,
                '상태' => $e->status,
                '반납 예정' => $e->rent_end,
            ]))->all(),
        ];
    }

    /**
     * 출퇴근 — 현장을 운영하는 사람만 명단을 본다.
     *
     * 그렇지 않은 사람에게는 <b>본인 기록</b>만 준다. 옆 사람이 몇 시에 왔는지는
     * 그 사람의 일이지 물어본 사람의 일이 아니다.
     */
    private function attendance(?Site $site, User $asker, array &$facts, array &$denied): void
    {
        if (! $site) {
            return;
        }

        $today = now()->toDateString();

        if (! AccessPolicy::canManageSite($asker)) {
            $denied[] = '현장 전체 출역 명단(현장 관리자만 볼 수 있습니다)';

            if ($asker->employee_id) {
                $mine = AttendanceLog::query()
                    ->where('employee_id', $asker->employee_id)
                    ->where('attendance_date', $today)
                    ->orderBy('event_at')
                    ->get(['event_type', 'event_at']);

                $facts['내 오늘 출퇴근'] = $mine->map(fn (AttendanceLog $l): array => [
                    '구분' => $l->event_type === 'clock_in' ? '출근' : '퇴근',
                    '시각' => $l->event_at?->format('H:i'),
                ])->all();
            }

            return;
        }

        $query = AttendanceLog::query()->where('site_id', $site->id)->where('attendance_date', $today);
        AccessPolicy::applyCompanyLock($query, $asker);

        $facts['오늘 출역'] = [
            '출근 인원' => (clone $query)->where('event_type', 'clock_in')->distinct('employee_id')->count('employee_id'),
            '퇴근 인원' => (clone $query)->where('event_type', 'clock_out')->distinct('employee_id')->count('employee_id'),
            '현장 등록 인원' => Employee::query()
                ->where('site_id', $site->id)
                ->where('employment_status', 'active')
                ->when(
                    AccessPolicy::lockedCompanyId($asker) !== null,
                    fn ($q) => AccessPolicy::applyCompanyLock($q, $asker),
                )
                ->count(),
        ];
    }

    /**
     * 돈 — 돈을 다루는 사람만.
     *
     * 여기서 막지 않으면 대화방이 재무 화면의 뒷문이 된다.
     */
    private function money(?Site $site, User $asker, array &$facts, array &$denied): void
    {
        if (! AccessPolicy::canManageMoney($asker)) {
            $denied[] = '비용·급여 금액(재무 권한이 있는 사람만 볼 수 있습니다)';

            return;
        }

        $query = MobileExpense::query();
        if ($site) {
            $query->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($query, $asker);

        $pending = (clone $query)->where('status', 'submitted');

        $facts['비용'] = array_filter([
            '승인 대기 건수' => $pending->count(),
            '승인 대기 합계' => (float) $pending->sum('amount'),
            '이번 달 승인 합계' => (float) (clone $query)
                ->whereIn('status', ['approved', 'paid'])
                ->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'),
        ], fn ($v): bool => $v !== 0 && $v !== 0.0);
    }

    /** 최근 문서 — AI 가 이미 읽어 둔 핵심 사실만. 원문을 다시 실어 보내지 않는다. */
    private function documents(?Site $site, User $asker): array
    {
        $query = IntelligentDocument::query()->latest('id')->limit(6);

        if ($site) {
            $query->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($query, $asker);

        return $query->get()->map(fn (IntelligentDocument $d): array => array_filter([
            '이름' => $d->title ?: $d->original_file_name,
            '종류' => $d->document_type,
            '문서일' => $d->document_date?->toDateString(),
            '요약' => filled($d->summary) ? mb_substr((string) $d->summary, 0, 200) : null,
        ]))->filter()->values()->all();
    }
}
