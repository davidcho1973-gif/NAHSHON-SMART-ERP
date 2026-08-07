<?php

namespace App\Services\Alerts;

use App\Models\DocumentActionItem;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\MemberDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\SafetyWorkIssue;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UnifiedAlertService
{
    /** @param array<string, mixed> $data */
    public function emit(string $fingerprint, array $data): UnifiedAlert
    {
        $alert = UnifiedAlert::query()->firstOrNew(['fingerprint' => $fingerprint]);

        if (! $alert->exists) {
            $alert->alert_code = $this->nextAlertCode();
            $alert->status = (string) ($data['status'] ?? 'unresolved');
            $alert->occurred_at = $data['occurred_at'] ?? now();
        }

        $alert->fill([
            ...$data,
            'last_detected_at' => now(),
        ]);
        $alert->save();

        return $alert;
    }

    /** @return array<int, array<string, mixed>> */
    public function forUser(User $user, string $siteScope = 'ALL', string $filter = 'all'): array
    {
        if (! Schema::hasTable('unified_alerts')) {
            return [];
        }

        $this->refreshKnownModules();

        $query = UnifiedAlert::query()
            ->visibleTo($user)
            ->orderByRaw("CASE status WHEN 'unresolved' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('occurred_at');

        if ($siteScope !== '' && strtoupper($siteScope) !== 'ALL') {
            $siteIds = Site::query()->where('code', $siteScope)->pluck('id');
            $query->where(function (Builder $siteQuery) use ($siteIds): void {
                $siteQuery->whereNull('site_id')->orWhereIn('site_id', $siteIds);
            });
        }

        if ($filter !== '' && strtolower($filter) !== 'all') {
            $query->where('source_module', strtoupper($filter));
        }

        $sites = Site::query()->pluck('code', 'id');

        return $query->limit(500)->get()->map(fn (UnifiedAlert $alert): array => $this->toApi($alert, $sites->all()))->all();
    }

    /** @return array{success: bool, alert?: array<string, mixed>, error?: string} */
    public function updateStatus(User $user, string $identifier, string $status): array
    {
        $normalized = match ($status) {
            '완료', 'completed', 'done' => 'completed',
            '처리중', 'in_progress' => 'in_progress',
            '무시', 'ignored' => 'ignored',
            default => 'unresolved',
        };

        $alert = UnifiedAlert::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('alert_code', $identifier);
                if (ctype_digit($identifier)) {
                    $query->orWhereKey((int) $identifier);
                }
            })
            ->first();

        if (! $alert) {
            return ['success' => false, 'error' => '알림을 찾을 수 없거나 접근 권한이 없습니다.'];
        }

        $alert->update([
            'status' => $normalized,
            'resolved_at' => in_array($normalized, ['completed', 'ignored'], true) ? now() : null,
        ]);

        if ($alert->source_type === DocumentActionItem::class && ctype_digit((string) $alert->source_id)) {
            DocumentActionItem::query()->whereKey((int) $alert->source_id)->update([
                'status' => $normalized === 'completed' ? 'completed' : ($normalized === 'ignored' ? 'ignored' : $normalized),
                'completed_at' => $normalized === 'completed' ? now() : null,
            ]);
        }

        return ['success' => true, 'alert' => $this->toApi($alert->fresh(), [])];
    }

    public function refreshKnownModules(): void
    {
        $this->refreshDocumentActions();
        $this->refreshContracts();
        $this->refreshHrExpiries();
        $this->refreshEquipment();
        $this->refreshProjectNotices();
        $this->refreshSafetyIssues();
    }

    private function refreshDocumentActions(): void
    {
        if (! Schema::hasTable('document_action_items')) {
            return;
        }

        DocumentActionItem::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where(function (Builder $query): void {
                $query->whereIn('severity', ['critical', 'high', 'warning'])
                    ->orWhere('due_at', '<=', now()->addDays(90));
            })
            ->with('document:id,uuid,title,original_file_name')
            ->get()
            ->each(function (DocumentActionItem $item): void {
                $this->emit('document-action:'.$item->id, [
                    'company_id' => $item->company_id,
                    'site_id' => $item->site_id,
                    'project_id' => $item->project_id,
                    'source_module' => 'DOC',
                    'source_type' => DocumentActionItem::class,
                    'source_id' => (string) $item->id,
                    'event_type' => $item->action_type,
                    'severity' => $this->normalizeSeverity($item->severity),
                    'title' => $item->title,
                    'content' => $item->details ?: $item->recommended_action,
                    'action_url' => '/document-hub?document='.$item->intelligent_document_id,
                    'due_at' => $item->due_at,
                    'metadata' => [
                        'document_id' => $item->intelligent_document_id,
                        'document_title' => $item->document?->displayTitle(),
                        'recommended_action' => $item->recommended_action,
                    ],
                ]);
            });
    }

    private function refreshContracts(): void
    {
        if (! Schema::hasTable('project_contracts')) {
            return;
        }

        ProjectContract::query()
            ->whereIn('status', ['draft', 'under_review', 'active', 'suspended'])
            ->whereNotNull('ends_on')
            ->where('ends_on', '<=', today()->addDays(60))
            ->get()
            ->each(function (ProjectContract $contract): void {
                $days = today()->diffInDays($contract->ends_on, false);
                $this->emit('contract-end:'.$contract->id.':'.$contract->ends_on->toDateString(), [
                    'company_id' => $contract->company_id,
                    'site_id' => $contract->site_id,
                    'project_id' => $contract->project_id,
                    'source_module' => 'CONTRACT',
                    'source_type' => ProjectContract::class,
                    'source_id' => (string) $contract->id,
                    'event_type' => 'contract_expiry',
                    'severity' => $days <= 7 ? 'critical' : 'warning',
                    'title' => '계약 종료일 확인: '.$contract->title,
                    'content' => $days < 0 ? '계약 종료일이 지났습니다.' : "계약 종료까지 {$days}일 남았습니다. 갱신·정산·준공 조건을 확인하세요.",
                    'action_url' => '/admin/project-contracts',
                    'due_at' => $contract->ends_on?->endOfDay(),
                ]);
            });

        ProjectContractDocument::query()
            ->where('is_current', true)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', today()->addDays(30))
            ->with('contract')
            ->get()
            ->each(function (ProjectContractDocument $document): void {
                $contract = $document->contract;
                $days = today()->diffInDays($document->expires_on, false);
                $this->emit('contract-doc-expiry:'.$document->id.':'.$document->expires_on->toDateString(), [
                    'company_id' => $contract?->company_id,
                    'site_id' => $contract?->site_id,
                    'project_id' => $contract?->project_id,
                    'source_module' => 'CONTRACT',
                    'source_type' => ProjectContractDocument::class,
                    'source_id' => (string) $document->id,
                    'event_type' => 'document_expiry',
                    'severity' => $days <= 3 ? 'critical' : 'warning',
                    'title' => '계약 증빙 만료: '.$document->title,
                    'content' => $days < 0 ? '현재본의 만료일이 지났습니다.' : "{$days}일 내 만료됩니다. 갱신본을 요청하세요.",
                    'action_url' => $contract ? '/admin/project-contracts/'.$contract->id.'/documents' : '/admin/project-contracts',
                    'due_at' => $document->expires_on?->endOfDay(),
                ]);
            });
    }

    private function refreshHrExpiries(): void
    {
        if (Schema::hasTable('employees')) {
            Employee::query()->where('employment_status', 'active')->get()->each(function (Employee $employee): void {
                foreach ([
                    'visa_expires_on' => ['비자 만료', 'visa_expiry'],
                    'safety_training_expires_on' => ['안전교육 만료', 'safety_training_expiry'],
                ] as $field => [$label, $event]) {
                    $due = $employee->{$field};
                    if (! $due || $due->gt(today()->addDays(30))) {
                        continue;
                    }
                    $days = today()->diffInDays($due, false);
                    $this->emit("employee-{$field}:{$employee->id}:{$due->toDateString()}", [
                        'company_id' => $employee->company_id,
                        'site_id' => $employee->site_id,
                        'employee_id' => $employee->id,
                        'source_module' => 'HR',
                        'source_type' => Employee::class,
                        'source_id' => (string) $employee->id,
                        'event_type' => $event,
                        'severity' => $days <= 7 ? 'critical' : 'warning',
                        'title' => "{$label}: {$employee->name}",
                        'content' => $days < 0 ? '만료일이 지났습니다.' : "{$days}일 내 만료됩니다.",
                        'action_url' => '/admin/employees',
                        'due_at' => $due->endOfDay(),
                    ]);
                }
            });
        }

        if (Schema::hasTable('member_documents')) {
            MemberDocument::query()
                ->whereNotNull('expires_on')
                ->where('expires_on', '<=', today()->addDays(30))
                ->with('memberRegistration')
                ->get()
                ->each(function (MemberDocument $document): void {
                    $registration = $document->memberRegistration;
                    $days = today()->diffInDays($document->expires_on, false);
                    $this->emit('member-doc-expiry:'.$document->id.':'.$document->expires_on->toDateString(), [
                        'company_id' => $registration?->company_id,
                        'site_id' => $registration?->site_id,
                        'source_module' => 'HR',
                        'source_type' => MemberDocument::class,
                        'source_id' => (string) $document->id,
                        'event_type' => 'member_document_expiry',
                        'severity' => $days <= 7 ? 'critical' : 'warning',
                        'title' => '직원서류 만료: '.$document->title,
                        'content' => $days < 0 ? '만료일이 지났습니다.' : "{$days}일 내 만료됩니다.",
                        'action_url' => '/admin/member-documents',
                        'due_at' => $document->expires_on?->endOfDay(),
                    ]);
                });
        }
    }

    private function refreshEquipment(): void
    {
        if (! Schema::hasTable('equipments')) {
            return;
        }

        Equipment::query()->get()->each(function (Equipment $equipment): void {
            foreach ([
                'inspection_due_on' => ['장비 점검기한', 'equipment_inspection'],
                'rent_end' => ['장비 임대종료', 'equipment_rental_end'],
            ] as $field => [$label, $event]) {
                $due = $equipment->{$field};
                if (! $due || $due->gt(today()->addDays(30))) {
                    continue;
                }
                $days = today()->diffInDays($due, false);
                $this->emit("equipment-{$field}:{$equipment->id}:{$due->toDateString()}", [
                    'company_id' => $equipment->company_id,
                    'site_id' => $equipment->site_id,
                    'project_id' => $equipment->project_id,
                    'source_module' => 'INV',
                    'source_type' => Equipment::class,
                    'source_id' => (string) $equipment->id,
                    'event_type' => $event,
                    'severity' => $days <= 3 ? 'critical' : 'warning',
                    'title' => "{$label}: {$equipment->equipment_code}",
                    'content' => $days < 0 ? '기한이 지났습니다.' : "{$days}일 내 도래합니다.",
                    'action_url' => '/?view=equipment',
                    'due_at' => $due->endOfDay(),
                ]);
            }
        });
    }

    private function refreshProjectNotices(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        Project::query()
            ->whereNotNull('preliminary_notice_due_on')
            ->where('preliminary_notice_due_on', '<=', today()->addDays(30))
            ->get()
            ->each(function (Project $project): void {
                $days = today()->diffInDays($project->preliminary_notice_due_on, false);
                $this->emit('project-prelim-notice:'.$project->id.':'.$project->preliminary_notice_due_on->toDateString(), [
                    'company_id' => $project->company_id,
                    'site_id' => $project->site_id,
                    'project_id' => $project->id,
                    'source_module' => 'WBS',
                    'source_type' => Project::class,
                    'source_id' => (string) $project->id,
                    'event_type' => 'preliminary_notice_due',
                    'severity' => $days <= 3 ? 'critical' : 'warning',
                    'title' => 'Preliminary Notice 기한: '.$project->name,
                    'content' => $days < 0 ? '통지 기한이 지났습니다. 권리 보전 여부를 즉시 검토하세요.' : "제출기한까지 {$days}일 남았습니다.",
                    'action_url' => '/admin/projects',
                    'due_at' => $project->preliminary_notice_due_on?->endOfDay(),
                ]);
            });
    }

    private function refreshSafetyIssues(): void
    {
        if (! Schema::hasTable('safety_work_issues')) {
            return;
        }

        SafetyWorkIssue::query()
            ->whereNotIn('status', ['완료', 'completed'])
            ->with('workItem')
            ->get()
            ->each(function (SafetyWorkIssue $issue): void {
                $work = $issue->workItem;
                $this->emit('safety-issue:'.$issue->id, [
                    'site_id' => $work?->site_id,
                    'source_module' => 'SAFETY',
                    'source_type' => SafetyWorkIssue::class,
                    'source_id' => (string) $issue->id,
                    'event_type' => 'safety_issue',
                    'severity' => in_array($issue->type, ['위험상황', '사고'], true) ? 'critical' : 'warning',
                    'title' => '안전 미조치: '.($work?->title ?: $issue->type),
                    'content' => $issue->body,
                    'assignee' => $issue->owner,
                    'action_url' => '/?view=safety-work',
                ]);
            });
    }

    /** @param array<int, string> $sites */
    private function toApi(UnifiedAlert $alert, array $sites): array
    {
        return [
            'id' => $alert->alert_code,
            'module' => $alert->source_module,
            'type' => $alert->event_type,
            'site' => $sites[$alert->site_id] ?? 'Global',
            'level' => $alert->severity,
            'severity' => match ($alert->severity) {
                'critical' => '긴급',
                'warning' => '주의',
                default => '일반',
            },
            'status' => match ($alert->status) {
                'in_progress' => '처리중',
                'completed' => '완료',
                'ignored' => '무시',
                default => '미처리',
            },
            'title' => $alert->title,
            'content' => $alert->content ?: '',
            'date' => $alert->occurred_at?->toDateString(),
            'ts' => $alert->occurred_at?->format('Y-m-d H:i:s'),
            'dueAt' => $alert->due_at?->toIso8601String(),
            'relatedId' => $alert->source_id,
            'actionUrl' => $alert->action_url,
            'assignee' => $alert->assignee,
            'metadata' => $alert->metadata ?: [],
        ];
    }

    private function normalizeSeverity(?string $severity): string
    {
        return in_array($severity, ['critical', 'high'], true)
            ? 'critical'
            : ($severity === 'warning' ? 'warning' : 'normal');
    }

    private function nextAlertCode(): string
    {
        do {
            $code = 'ALT-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
        } while (UnifiedAlert::query()->where('alert_code', $code)->exists());

        return $code;
    }
}
