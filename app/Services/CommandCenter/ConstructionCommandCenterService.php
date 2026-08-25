<?php

namespace App\Services\CommandCenter;

use App\Models\AttendanceLog;
use App\Models\DailyCrewReport;
use App\Models\DocumentActionItem;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\ExpensePreApproval;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\Team;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\WbsItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConstructionCommandCenterService
{
    /**
     * Build one access-scoped, database-backed operational snapshot.
     *
     * No demo/default records are injected here. An empty module is reported as
     * empty so the command center never presents sample values as live facts.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user, string $siteSelector = 'ALL'): array
    {
        $now = now();
        $today = $now->toDateString();
        $allVisibleSites = $this->visibleSites($user);
        $selectedSite = $this->selectedSite($allVisibleSites, $siteSelector);

        if ($this->isSpecificSite($siteSelector) && ! $selectedSite) {
            return [
                'success' => false,
                'error' => '선택한 현장에 접근할 수 없거나 현장이 존재하지 않습니다.',
            ];
        }

        $sites = $selectedSite ? collect([$selectedSite]) : $allVisibleSites;
        $siteIds = $sites->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $includeGlobalRecords = ! $selectedSite;

        $projects = Project::query()
            ->with(['manager', 'site'])
            ->whereIn('site_id', $siteIds)
            ->whereNotIn('project_stage', ['completed', 'closed'])
            ->orderBy('planned_completion_date')
            ->orderBy('project_code')
            ->get();

        $wbsItems = $this->wbsItems($siteIds, $projects);
        $projectRows = $this->projectRows($projects, $wbsItems, $now);
        $todayWork = $this->todayWork($wbsItems, $today);

        $employees = Employee::query()
            ->whereIn('site_id', $siteIds)
            ->where('employment_status', 'active')
            ->get();
        $attendance = AttendanceLog::query()
            ->whereIn('site_id', $siteIds)
            ->whereDate('attendance_date', $today)
            ->where('event_type', 'clock_in')
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->get();
        $crewReports = DailyCrewReport::query()
            ->whereIn('site_id', $siteIds)
            ->whereDate('work_date', $today)
            ->get();
        $teams = Team::query()
            ->with(['site', 'company'])
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $workforce = $this->workforce($teams, $employees, $attendance, $crewReports);

        $safetyCards = SafetyWorkItem::query()
            ->with(['issues', 'signatures'])
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->get();
        $openSafetyIssues = $safetyCards
            ->flatMap(fn (SafetyWorkItem $card): Collection => $card->issues->map(
                fn ($issue): array => ['card' => $card, 'issue' => $issue],
            ))
            ->reject(fn (array $row): bool => $this->isClosedStatus((string) $row['issue']->status))
            ->values();
        $tbmBlockers = $todayWork->where('tbmGated', true)->values();

        $expenses = $this->financeQuery(MobileExpense::query(), $user, $siteIds, $includeGlobalRecords)->get();
        $preApprovals = $this->financeQuery(ExpensePreApproval::query(), $user, $siteIds, $includeGlobalRecords)->get();
        $finance = $this->finance($expenses, $preApprovals, $user, $sites, $includeGlobalRecords, $now);

        $equipment = Equipment::query()
            ->visibleTo($user)
            ->whereIn('site_id', $siteIds)
            ->with(['site'])
            ->get();
        $assetStatus = $this->assetStatus($equipment, $now);

        $documents = IntelligentDocument::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($siteIds, $includeGlobalRecords): void {
                $query->whereIn('site_id', $siteIds);
                if ($includeGlobalRecords) {
                    $query->orWhereNull('site_id');
                }
            })
            ->get();
        $documentActions = DocumentActionItem::query()
            ->whereIn('intelligent_document_id', $documents->pluck('id'))
            ->get()
            ->reject(fn (DocumentActionItem $action): bool => $this->isClosedStatus($action->status))
            ->values();
        $documentStatus = $this->documentStatus($documents, $documentActions, $now);

        $alerts = UnifiedAlert::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($siteIds, $includeGlobalRecords): void {
                $query->whereIn('site_id', $siteIds);
                if ($includeGlobalRecords) {
                    $query->orWhereNull('site_id');
                }
            })
            ->orderByDesc('occurred_at')
            ->get()
            ->reject(fn (UnifiedAlert $alert): bool => $this->isClosedStatus($alert->status))
            ->values();

        $decisions = $this->decisions(
            $alerts,
            $openSafetyIssues,
            $tbmBlockers,
            $documentActions,
            $finance,
            $assetStatus,
            $workforce,
            $projectRows,
            $now,
        );

        $scopeLabel = $selectedSite
            ? trim($selectedSite->code.' · '.$selectedSite->name)
            : 'Global · 접근 가능한 '.$sites->count().'개 현장';

        $sources = collect([
            $this->source('현장·프로젝트', 'sites / projects', $sites->count() + $projects->count(), $projects->merge($sites)),
            $this->source('공정·안전', 'wbs_items / safety_work_*', $wbsItems->count() + $safetyCards->count(), $wbsItems->merge($safetyCards)),
            $this->source('출퇴근·인원마감', 'attendance_logs / daily_crew_reports', $attendance->count() + $crewReports->count(), $attendance->merge($crewReports)),
            $this->source('비용·급여', 'mobile_expenses / expense_pre_approvals / payroll_runs', $expenses->count() + $preApprovals->count() + $finance['payrollWaiting'], $expenses->merge($preApprovals)),
            $this->source('장비·재고', 'equipments', $equipment->count(), $equipment),
            $this->source('문서·통합알림', 'intelligent_documents / unified_alerts', $documents->count() + $alerts->count(), $documents->merge($alerts)),
        ])->values();

        $emptyModules = $sources
            ->filter(fn (array $source): bool => $source['records'] === 0)
            ->pluck('label')
            ->values()
            ->all();

        return [
            'success' => true,
            'isLive' => true,
            'generatedAt' => $now->toIso8601String(),
            'generatedLabel' => $now->format('Y-m-d H:i'),
            'scope' => [
                'label' => $scopeLabel,
                'siteIds' => $siteIds->all(),
                'siteCount' => $sites->count(),
                'selectedSiteCode' => $selectedSite?->code,
            ],
            'health' => [
                'decisionQueue' => $decisions->count(),
                'pendingCost' => $finance['pendingAmount'],
                'safetyBlockers' => $openSafetyIssues->count() + $tbmBlockers->count(),
                'scheduleRisk' => $projectRows->whereIn('risk', ['critical', 'warning'])->count(),
            ],
            'workforce' => $workforce,
            'decisions' => $decisions->take(10)->values()->all(),
            'projects' => $projectRows->take(10)->values()->all(),
            'todayWork' => $todayWork->take(10)->values()->all(),
            'finance' => $finance,
            'billing' => [
                [
                    'label' => '영수증 비용 승인 대기',
                    'amount' => $finance['pendingExpenseAmount'],
                    'count' => $finance['pendingExpenseCount'],
                    'status' => $finance['pendingExpenseCount'] > 0 ? '승인대기' : '정상',
                    'action' => '재무에서 검토',
                    'source' => 'mobile_expenses',
                ],
                [
                    'label' => '사전승인 요청 대기',
                    'amount' => $finance['pendingPreApprovalAmount'],
                    'count' => $finance['pendingPreApprovalCount'],
                    'status' => $finance['pendingPreApprovalCount'] > 0 ? '승인대기' : '정상',
                    'action' => '예산 승인',
                    'source' => 'expense_pre_approvals',
                ],
                [
                    'label' => '직원 정산 가능액',
                    'amount' => $finance['claimableAmount'],
                    'count' => $finance['claimableCount'],
                    'status' => $finance['claimableCount'] > 0 ? '정산필요' : '정상',
                    'action' => '급여·지급 확인',
                    'source' => 'mobile_expenses',
                ],
            ],
            'equipment' => $assetStatus,
            'documents' => $documentStatus,
            'alerts' => [
                'open' => $alerts->count(),
                'critical' => $alerts->filter(fn (UnifiedAlert $alert): bool => $this->priority($alert->severity) === 'critical')->count(),
                'items' => $alerts->take(8)->map(fn (UnifiedAlert $alert): array => [
                    'id' => $alert->alert_code,
                    'title' => $alert->title,
                    'severity' => $this->priority($alert->severity),
                    'module' => $alert->source_module,
                    'occurredAt' => $alert->occurred_at?->toIso8601String(),
                    'actionUrl' => $alert->action_url,
                ])->values()->all(),
            ],
            'brief' => $this->brief($scopeLabel, $workforce, $openSafetyIssues, $tbmBlockers, $projectRows, $finance, $documentStatus, $assetStatus),
            'sources' => $sources->all(),
            'dataQuality' => [
                'emptyModules' => $emptyModules,
                'hasOperationalData' => count($emptyModules) < $sources->count(),
                'message' => $emptyModules === []
                    ? '연결된 운영 테이블에서 최신 데이터를 집계했습니다.'
                    : '데이터가 없는 영역: '.implode(', ', $emptyModules),
            ],
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    private function visibleSites(User $user): Collection
    {
        $query = Site::query()->where('status', 'active');

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query->orderBy('code')->get();
        }

        match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where(function (Builder $company) use ($user): void {
                    $company
                        ->where('company_id', $user->allowed_company_id)
                        ->orWhereHas('companies', fn (Builder $linked): Builder => $linked->whereKey($user->allowed_company_id));
                })
                : $query->whereRaw('1 = 0'),
            'site' => $user->allowed_site_id
                ? $query->whereKey($user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            'team' => $user->allowed_team_id
                ? $query->whereKey(Team::query()->whereKey($user->allowed_team_id)->value('site_id'))
                : $query->whereRaw('1 = 0'),
            'self' => $user->employee?->site_id
                ? $query->whereKey($user->employee->site_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->orderBy('code')->get();
    }

    private function selectedSite(Collection $sites, string $selector): ?Site
    {
        if (! $this->isSpecificSite($selector)) {
            return null;
        }

        $selector = trim($selector);
        $code = str_contains($selector, ' - ') ? trim(strstr($selector, ' - ', true)) : $selector;

        return $sites->first(fn (Site $site): bool => (string) $site->id === $selector
            || strcasecmp($site->code, $selector) === 0
            || strcasecmp($site->code, $code) === 0
            || strcasecmp($site->name, $selector) === 0);
    }

    private function isSpecificSite(string $selector): bool
    {
        return ! in_array(strtoupper(trim($selector)), ['', 'ALL', 'GLOBAL'], true);
    }

    /**
     * @param  Collection<int, int>  $siteIds
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, WbsItem>
     */
    private function wbsItems(Collection $siteIds, Collection $projects): Collection
    {
        return WbsItem::query()
            ->with(['safetyWorkItems.signatures', 'safetyWorkItems.issues'])
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->where(function (Builder $scope) use ($siteIds, $projects): void {
                $scope
                    ->whereIn('site_id', $siteIds)
                    ->orWhereIn('project_id', $projects->pluck('id'))
                    ->orWhereIn('project_code', $projects->pluck('project_code'));
            })
            ->orderBy('planned_end')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, WbsItem>  $wbsItems
     * @return Collection<int, array<string, mixed>>
     */
    private function projectRows(Collection $projects, Collection $wbsItems, Carbon $now): Collection
    {
        return $projects->map(function (Project $project) use ($wbsItems, $now): array {
            $items = $wbsItems->filter(fn (WbsItem $item): bool => (int) $item->project_id === (int) $project->id
                || $item->project_code === $project->project_code);
            // 진척률은 정본 산식 하나(WbsService::weightedProgress — 공수→공기→균등)로.
            // 자체 산식(공수만)은 공수가 빈 흔한 공정표에서 단순 평균으로 떨어져,
            // 같은 프로젝트에 공정 화면과 다른 숫자가 떴다(연계 점검: 가중치 상이).
            $progress = $items->isEmpty() ? 0 : app(\App\Services\Wbs\WbsService::class)->weightedProgress($items->values());
            $endDate = $project->planned_completion_date
                ?: $items->whereNotNull('planned_end')->max('planned_end');
            $daysLeft = $endDate ? $now->copy()->startOfDay()->diffInDays(Carbon::parse($endDate), false) : null;
            $overdue = $items->filter(fn (WbsItem $item): bool => $item->planned_end
                && $item->planned_end->isBefore($now->copy()->startOfDay())
                && $item->effectiveProgress() < 100);
            $criticalOpen = $items->filter(fn (WbsItem $item): bool => (bool) $item->is_critical && $item->effectiveProgress() < 100);

            $risk = 'ok';
            $signal = $items->isEmpty() ? 'WBS 미등록' : '계획 범위';
            $nextAction = $items->isEmpty() ? 'WBS 작업을 등록해 진척 기준선을 만드세요.' : '현장 실적과 안전카드를 계속 갱신하세요.';

            if ($overdue->isNotEmpty() || ($daysLeft !== null && $daysLeft < 0 && $progress < 100)) {
                $risk = 'critical';
                $signal = '지연 '.$overdue->count().'개';
                $nextAction = '지연 작업의 회복 일정·추가 인력·장비를 확정하세요.';
            } elseif ($criticalOpen->isNotEmpty() || ($daysLeft !== null && $daysLeft <= 14 && $progress < 85)) {
                $risk = 'warning';
                $signal = $criticalOpen->isNotEmpty() ? 'Critical Path '.$criticalOpen->count().'개' : '14일 내 마감';
                $nextAction = 'Critical Path와 자재 납기를 오늘 재확인하세요.';
            }

            return [
                'id' => $project->id,
                'code' => $project->project_code,
                'name' => $project->name,
                'site' => $project->site?->code,
                'manager' => $project->manager?->name ?: '미지정',
                'stage' => $project->project_stage,
                'progress' => $progress,
                'wbsCount' => $items->count(),
                'overdueCount' => $overdue->count(),
                'criticalCount' => $criticalOpen->count(),
                'endDate' => $endDate ? Carbon::parse($endDate)->toDateString() : null,
                'daysLeft' => $daysLeft,
                'risk' => $risk,
                'signal' => $signal,
                'nextAction' => $nextAction,
                'color' => match ($risk) {
                    'critical' => '#ef4444',
                    'warning' => '#f59e0b',
                    default => '#10b981',
                },
                'source' => 'projects + wbs_items',
            ];
        })->sortBy(fn (array $row): int => match ($row['risk']) {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        })->values();
    }

    /**
     * @param  Collection<int, WbsItem>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function todayWork(Collection $items, string $today): Collection
    {
        $date = Carbon::parse($today);

        return $items
            ->filter(function (WbsItem $item) use ($date): bool {
                if ($item->status === WbsItem::STATUS_IN_PROGRESS) {
                    return true;
                }

                return $item->planned_start
                    && $item->planned_end
                    && $item->planned_start->lte($date)
                    && $item->planned_end->gte($date)
                    && $item->effectiveProgress() < 100;
            })
            ->map(function (WbsItem $item) use ($today): array {
                $card = $item->safetyWorkItems->first(
                    fn (SafetyWorkItem $candidate): bool => $candidate->work_date?->toDateString() === $today,
                );
                $fieldWork = (float) $item->crew_size > 0;
                $tbmGated = $fieldWork && (! $card || ! $card->isTbmCleared());

                return [
                    'id' => $item->wbs_code,
                    'projectCode' => $item->project_code,
                    'name' => $item->name,
                    'trade' => $item->trade ?: '-',
                    'company' => $item->company ?: '-',
                    'plannedCrew' => (float) ($item->crew_size ?: 0),
                    'actualCrew' => $card?->signatures->where('signed', true)->count() ?? 0,
                    'progress' => $item->effectiveProgress(),
                    'isCritical' => (bool) $item->is_critical,
                    'tbmGated' => $tbmGated,
                    'safetyStatus' => ! $fieldWork ? 'TBM 비대상' : ($card ? ($card->isTbmCleared() ? 'TBM 완료' : 'TBM 미완료') : '안전카드 없음'),
                    'endDate' => $item->planned_end?->toDateString(),
                    'source' => 'wbs_items + safety_work_items',
                ];
            })
            ->sortBy(fn (array $row): int => $row['tbmGated'] ? 0 : ($row['isCritical'] ? 1 : 2))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function workforce(Collection $teams, Collection $employees, Collection $attendance, Collection $reports): array
    {
        $presentEmployeeIds = $attendance->pluck('employee_id')->filter()->unique();
        $presentByTeam = $attendance
            ->whereNotNull('team_id')
            ->groupBy('team_id')
            ->map(fn (Collection $logs): int => $logs->pluck('employee_id')->filter()->unique()->count());
        $reportsByTeam = $reports->keyBy('team_id');

        $teamRows = $teams->map(function (Team $team) use ($presentByTeam, $reportsByTeam): array {
            $report = $reportsByTeam->get($team->id);
            $registered = (int) ($presentByTeam->get($team->id) ?? 0);

            return [
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
                'site' => $team->site?->code,
                'company' => $team->effectiveCompanyName() ?: '-',
                'trade' => $team->trade_type ?: '-',
                'foreman' => $team->foreman_name ?: $team->supervisor_name ?: '미지정',
                'planned' => (int) ($team->planned_headcount ?: 0),
                'registeredPresent' => $registered,
                'external' => (int) ($report?->external_headcount ?: 0),
                'actual' => $report ? (int) $report->final_headcount : $registered,
                'closed' => (bool) $report,
                'closedAt' => $report?->closed_at?->toIso8601String(),
                'workDescription' => $report?->work_description,
            ];
        })->values();

        $unteamedPresent = $attendance
            ->whereNull('team_id')
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->count();

        return [
            'activeEmployees' => $employees->count(),
            'checkedIn' => $presentEmployeeIds->count(),
            'notCheckedIn' => max(0, $employees->count() - $presentEmployeeIds->count()),
            'externalHeadcount' => (int) $reports->sum('external_headcount'),
            'finalHeadcount' => (int) $teamRows->sum('actual') + $unteamedPresent,
            'attendanceRate' => $employees->count() > 0
                ? (int) round($presentEmployeeIds->count() / $employees->count() * 100)
                : 0,
            'teamsClosed' => $reports->pluck('team_id')->unique()->count(),
            'teamsOpen' => max(0, $teams->count() - $reports->pluck('team_id')->unique()->count()),
            'teams' => $teamRows->all(),
        ];
    }

    private function financeQuery(Builder $query, User $user, Collection $siteIds, bool $includeGlobalRecords): Builder
    {
        $query->where(function (Builder $site) use ($siteIds, $includeGlobalRecords): void {
            $site->whereIn('site_id', $siteIds);
            if ($includeGlobalRecords) {
                $site->orWhereNull('site_id');
            }
        });

        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)
            || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'team' => $user->allowed_team_id
                ? $query->whereHas('employee', fn (Builder $employee): Builder => $employee->where('team_id', $user->allowed_team_id))
                : $query->whereRaw('1 = 0'),
            'self' => $user->employee_id
                ? $query->where('employee_id', $user->employee_id)
                : $query->whereRaw('1 = 0'),
            default => $query,
        };
    }

    /**
     * @return array<string, float|int>
     */
    private function finance(
        Collection $expenses,
        Collection $preApprovals,
        User $user,
        Collection $sites,
        bool $includeGlobalRecords,
        Carbon $now,
    ): array {
        $pendingExpenses = $expenses->where('status', 'pending');
        $pendingPreApprovals = $preApprovals->where('status', 'pending');
        $claimable = $expenses
            ->where('status', 'approved')
            ->where('payment_type', 'personal');
        $mtdExpenses = $expenses
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->filter(fn (MobileExpense $expense): bool => $expense->expense_date?->gte($now->copy()->startOfMonth()) ?? false);

        $payrollQuery = PayrollRun::query()->whereNotIn('status', ['paid', 'closed', 'cancelled']);
        if (! $includeGlobalRecords) {
            $siteCodes = $sites->pluck('code')->filter()->all();
            $payrollQuery->whereIn('site_scope', $siteCodes);
        }
        if (! in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)) {
            $payrollQuery->whereRaw('1 = 0');
        }
        $payroll = $payrollQuery->get();

        return [
            'pendingExpenseCount' => $pendingExpenses->count(),
            'pendingExpenseAmount' => (float) $pendingExpenses->sum('amount'),
            'pendingPreApprovalCount' => $pendingPreApprovals->count(),
            'pendingPreApprovalAmount' => (float) $pendingPreApprovals->sum('estimated_amount'),
            'pendingCount' => $pendingExpenses->count() + $pendingPreApprovals->count(),
            'pendingAmount' => (float) $pendingExpenses->sum('amount') + (float) $pendingPreApprovals->sum('estimated_amount'),
            'claimableCount' => $claimable->count(),
            'claimableAmount' => (float) $claimable->sum('amount'),
            'mtdSpend' => (float) $mtdExpenses->sum('amount'),
            'payrollWaiting' => $payroll->count(),
            'payrollWaitingAmount' => (float) $payroll->sum('total_gross'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetStatus(Collection $equipment, Carbon $now): array
    {
        $maintenanceStatuses = ['정비중', '수리필요', 'maintenance', 'repair'];
        $rented = $equipment->filter(fn (Equipment $asset): bool => $asset->acquisition_type === 'rental' && $asset->rent_end);
        $overdue = $rented->filter(fn (Equipment $asset): bool => $asset->rent_end->isBefore($now->copy()->startOfDay()));
        $dueSoon = $rented->filter(fn (Equipment $asset): bool => $asset->rent_end->between(
            $now->copy()->startOfDay(),
            $now->copy()->addDays(7)->endOfDay(),
        ));
        $inspectionDue = $equipment->filter(fn (Equipment $asset): bool => $asset->inspection_due_on
            && $asset->inspection_due_on->lte($now->copy()->endOfDay()));

        return [
            'total' => (int) $equipment->sum(fn (Equipment $asset): int => max(1, (int) $asset->quantity)),
            'records' => $equipment->count(),
            'maintenance' => $equipment->whereIn('status', $maintenanceStatuses)->count(),
            'inspectionDue' => $inspectionDue->count(),
            'rentalOverdue' => $overdue->count(),
            'rentalDueSoon' => $dueSoon->count(),
            'riskItems' => $overdue->merge($dueSoon)->merge($inspectionDue)->unique('id')->take(8)->map(fn (Equipment $asset): array => [
                'id' => $asset->equipment_code,
                'name' => trim(($asset->equipment_type ?: '장비').' · '.($asset->model ?: '모델 미등록')),
                'status' => $asset->inspection_due_on?->lte($now) ? '검사기한' : ($asset->rent_end?->isBefore($now) ? '반납지연' : '7일 내 반납'),
                'dueDate' => ($asset->inspection_due_on ?: $asset->rent_end)?->toDateString(),
                'site' => $asset->site?->code,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentStatus(Collection $documents, Collection $actions, Carbon $now): array
    {
        return [
            'total' => $documents->count(),
            'aiPending' => $documents->whereIn('ai_status', ['queued', 'processing'])->count(),
            'reviewPending' => $documents
                ->where('ai_status', 'ready')
                ->whereNull('reviewed_at')
                ->count(),
            'openActions' => $actions->count(),
            'overdueActions' => $actions->filter(fn (DocumentActionItem $action): bool => $action->due_at?->isBefore($now) ?? false)->count(),
            'dueSoonActions' => $actions->filter(fn (DocumentActionItem $action): bool => $action->due_at?->between($now, $now->copy()->addDays(7)) ?? false)->count(),
            'actions' => $actions->sortBy('due_at')->take(8)->map(fn (DocumentActionItem $action): array => [
                'id' => $action->id,
                'title' => $action->title,
                'severity' => $this->priority($action->severity),
                'status' => $action->status,
                'module' => $action->related_module,
                'dueAt' => $action->due_at?->toIso8601String(),
                'recommendedAction' => $action->recommended_action,
                'documentId' => $action->intelligent_document_id,
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function decisions(
        Collection $alerts,
        Collection $safetyIssues,
        Collection $tbmBlockers,
        Collection $documentActions,
        array $finance,
        array $equipment,
        array $workforce,
        Collection $projects,
        Carbon $now,
    ): Collection {
        $decisions = collect();

        $alerts->take(4)->each(function (UnifiedAlert $alert) use ($decisions): void {
            $decisions->push([
                'id' => $alert->alert_code,
                'priority' => $this->priority($alert->severity),
                'type' => $alert->source_module ?: '알림',
                'title' => $alert->title,
                'detail' => $alert->content ?: '통합 알림 센터에서 원인과 후속조치를 확인하세요.',
                'why' => '실제 통합 알림 · '.($alert->occurred_at?->format('m/d H:i') ?: '시각 미등록'),
                'owner' => $alert->assignee ?: '담당자 미지정',
                'nextAction' => '알림 열기',
                'view' => $this->moduleView($alert->source_module),
                'url' => $alert->action_url,
                'source' => 'unified_alerts',
            ]);
        });

        $safetyIssues->take(2)->each(function (array $row) use ($decisions): void {
            $issue = $row['issue'];
            /** @var SafetyWorkItem $card */
            $card = $row['card'];
            $decisions->push([
                'id' => 'safety-issue-'.$issue->id,
                'priority' => $this->priority($issue->type === '위험상황' ? 'critical' : 'warning'),
                'type' => '안전',
                'title' => $issue->body ?: $card->title.' 미조치 이슈',
                'detail' => $card->work_code.' · '.$card->title.' · 상태 '.$issue->status,
                'why' => '실제 안전 작업카드의 미완료 이슈',
                'owner' => $issue->owner ?: '안전담당',
                'nextAction' => '안전카드 확인',
                'view' => 'safety',
                'source' => 'safety_work_issues',
            ]);
        });

        if ($tbmBlockers->isNotEmpty()) {
            $decisions->push([
                'id' => 'tbm-gate-'.$now->toDateString(),
                'priority' => 'critical',
                'type' => 'TBM Gate',
                'title' => '오늘 작업 전 안전 게이트 '.$tbmBlockers->count().'건',
                'detail' => $tbmBlockers->take(3)->pluck('name')->implode(' · '),
                'why' => '안전카드 없음 또는 TBM 서명 미완료',
                'owner' => '현장소장 / 안전담당',
                'nextAction' => '오늘 작업 확인',
                'view' => 'wbs',
                'source' => 'wbs_items + safety_work_items',
            ]);
        }

        $overdueActions = $documentActions->filter(fn (DocumentActionItem $action): bool => $action->due_at?->isBefore($now) ?? false);
        if ($overdueActions->isNotEmpty()) {
            $decisions->push([
                'id' => 'document-overdue-'.$now->toDateString(),
                'priority' => 'critical',
                'type' => '문서',
                'title' => '기한 초과 문서 후속조치 '.$overdueActions->count().'건',
                'detail' => $overdueActions->take(3)->pluck('title')->implode(' · '),
                'why' => 'AI 문서함에서 추출한 실제 기한과 미완료 상태',
                'owner' => 'PM / 문서담당',
                'nextAction' => '문서함 열기',
                'view' => 'document-hub',
                'source' => 'document_action_items',
            ]);
        }

        if ($finance['pendingCount'] > 0) {
            $decisions->push([
                'id' => 'finance-pending-'.$now->toDateString(),
                'priority' => 'warning',
                'type' => '비용승인',
                'title' => '승인 대기 비용 '.$finance['pendingCount'].'건',
                'detail' => '영수증·사전승인 합계 $'.number_format((float) $finance['pendingAmount'], 2),
                'why' => '실제 비용 및 사전승인 대기 상태',
                'owner' => '회계 / 승인권자',
                'nextAction' => '재무 확인',
                'view' => 'finance',
                'source' => 'mobile_expenses + expense_pre_approvals',
            ]);
        }

        $assetRisk = $equipment['rentalOverdue'] + $equipment['rentalDueSoon'] + $equipment['inspectionDue'];
        if ($assetRisk > 0) {
            $decisions->push([
                'id' => 'asset-risk-'.$now->toDateString(),
                'priority' => $equipment['rentalOverdue'] > 0 ? 'critical' : 'warning',
                'type' => '장비',
                'title' => '반납·검사 결정이 필요한 장비 '.$assetRisk.'건',
                'detail' => '반납지연 '.$equipment['rentalOverdue'].' · 7일 내 반납 '.$equipment['rentalDueSoon'].' · 검사기한 '.$equipment['inspectionDue'],
                'why' => '실제 장비 계약 종료일 및 검사 예정일',
                'owner' => '장비담당 / PM',
                'nextAction' => '장비 확인',
                'view' => 'inventory',
                'source' => 'equipments',
            ]);
        }

        if ($workforce['teamsOpen'] > 0) {
            $decisions->push([
                'id' => 'crew-close-'.$now->toDateString(),
                'priority' => 'warning',
                'type' => '인원마감',
                'title' => '오늘 인원 마감 미완료 팀 '.$workforce['teamsOpen'].'개',
                'detail' => '등록 출근 '.$workforce['checkedIn'].'명 · 외부 인원 '.$workforce['externalHeadcount'].'명',
                'why' => '활성 팀 대비 실제 일일 인원 마감 기록',
                'owner' => '팀장 / 현장소장',
                'nextAction' => '인원 현황',
                'view' => 'hr',
                'source' => 'teams + daily_crew_reports',
            ]);
        }

        $scheduleRisk = $projects->whereIn('risk', ['critical', 'warning']);
        if ($scheduleRisk->isNotEmpty()) {
            $decisions->push([
                'id' => 'schedule-risk-'.$now->toDateString(),
                'priority' => $scheduleRisk->contains('risk', 'critical') ? 'critical' : 'warning',
                'type' => '공정',
                'title' => '일정 주의 프로젝트 '.$scheduleRisk->count().'개',
                'detail' => $scheduleRisk->take(3)->pluck('code')->implode(' · '),
                'why' => '실제 WBS 종료예정일·진척률·Critical Path 집계',
                'owner' => 'PM / 공정담당',
                'nextAction' => 'WBS 열기',
                'view' => 'wbs',
                'source' => 'projects + wbs_items',
            ]);
        }

        return $decisions
            ->sortBy(fn (array $decision): int => match ($decision['priority']) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            })
            ->unique('id')
            ->values();
    }

    private function priority(?string $severity): string
    {
        return match (strtolower(trim((string) $severity))) {
            'critical', 'urgent', 'high', 'danger', '긴급', '위험상황' => 'critical',
            'warning', 'medium', '주의', '미조치' => 'warning',
            default => 'ok',
        };
    }

    private function moduleView(?string $module): string
    {
        return match (strtoupper((string) $module)) {
            'DOC', 'DOCUMENT', 'CONTRACT' => 'document-hub',
            'WBS', 'SCHEDULE', 'PROJECT' => 'wbs',
            'SAFETY', 'EHS' => 'safety',
            'FINANCE', 'PAYROLL', 'EXPENSE' => 'finance',
            'EQUIPMENT', 'INVENTORY' => 'inventory',
            'HR', 'ATTENDANCE' => 'hr',
            default => 'alerts',
        };
    }

    private function isClosedStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), [
            'completed', 'complete', 'resolved', 'closed', 'dismissed', 'ignored',
            '완료', '처리완료', '해결', '무시',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $label, string $tables, int $records, Collection $models): array
    {
        $latest = $models
            ->map(fn ($model) => $model->updated_at ?? $model->created_at ?? null)
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'label' => $label,
            'tables' => $tables,
            'records' => $records,
            'latestAt' => $latest?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function brief(
        string $scopeLabel,
        array $workforce,
        Collection $safetyIssues,
        Collection $tbmBlockers,
        Collection $projects,
        array $finance,
        array $documents,
        array $equipment,
    ): array {
        $scheduleRisk = $projects->whereIn('risk', ['critical', 'warning'])->count();

        return [
            $scopeLabel.' 기준 실제 출근 '.$workforce['checkedIn'].'명, 외부 마감 인원 '.$workforce['externalHeadcount'].'명, 인원 마감 미완료 팀 '.$workforce['teamsOpen'].'개입니다.',
            '안전 미조치 '.$safetyIssues->count().'건, 오늘 TBM 게이트 '.$tbmBlockers->count().'건, 일정 주의 프로젝트 '.$scheduleRisk.'개를 확인했습니다.',
            '승인 대기 비용 $'.number_format((float) $finance['pendingAmount'], 2).', 문서 후속조치 '.$documents['openActions'].'건, 장비 반납·검사 위험 '.($equipment['rentalOverdue'] + $equipment['rentalDueSoon'] + $equipment['inspectionDue']).'건입니다.',
        ];
    }
}
