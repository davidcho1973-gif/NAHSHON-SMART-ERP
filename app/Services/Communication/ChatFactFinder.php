<?php

namespace App\Services\Communication;

use App\Models\AttendanceLog;
use App\Models\BoqItem;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Submittal;
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
        'money' => ['비용', '경비', '영수증', '지출', '금액', '얼마', '예산', '급여', '인건비', '정산', 'cost', 'expense', 'payroll'],
        'documents' => ['문서', '도면', '계약서', '서류', '스펙', '사양', 'drawing', 'document', 'spec'],
        'boq' => ['물량', '수량', '몇 개', '몇개', '몇 장', '몇장', '몇 본', '몇본', '단가', '산출', '내역', 'boq', 'quantity', 'takeoff'],
        'submittals' => ['제출물', '샵드로잉', '제작도', '컷시트', '배합설계', '승인', 'submittal', 'shop drawing'],
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

        // 지식 창고는 주제와 무관하게 항상 본다 — 축적된 지식은 어떤 질문에든
        // 걸릴 수 있고, 아무 주제에도 안 걸린 질문의 마지막 그물이기도 하다.
        $knowledge = app(\App\Services\Documents\KnowledgeKeeper::class)
            ->search($site, $asker, $this->searchTerms($question), $question);
        if ($knowledge !== []) {
            $facts['지식 창고(문서에서 축적)'] = $knowledge;
        }

        foreach ($topics as $topic) {
            match ($topic) {
                'wbs' => $facts['공정'] = $this->wbs($site, $asker),
                'procurement' => $facts['조달·발주'] = $this->procurement($site, $asker),
                'equipment' => $facts['장비'] = $this->equipment($site, $asker),
                'attendance' => $this->attendance($site, $asker, $facts, $denied),
                'money' => $this->money($site, $asker, $facts, $denied),
                'documents' => $facts['문서함'] = $this->documents($site, $asker, $question),
                'boq' => $facts['물량/BOQ'] = $this->boq($site, $asker, $question),
                'submittals' => $facts['제출물 대장'] = $this->submittals($site, $asker, $question),
                default => null,
            };
        }

        // 마지막 그물 — 아무 주제에도 안 걸린 질문("코어에 합판 써도 돼?")은
        // 문서 본문 검색이 받아낸다. 시방·계약 조항 질문은 낱말 표로 다 못 잡는다.
        if ($topics === [] && ! isset($facts['문서함'])) {
            $fallback = $this->documents($site, $asker, $question);
            if ($fallback !== []) {
                $facts['문서함'] = $fallback;
            }
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

    /**
     * 문서함 — 질문의 낱말로 문서를 <b>검색</b>해서, 걸린 문서는 본문 발췌까지 실어 보낸다.
     *
     * "계약서에 뭐라고 써 있어?" 는 요약 200자로는 답이 안 나온다. 제목·파일명·본문
     * 색인(search_text)을 뒤져 맞는 문서를 찾고, 낱말이 등장하는 자리 주변을 잘라
     * 보낸다. 아무것도 안 걸리면 예전처럼 최근 문서 목록으로 물러선다.
     */
    private function documents(?Site $site, User $asker, string $question): array
    {
        $query = IntelligentDocument::query();

        if ($site) {
            $query->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($query, $asker);

        $terms = $this->searchTerms($question);

        $meta = fn (IntelligentDocument $d): array => array_filter([
            '이름' => $d->title ?: $d->original_file_name,
            '종류' => $d->document_type,
            '문서일' => $d->document_date?->toDateString(),
            '요약' => filled($d->summary) ? mb_substr((string) $d->summary, 0, 200) : null,
        ]);

        if ($terms !== []) {
            $matched = (clone $query)
                ->where(function ($q) use ($terms): void {
                    foreach ($terms as $t) {
                        // ilike — 이 앱은 로컬·클라우드 모두 PostgreSQL 이다.
                        $q->orWhere('title', 'ilike', "%{$t}%")
                            ->orWhere('original_file_name', 'ilike', "%{$t}%")
                            ->orWhere('search_text', 'ilike', "%{$t}%");
                    }
                })
                ->latest('id')
                ->limit(4)
                ->get();

            if ($matched->isNotEmpty()) {
                return [
                    '검색어' => implode(', ', $terms),
                    '검색된 문서' => $matched->map(fn (IntelligentDocument $d): array => array_filter($meta($d) + [
                        '핵심 사실' => is_array($d->key_facts) ? array_slice($d->key_facts, 0, 5) : null,
                        '본문 발췌' => $this->excerpt((string) ($d->search_text ?: $d->extracted_text), $terms),
                    ]))->values()->all(),
                ];
            }
        }

        $recent = (clone $query)->latest('id')->limit(6)->get();

        return $recent->isEmpty() ? [] : [
            '안내' => $terms === [] ? null : '검색어와 일치하는 문서 없음 — 최근 문서만 제공',
            '최근 문서' => $recent->map($meta)->filter()->values()->all(),
        ];
    }

    /**
     * 물량/BOQ — 수량 질문의 정답지. 질문에 나온 품명으로 대장을 직접 검색한다.
     *
     * "석고 수량 파악해줘" 의 답은 대화가 아니라 437행짜리 물량 대장에 있다.
     */
    private function boq(?Site $site, User $asker, string $question): array
    {
        $query = BoqItem::query();
        if ($site) {
            $query->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($query, $asker);

        $total = (clone $query)->count();
        if ($total === 0) {
            return [];
        }

        $terms = $this->searchTerms($question);
        $rows = collect();

        if ($terms !== []) {
            $rows = (clone $query)
                ->where(function ($q) use ($terms): void {
                    foreach ($terms as $t) {
                        $q->orWhere('name_kr', 'ilike', "%{$t}%")
                            ->orWhere('name_en', 'ilike', "%{$t}%")
                            ->orWhere('spec', 'ilike', "%{$t}%");
                    }
                })
                ->orderBy('seq')
                ->limit(self::ROWS)
                ->get();
        }

        return array_filter([
            '전체 행수' => $total,
            '직접비 합계($)' => (float) (clone $query)->sum('amount'),
            '검색어' => $terms === [] ? null : implode(', ', $terms),
            '해당 품목' => $rows->isEmpty()
                ? ($terms === [] ? null : '일치하는 품목 없음 — 품명을 바꿔 다시 물어봐 달라고 답할 것')
                : $rows->map(fn (BoqItem $b): array => array_filter([
                    '공정' => $b->discipline,
                    '품명' => $b->name_kr,
                    '규격' => filled($b->spec) ? mb_substr((string) $b->spec, 0, 120) : null,
                    '수량' => rtrim(rtrim((string) $b->qty, '0'), '.').' '.$b->unit,
                    '수량 근거' => $b->qty_basis,
                    '단가($)' => (float) $b->unit_price,
                    '금액($)' => (float) $b->amount,
                    '출처 도면' => $b->source,
                    '검토 필요' => $b->flagged ? '예(단가 편차)' : null,
                ]))->values()->all(),
        ], fn ($v): bool => $v !== null);
    }

    /** 제출물 대장 — 상태 집계와, 질문에 걸린 항목. 게이트(정지 조항)는 항상 눈에 띄게. */
    private function submittals(?Site $site, User $asker, string $question): array
    {
        $query = Submittal::query();
        if ($site) {
            $query->where('site_id', $site->id);
        }
        AccessPolicy::applyCompanyLock($query, $asker);

        $total = (clone $query)->count();
        if ($total === 0) {
            return [];
        }

        $byStatus = (clone $query)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $terms = $this->searchTerms($question);
        $rows = collect();

        if ($terms !== []) {
            $rows = (clone $query)
                ->where(function ($q) use ($terms): void {
                    foreach ($terms as $t) {
                        $q->orWhere('title', 'ilike', "%{$t}%")
                            ->orWhere('section', 'ilike', "%{$t}%")
                            ->orWhere('csi', 'ilike', "%{$t}%");
                    }
                })
                ->orderByDesc('gate')
                ->orderBy('seq')
                ->limit(self::ROWS)
                ->get();
        }

        return array_filter([
            '전체 건수' => $total,
            '상태별' => $byStatus,
            '정지 게이트(★) 건수' => (clone $query)->where('gate', true)->count(),
            '검색어' => $terms === [] ? null : implode(', ', $terms),
            '해당 항목' => $rows->isEmpty() ? null : $rows->map(fn (Submittal $s): array => array_filter([
                '번호' => $s->seq,
                '공종' => trim($s->csi.' '.$s->section),
                '구분' => $s->category,
                '제목' => mb_substr((string) $s->title, 0, 160),
                '상태' => $s->status,
                '게이트' => $s->gate ? '★ 정지 조항(승인 전 발주·시공 금지 등)' : null,
                '담당' => $s->assignee,
                '제출 예정' => $s->planned_on?->toDateString(),
            ]))->values()->all(),
        ], fn ($v): bool => $v !== null);
    }

    /**
     * 질문에서 검색할 낱말을 고른다 — 멘션·요청 상투어를 걷어내고 남는 명사들.
     *
     * "석고 수량 파악해줘" → [석고]. 조사가 붙은 낱말(석고보드의)은 자른 변형도
     * 함께 넣는다 — OR 검색이라 후보가 늘어도 해가 없다.
     */
    private function searchTerms(string $question): array
    {
        $q = preg_replace('/@\s*(ai|에이아이)/iu', ' ', mb_strtolower($question)) ?? $question;

        $stop = [
            '물량', '수량', '몇개', '개수', '단가', '산출', '내역', 'boq', '제출물', '승인', '문서', '도면', '서류',
            '파악', '알려줘', '알려주세요', '알려', '확인', '해줘', '해주세요', '해봐', '좀', '지금', '현재',
            '현황', '상태', '목록', '리스트', '전체', '검색', '찾아줘', '찾아', '얼마나', '얼마', '어디', '뭐야',
            '무엇', '어떻게', '있어', '있나', '필요', 'the', 'of', 'is', 'what',
        ];

        $tokens = preg_split('/[^\p{L}\p{N}"×\.\-\/]+/u', $q) ?: [];
        $terms = [];

        foreach ($tokens as $t) {
            $t = trim($t, '-./"');
            if (mb_strlen($t) < 2 || in_array($t, $stop, true)) {
                continue;
            }
            $terms[] = $t;

            // 조사 하나 떼어 본 변형 — 3자 이상일 때만 (2자를 자르면 낱말이 사라진다).
            $last = mb_substr($t, -1);
            if (mb_strlen($t) >= 3 && in_array($last, ['은', '는', '이', '가', '을', '를', '의', '도', '만', '에'], true)) {
                $terms[] = mb_substr($t, 0, mb_strlen($t) - 1);
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 5);
    }

    /** 본문에서 낱말이 처음 나오는 자리의 앞뒤를 잘라 온다 — 원문 전체는 싣지 않는다. */
    private function excerpt(string $text, array $terms): ?string
    {
        if ($text === '') {
            return null;
        }

        $lower = mb_strtolower($text);
        foreach ($terms as $t) {
            $pos = mb_strpos($lower, mb_strtolower($t));
            if ($pos !== false) {
                $start = max(0, $pos - 200);
                $slice = mb_substr($text, $start, 500);

                return ($start > 0 ? '…' : '').trim(preg_replace('/\s+/u', ' ', $slice) ?? $slice).'…';
            }
        }

        return null;
    }
}
