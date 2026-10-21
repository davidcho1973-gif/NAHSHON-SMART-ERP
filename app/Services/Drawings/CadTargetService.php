<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\ContractChange;
use App\Models\DailyClosingReport;
use App\Models\IntelligentDocument;
use App\Models\PurchaseRequest;
use App\Models\Submittal;
use App\Models\WbsItem;
use App\Models\WbsPhoto;
use App\Models\WorkSection;
use App\Services\Admin\BillingAdminService;
use App\Services\Admin\ProjectRegisterService;
use App\Services\Finance\ClaimEvidenceService;
use App\Services\Procurement\PurchaseRequestService;
use App\Support\FieldClaimAccess;
use App\Support\JobAccess;
use App\Support\PurchaseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** References resolve through the owning module. This service owns no quantity/status ledger. */
class CadTargetService
{
    public const TYPES = ['section', 'boq', 'work_record', 'photo', 'daily_report', 'wbs', 'submittal', 'document', 'purchase', 'contract_change'];

    private array $ledgers = [];

    public function query(CadDrawing $drawing, string $type): Builder
    {
        app(CadDrawingService::class)->access(auth()->user(), $drawing);
        abort_unless(in_array($type, self::TYPES, true), 422);
        abort_if(FieldClaimAccess::restricted() && ! in_array($type, ['section', 'boq', 'work_record'], true), 403);
        $q = match ($type) {
            'section' => FieldClaimAccess::restricted() ? FieldClaimAccess::sections() : WorkSection::query(),
            'boq' => ContractBoqLine::query()->whereHas('contract', fn ($q) => $q->where('site_id', $drawing->site_id)->where('company_id', $drawing->company_id)),
            'work_record' => ClaimWorkRecord::query()->whereHas('line.contract', fn ($q) => $q->where('site_id', $drawing->site_id)->where('company_id', $drawing->company_id)),
            'photo' => WbsPhoto::query(), 'daily_report' => DailyClosingReport::query(), 'wbs' => WbsItem::query(),
            'submittal' => Submittal::query(), 'document' => IntelligentDocument::query()->visibleTo(auth()->user()),
            'purchase' => PurchaseRequest::query(),
            'contract_change' => ContractChange::query()->whereHas('contract', fn ($q) => $q->where('site_id', $drawing->site_id)->where('company_id', $drawing->company_id)),
        };
        if (! in_array($type, ['boq', 'work_record', 'contract_change'], true)) {
            $q->where('site_id', $drawing->site_id);
        }
        if (in_array($type, ['section', 'wbs', 'submittal', 'document', 'purchase'], true)) {
            $q->where('company_id', $drawing->company_id);
        }
        if (in_array($type, ['document', 'submittal'], true)) {
            abort_if(JobAccess::managed(auth()->user()) && ! JobAccess::can(auth()->user(), 'documents'), 403);
            abort_unless(app(ProjectRegisterService::class)->canView(), 403);
        }
        if ($type === 'purchase') {
            $user = auth()->user();
            abort_unless(PurchaseAccess::canRequest($user) || PurchaseAccess::canBuy($user), 403);
            if (! PurchaseAccess::canBuy($user)) {
                $q->where('requested_by_id', $user->id);
            }
        }
        if ($type === 'photo' && ! in_array(auth()->user()->access_role, ['admin', 'super_admin'], true)) {
            abort_unless((int) auth()->user()->allowed_site_id === (int) $drawing->site_id, 403);
        }
        if ($type === 'contract_change') {
            abort_unless(app(BillingAdminService::class)->canView(), 403);
        }
        if ($type === 'daily_report') {
            abort_if(JobAccess::managed(auth()->user()) && ! JobAccess::can(auth()->user(), 'reports'), 403);
        }

        return $q;
    }

    public function catalog(CadDrawing $drawing, string $type, string $search = '', int $after = 0): array
    {
        $q = $this->query($drawing, $type)->where('id', '>', $after)->orderBy('id');
        $column = match ($type) {
            'section', 'wbs' => 'name', 'boq' => 'description', 'photo' => 'caption', 'daily_report' => 'work_title',
            'submittal', 'document' => 'title', 'purchase' => 'note', default => null,
        };
        if ($search !== '' && $column) {
            $q->where($column, 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        $models = $q->limit(101)->get();
        $items = [];
        foreach ($models->take(100) as $model) {
            try {
                $items[] = $this->resolve($drawing, $type, $model->id);
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            } catch (ModelNotFoundException) {
            }
        }

        return ['items' => $items, 'next' => $models->count() > 100 ? $models[99]->id : null];
    }

    public function resolve(CadDrawing $drawing, string $type, int $id): array
    {
        $model = $this->query($drawing, $type)->findOrFail($id);
        $base = ['type' => $type, 'id' => $id];
        $home = '/?site='.$drawing->site_id;
        if ($type === 'section') {
            return $base + ['title' => trim($model->code.' '.$model->name), 'status' => 'linked', 'url' => $home.'&view=section-drawings'];
        }
        if (in_array($type, ['boq', 'work_record'], true)) {
            $line = $type === 'boq' ? $model : $model->line;
            if (FieldClaimAccess::restricted()) {
                FieldClaimAccess::line($line->id);
                $row = $type === 'boq' ? ['unit' => $line->unit, 'status' => $line->status] : ['status' => $model->status, 'reportedQty' => $model->reported_qty, 'verifiedQty' => $model->verified_qty, 'workDate' => $model->work_date?->toDateString()];
            } else {
                $ledger = $this->ledgers[$line->project_contract_id] ??= app(ClaimEvidenceService::class)->getLedger($line->project_contract_id);
                abort_unless($ledger['success'] ?? false, 403);
                $row = collect($ledger[$type === 'boq' ? 'lines' : 'records'])->firstWhere('id', $id);
                abort_unless($row, 404);
            }

            return $base + ['title' => $line->description.($type === 'work_record' ? ' · '.$model->work_date?->toDateString() : ''),
                'status' => $row['status'] ?? 'linked', 'date' => $row['workDate'] ?? null,
                'quantities' => array_intersect_key($row, array_flip(['unit', 'reportedQty', 'verifiedQty', 'unallocatedQty', 'availableQty', 'unverifiedQty', 'stageQuantities'])),
                'contractId' => $line->project_contract_id, 'lineId' => $line->id, 'url' => $home.'&view=billing-admin'];
        }
        if ($type === 'wbs') {
            $model->load(['safetyWorkItems' => fn ($q) => $q->where('site_id', $drawing->site_id)]);

            return $base + ['title' => $model->wbs_code.' · '.$model->name, 'status' => $model->status, 'progress' => $model->effectiveProgress(),
                'date' => $model->planned_end?->toDateString(), 'plannedStart' => $model->planned_start?->toDateString(), 'hold' => $model->hold_point && ! $model->hold_released, 'url' => $home.'&view=wbs'];
        }
        if ($type === 'photo') {
            return $base + ['title' => ($model->caption ?: '현장 사진').' · '.$model->photo_date?->toDateString(), 'status' => 'evidence', 'date' => $model->photo_date?->toDateString(),
                'url' => '/wbs-api/photos/'.$id.'/file', 'thumbUrl' => '/wbs-api/photos/'.$id.'/thumb', 'sha256' => $model->original_sha256];
        }
        if ($type === 'daily_report') {
            return $base + ['title' => ($model->work_title ?: '작업 일지').' · '.$model->report_date?->toDateString(), 'status' => $model->field_status, 'date' => $model->report_date?->toDateString(), 'url' => $home.'&view=daily-report&date='.$model->report_date?->toDateString()];
        }
        if ($type === 'document') {
            return $base + ['title' => $model->displayTitle(), 'status' => $model->ai_status, 'revision' => $model->revision, 'sha256' => $model->sha256,
                'url' => route('document-intelligence.preview', $model, false), 'documentType' => $model->document_type];
        }
        if ($type === 'submittal') {
            $register = app(ProjectRegisterService::class)->listSubmittals($model->project_id, (string) $drawing->site_id);
            abort_unless(! isset($register['error']) && collect($register['rows'] ?? [])->contains('id', $id), 404);
            $documents = $model->documents()->visibleTo(auth()->user())->get()->map(fn ($doc) => ['id' => $doc->id, 'title' => $doc->displayTitle(), 'kind' => $doc->pivot->kind, 'url' => route('document-intelligence.preview', $doc, false)])->all();

            return $base + ['title' => $model->seq.' · '.$model->title, 'status' => $model->status, 'date' => $model->approved_on?->toDateString() ?: $model->planned_on?->toDateString(), 'documents' => $documents, 'url' => $home.'&view=submittals'];
        }
        if ($type === 'purchase') {
            $row = app(PurchaseRequestService::class)->detail($id)['request'];

            return $base + ['title' => $row['title'], 'status' => $row['status_label'], 'date' => $row['eta'] ?? $row['need_by'], 'lines' => $row['lines'], 'url' => $home.'&view=purchase-requests&request='.$id];
        }

        return $base + ['title' => '계약 변경 #'.$id, 'status' => $model->status, 'date' => $model->decided_on?->toDateString(), 'url' => $home.'&view=billing-admin'];
    }
}
