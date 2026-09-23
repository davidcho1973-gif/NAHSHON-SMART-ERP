<?php

namespace App\Services\Wbs;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Models\WeekBoardLine;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;
use App\Support\ReportSlot;
use App\Support\SiteClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 이번 주 작업판 — 공종별로, 현장의 말로, 한 주.
 *
 * ── 무엇을 대신하는가 ─────────────────────────────────────────────────
 * 「오늘 할 일」 은 정식 공정표에서 <b>계획 날짜가 오늘에 걸린 액티비티</b>를 골랐다.
 * 그러면 화면은 «계획표가 상상한 오늘» 을 보여 주고, 현장이 하루 밀리는 순간부터
 * 매일 어긋난다. 목록이 안 맞으니 아무도 상태를 안 누르고, 안 누르니 날짜가 안 움직인다.
 *
 * 작업판은 반대로 간다. <b>사람이 월요일에 적는 것이 이번 주의 사실</b>이고, 화면은
 * 그것을 보여 줄 뿐이다. 계획표는 원청 보고용으로 뒤에 남는다.
 *
 * ── 공종은 일일보고와 같은 낱말 ───────────────────────────────────────
 * 공종 이름은 직원의 공종(employees.role)이다 — 공종별 일일보고가 쓰는 바로 그
 * 어휘(ReportSlot). 여기서 다른 이름을 쓰기 시작하면 「전기」 와 「전기/배관」 이
 * 두 공종이 되어, 어느 쪽이 맞는지 아무도 모른다.
 */
class WeekBoardService
{
    /**
     * 한 주의 작업판 — 공종별로 묶어서.
     *
     * @return array<string, mixed>
     */
    public function board(string $siteId = 'ALL', ?string $week = null): array
    {
        $user = auth()->user();
        if (! $this->canView($user)) {
            return ['success' => false, 'error' => '작업판을 볼 권한이 없습니다.'];
        }

        $site = $this->site($siteId, $user);
        if ($site === null) {
            return ['success' => true, 'noSite' => true, 'sites' => $this->siteOptions($user), 'groups' => [],
                'canManage' => $this->canManage($user)];
        }

        $weekStart = $this->weekStart($site, $week);
        $today = SiteClock::show($site, Carbon::now(), 'Y-m-d');

        $lines = WeekBoardLine::query()
            ->where('site_id', $site->id)
            ->whereDate('week_start', $weekStart)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $present = $this->presentByTrade($site, $today);
        $trades = $this->tradeOrder($site, $lines);

        $groups = [];
        foreach ($trades as $trade) {
            $mine = $lines->where('trade', $trade)->values();
            $groups[] = [
                'trade' => $trade,
                'presentToday' => $present[$trade] ?? 0,
                'plannedHeadcount' => round((float) $mine->sum(fn (WeekBoardLine $l) => (float) ($l->headcount ?? 0)), 1),
                'lines' => $mine->map(fn (WeekBoardLine $l) => $this->row($l))->all(),
            ];
        }

        // 지난주에 못 끝낸 것 — 이월 버튼이 무엇을 가져올지 미리 보여 준다.
        $lastWeek = Carbon::parse($weekStart)->subWeek()->toDateString();
        $leftover = WeekBoardLine::query()
            ->where('site_id', $site->id)
            ->whereDate('week_start', $lastWeek)
            ->where('status', '!=', WeekBoardLine::STATUS_DONE)
            ->count();

        return [
            'success' => true,
            'noSite' => false,
            'siteId' => $site->id,
            'site' => $site->code.' — '.$site->name,
            'sites' => $this->siteOptions($user),
            'weekStart' => $weekStart,
            'weekEnd' => Carbon::parse($weekStart)->addDays(6)->toDateString(),
            'isThisWeek' => $weekStart === $this->weekStart($site, null),
            'today' => $today,
            'groups' => $groups,
            'total' => $lines->count(),
            'doneCount' => $lines->where('status', WeekBoardLine::STATUS_DONE)->count(),
            'blockedCount' => $lines->where('status', WeekBoardLine::STATUS_BLOCKED)->count(),
            'leftoverCount' => $leftover,
            'lastWeek' => $lastWeek,
            'tradeOptions' => $this->tradeOptions($site, $lines),
            'statuses' => WeekBoardLine::STATUSES,
            'canManage' => $this->canManage($user),
        ];
    }

    /**
     * 줄 하나를 적거나 고친다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input, string $siteId = 'ALL'): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '작업판을 적을 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $line = $id > 0 ? WeekBoardLine::query()->visibleTo($user)->whereKey($id)->first() : new WeekBoardLine;
        if ($line === null) {
            return ['success' => false, 'error' => '줄을 찾을 수 없습니다.'];
        }

        $site = $line->exists
            ? Site::query()->find($line->site_id)
            : $this->site((string) ($input['siteId'] ?? $siteId), $user);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 먼저 고르세요.'];
        }

        $trade = trim((string) ($input['trade'] ?? ''));
        $task = trim((string) ($input['task'] ?? ''));
        $errors = [];
        if ($trade === '') {
            $errors['trade'] = '공종을 고르세요.';
        }
        if ($task === '') {
            $errors['task'] = '하는 일을 적으세요.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $status = (string) ($input['status'] ?? ($line->status ?: WeekBoardLine::STATUS_PLANNED));
        if (! array_key_exists($status, WeekBoardLine::STATUSES)) {
            $status = WeekBoardLine::STATUS_PLANNED;
        }

        $line->fill([
            'company_id' => $site->company_id,
            'site_id' => $site->id,
            'week_start' => $line->exists ? $line->week_start : $this->weekStart($site, $input['week'] ?? null),
            'trade' => mb_substr($trade, 0, 60),
            'task' => mb_substr($task, 0, 255),
            'headcount' => is_numeric($input['headcount'] ?? null) ? (float) $input['headcount'] : null,
            'note' => $this->text($input['note'] ?? null),
            'wbs_codes' => $this->codes($input['wbsCodes'] ?? null),
            'updated_by_id' => $user?->id,
        ]);
        if (! $line->exists) {
            $line->created_by_id = $user?->id;
            $line->sort_order = (int) WeekBoardLine::query()
                ->where('site_id', $site->id)->whereDate('week_start', $line->week_start)->max('sort_order') + 1;
        }
        $line->save();

        $warning = $status !== $line->status ? $this->applyStatus($line, $status, $this->text($input['reason'] ?? null), $user?->id) : null;

        return ['success' => true, 'id' => $line->id, 'weekStart' => $line->week_start->toDateString(), 'warning' => $warning];
    }

    /**
     * 됐다 / 안 됐다 — 한 번 누르는 것.
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, string $status, ?string $reason = null): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '작업판을 적을 권한이 없습니다.'];
        }
        if (! array_key_exists($status, WeekBoardLine::STATUSES)) {
            return ['success' => false, 'error' => '모르는 상태입니다.'];
        }

        $line = WeekBoardLine::query()->visibleTo($user)->whereKey($id)->first();
        if (! $line) {
            return ['success' => false, 'error' => '줄을 찾을 수 없습니다.'];
        }

        $warning = $this->applyStatus($line, $status, $this->text($reason), $user?->id);

        return ['success' => true, 'id' => $line->id, 'status' => $line->status, 'warning' => $warning];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '작업판을 적을 권한이 없습니다.'];
        }

        $line = WeekBoardLine::query()->visibleTo($user)->whereKey($id)->first();
        if (! $line) {
            return ['success' => false, 'error' => '줄을 찾을 수 없습니다.'];
        }

        $line->delete();

        return ['success' => true];
    }

    /**
     * 지난주에 못 끝낸 줄을 이번 주로 넘긴다.
     *
     * 넘어온 줄은 어디서 왔는지 기억한다(carried_from_id). 같은 일이 세 주째 넘어오면
     * 그 줄이 아니라 그 일이 문제이고, 그걸 보이게 하는 것이 이 표의 일이다.
     *
     * @return array<string, mixed>
     */
    public function carryOver(string $siteId = 'ALL', ?string $toWeek = null): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '작업판을 적을 권한이 없습니다.'];
        }

        $site = $this->site($siteId, $user);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 먼저 고르세요.'];
        }

        $to = $this->weekStart($site, $toWeek);
        $from = Carbon::parse($to)->subWeek()->toDateString();

        $existing = WeekBoardLine::query()
            ->where('site_id', $site->id)->whereDate('week_start', $to)
            ->get()
            ->map(fn (WeekBoardLine $l): string => $l->trade.'|'.mb_strtolower($l->task))
            ->all();

        $sort = (int) WeekBoardLine::query()->where('site_id', $site->id)->whereDate('week_start', $to)->max('sort_order');
        $moved = 0;

        $leftovers = WeekBoardLine::query()
            ->where('site_id', $site->id)->whereDate('week_start', $from)
            ->where('status', '!=', WeekBoardLine::STATUS_DONE)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($leftovers as $old) {
            // 이미 이번 주에 같은 줄이 있으면 두 번 넘기지 않는다 — 버튼을 두 번 눌러도 안전하게.
            if (in_array($old->trade.'|'.mb_strtolower($old->task), $existing, true)) {
                continue;
            }

            WeekBoardLine::query()->create([
                'company_id' => $old->company_id,
                'site_id' => $old->site_id,
                'week_start' => $to,
                'trade' => $old->trade,
                'task' => $old->task,
                'headcount' => $old->headcount,
                'status' => WeekBoardLine::STATUS_PLANNED,
                'note' => $old->note,
                'wbs_codes' => $old->wbs_codes,
                'sort_order' => ++$sort,
                'carried_from_id' => $old->id,
                'created_by_id' => $user?->id,
            ]);
            $moved++;
        }

        return ['success' => true, 'moved' => $moved, 'weekStart' => $to];
    }

    // ── 안쪽 ──────────────────────────────────────────────────────────

    /**
     * 상태를 바꾸고, 붙어 있는 공정표 액티비티가 있으면 그쪽도 따라가게 한다.
     *
     * 사람이 눌렀으면($auto 없음) 상황실이 남긴 자동 흔적을 지운다 — 사람의 결정이
     * AI 의 짐작을 이긴다. 상황실이 바꿨으면($auto 있음) 어느 글을 듣고 바꿨는지 남긴다.
     *
     * @param  array<string, mixed>|null  $auto  상황실 자동 반영의 흔적(auto_source·auto_quote·auto_batch_id·auto_at)
     */
    public function applyStatus(WeekBoardLine $line, string $status, ?string $reason, ?int $userId = null, ?array $auto = null): ?string
    {
        $line->forceFill([
            'status' => $status,
            'reason' => $status === WeekBoardLine::STATUS_BLOCKED ? $reason : null,
            'done_at' => $status === WeekBoardLine::STATUS_DONE ? Carbon::now() : null,
            'updated_by_id' => $userId,
        ] + ($auto ?? ['auto_source' => null, 'auto_quote' => null, 'auto_batch_id' => null, 'auto_at' => null]))->save();

        if ($status !== WeekBoardLine::STATUS_DONE || ($line->wbs_codes ?? []) === []) {
            return null;
        }

        // 작업판이 끝났으면 정식 공정표의 그 액티비티도 끝난 것이다. 실패해도 작업판은
        // 살아야 한다 — 부가 기능이 주 기능을 막으면 안 된다.
        $failed = [];
        foreach ($line->wbs_codes as $code) {
            try {
                $res = app(WbsService::class)->markStatus((string) $code, WbsItem::STATUS_DONE);
                if (($res['success'] ?? false) !== true) {
                    $failed[] = $code.'('.($res['error'] ?? '?').')';
                }
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $code;
            }
        }

        return $failed === [] ? null : '작업판은 완료했지만 공정표 반영이 안 된 것이 있습니다: '.implode(', ', $failed);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(WeekBoardLine $l): array
    {
        return [
            'id' => $l->id,
            'trade' => $l->trade,
            'task' => $l->task,
            'headcount' => $l->headcount !== null ? (float) $l->headcount : null,
            'status' => $l->status,
            'statusLabel' => WeekBoardLine::STATUSES[$l->status] ?? $l->status,
            'reason' => $l->reason,
            'note' => $l->note,
            'wbsCodes' => $l->wbs_codes ?? [],
            'carried' => $l->carried_from_id !== null,
            'doneAt' => $l->done_at?->toDateTimeString(),
            // 상황실 글이 저절로 바꾼 줄 — 화면에 «상황실에서 자동» 과 들은 문장이 보인다.
            'auto' => $l->auto_source ? ['source' => $l->auto_source, 'quote' => $l->auto_quote, 'batchId' => $l->auto_batch_id] : null,
        ];
    }

    /**
     * 공종 순서 — 오늘 출근한 공종 → 이번 주 줄이 있는 공종 → 이 현장 직원의 공종, 일일보고와 같은 정렬.
     *
     * @param  Collection<int, WeekBoardLine>  $lines
     * @return array<int, string>
     */
    private function tradeOrder(Site $site, Collection $lines): array
    {
        $keys = collect(ReportSlot::keysOf($this->employees($site)))
            ->concat($lines->pluck('trade'))
            ->filter()->unique()->values()->all();

        $ordered = ReportSlot::sort($keys);

        // 줄이 하나라도 있는 공종만 그린다. 아무것도 안 적힌 공종을 전부 그리면
        // 작업판이 직원 명부처럼 길어져서, 정작 적힌 줄이 안 보인다.
        return array_values(array_filter($ordered, fn (string $t): bool => $lines->where('trade', $t)->isNotEmpty()));
    }

    /**
     * 폼에서 고를 공종 — 이 현장 직원들의 공종(일일보고와 같은 어휘) + 이미 적힌 공종.
     *
     * @param  Collection<int, WeekBoardLine>  $lines
     * @return array<int, string>
     */
    public function tradeOptions(Site $site, ?Collection $lines = null): array
    {
        // 도면에서 분석한 공정표의 공종이 먼저다(사장 지시) — 그 현장의 공정이 곧 공종 목록이다.
        $fromSchedule = WbsItem::query()->where('site_id', $site->id)
            ->whereNotNull('trade')->where('trade', '!=', '')->distinct()->pluck('trade')
            ->map(fn ($t) => trim((string) $t))->filter();

        $keys = $fromSchedule
            ->concat(collect(ReportSlot::keysOf($this->employees($site)))
                ->reject(fn (string $k): bool => ReportSlot::isOffice($k)))   // 사무·안전은 공종이 아니다
            ->concat(($lines ?? collect())->pluck('trade'))
            ->filter()->unique()->values()->all();

        return ReportSlot::sort($keys);
    }

    /**
     * 여러 줄을 한 번에 — 비서(AI)가 정리한 초안을 사람이 보고 저장할 때.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function saveMany(array $lines, string $siteId = 'ALL', ?string $week = null, ?string $source = null): array
    {
        $saved = 0;
        $errors = [];
        foreach ($lines as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            $res = $this->save($line + ['siteId' => $line['siteId'] ?? null, 'week' => $week], $siteId);
            if ($res['success'] ?? false) {
                $saved++;
                if ($source) {
                    WeekBoardLine::query()->whereKey($res['id'])->update(['note' => trim(((string) ($line['note'] ?? '')).' ['.$source.']')]);
                }
            } else {
                $errors[] = ($i + 1).'번째 줄: '.($res['error'] ?? implode(' ', $res['errors'] ?? []));
            }
        }

        return ['success' => $saved > 0 || $errors === [], 'saved' => $saved, 'errors' => $errors];
    }

    /** 이 사람이 볼 수 있는 현장 하나를 고른다 — 비서 초안이 어느 현장 낱말을 쓸지 정할 때. */
    public function siteFor(string $siteId, ?User $user): ?Site
    {
        return $this->site($siteId, $user);
    }

    /** @return Collection<int, Employee> */
    private function employees(Site $site): Collection
    {
        return Employee::query()
            ->where('site_id', $site->id)
            ->where('employment_status', 'active')
            ->get(['id', 'role', 'position', 'employment_type']);
    }

    /**
     * 오늘 그 현장에 출근한 사람 수 — 공종별. 계획 인원 옆에 현실을 놓는다.
     *
     * @return array<string, int>
     */
    private function presentByTrade(Site $site, string $today): array
    {
        $ids = AttendanceLog::query()
            ->where('site_id', $site->id)
            ->whereDate('attendance_date', $today)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->distinct()->pluck('employee_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $counts = [];
        foreach (Employee::query()->whereIn('id', $ids)->get(['id', 'role', 'position', 'employment_type']) as $e) {
            $key = ReportSlot::keyOf($e);
            if ($key !== null) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** 이 주의 월요일 — 현장 시계로. 아무 날짜를 줘도 그 주의 월요일로 맞춘다. */
    private function weekStart(Site $site, mixed $week): string
    {
        $day = is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($week))
            ? Carbon::parse(trim($week), SiteClock::zone($site))
            : Carbon::now()->setTimezone(SiteClock::zone($site));

        return $day->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    private function site(string $siteId, ?User $user): ?Site
    {
        $id = null;
        $siteId = trim($siteId);
        if ($siteId !== '' && ! in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            $id = is_numeric($siteId) ? (int) $siteId
                : Site::query()->whereRaw('upper(code) = ?', [strtoupper($siteId)])->value('id');
        }
        // 위에서 '전체' 를 골랐으면 보는 사람의 소속 현장으로 — 현장 사람은 대개 자기 현장을 본다.
        $id ??= $user ? AiInformationAccess::siteId($user) : null;

        $allowed = collect($this->siteOptions($user))->pluck('value')->map(fn ($v): int => (int) $v);

        // 볼 수 있는 현장이 하나뿐이면 그것이다 — «현장을 고르세요» 는 고를 게 있을 때만 말한다.
        if ($id === null && $allowed->count() === 1) {
            $id = $allowed->first();
        }
        if ($id === null) {
            return null;
        }

        $site = Site::query()->whereKey($id)->first();
        if ($site === null) {
            return null;
        }

        return $allowed->contains($site->id) ? $site : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteOptions(?User $user): array
    {
        $query = Site::query()->where('status', 'active');

        if ($user && ! in_array($user->access_role, AccessPolicy::SYSTEM_ROLES, true)
            && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $user->allowed_company_id
                    ? $query->where('company_id', $user->allowed_company_id)
                    : $query->whereRaw('1 = 0'),
                'site', 'team' => $user->allowed_site_id
                    ? $query->whereKey($user->allowed_site_id)
                    : $query->whereRaw('1 = 0'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Site $s): array => ['value' => $s->id, 'label' => trim($s->code.' — '.$s->name, ' —')])
            ->all();
    }

    private function canView(?User $user): bool
    {
        return $user !== null && $user->account_status === 'active';
    }

    /** 현장 운영자가 적는다 — 출퇴근 수정·문서와 같은 등급. */
    private function canManage(?User $user): bool
    {
        return AccessPolicy::canManageSite($user);
    }

    /**
     * @return array<int, string>|null
     */
    private function codes(mixed $v): ?array
    {
        $list = is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v);
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $list ?: []))));

        return $codes === [] ? null : $codes;
    }

    private function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v !== '' ? $v : null;
    }
}
