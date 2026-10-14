<?php

namespace App\Services\Documents;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Jobs\MatchDocumentEvidenceBatch;
use App\Models\ClaimEvidenceInboxItem;
use App\Models\ContractBoqLine;
use App\Models\DocumentEvidenceBatch;
use App\Models\DocumentEvidenceBatchItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\User;
use App\Services\Admin\BillingAdminService;
use App\Support\JobAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Manual intake: one canonical original, one explicit start, one in-flight file per batch. */
class DocumentEvidenceBatchService
{
    public function options(User $actor): array
    {
        $this->authorize($actor);
        $projects = Project::query()->with('site')->orderBy('name')->get()->filter(function ($project) use ($actor) {
            try {
                $this->project($actor, $project->id);

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
        $rows = $projects->map(fn ($project) => [
            'id' => $project->id, 'name' => $project->name, 'siteId' => $project->site_id,
            'siteName' => $project->site?->name, 'companyId' => $project->company_id,
            'contracts' => $this->contracts($project->id)->map(fn ($contract) => ['id' => $contract->id, 'label' => $contract->title ?: $contract->contract_number])->values()->all(),
        ])->values()->all();
        $batches = DocumentEvidenceBatch::whereIn('project_id', $projects->pluck('id'))->latest('id')->limit(30)->get()
            ->filter(function ($batch) use ($actor) {
                try {
                    $this->accessible($actor, $batch->id);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->map(fn ($batch) => $this->summary($batch))->all();

        return ['success' => true, 'projects' => $rows, 'batches' => $batches];
    }

    public function create(User $actor, int $projectId, ?int $contractId, ?string $sessionKey = null): DocumentEvidenceBatch
    {
        $project = $this->project($actor, $projectId);
        $contracts = $this->contracts($projectId);
        if ($contractId && ! $contracts->contains('id', $contractId)) {
            throw ValidationException::withMessages(['contract_id' => '선택한 프로젝트의 기성 공정이 등록된 계약을 선택해 주세요.']);
        }
        $contractId ??= $contracts->count() === 1 ? (int) $contracts->first()->id : null;
        $attributes = [
            'company_id' => $project->company_id, 'site_id' => $project->site_id, 'project_id' => $projectId,
            'project_contract_id' => $contractId, 'created_by' => $actor->id, 'status' => 'draft', 'upload_session_key' => $sessionKey,
        ];
        $batch = $sessionKey ? DocumentEvidenceBatch::firstOrCreate(['created_by' => $actor->id, 'upload_session_key' => $sessionKey], $attributes) : DocumentEvidenceBatch::create($attributes);
        abort_unless((int) $batch->project_id === $projectId && (int) $batch->project_contract_id === (int) $contractId, 409);

        return $batch;
    }

    public function upload(User $actor, int $id, UploadedFile $file, string $uploadKey): array
    {
        $batch = $this->accessible($actor, $id);
        $sha = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($batch, $actor, $file, $uploadKey, $sha) {
            $batch = DocumentEvidenceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $existing = $batch->items()->where('upload_key', $uploadKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->original_sha256, $sha), 409, '같은 업로드 요청에 다른 파일을 사용할 수 없습니다.');

                return ['success' => true, 'item' => $this->itemRow($existing), 'batch' => $this->summary($batch)];
            }
            abort_unless($batch->status === 'draft', 409, '처리를 시작한 묶음에는 파일을 추가할 수 없습니다. 새로 접수해 주세요.');
            abort_if($batch->items()->count() >= 1000, 422, '한 번에 최대 1,000개까지 접수할 수 있습니다.');
            $result = app(DocumentIntake::class)->ingest($file, [
                'company_id' => $batch->company_id, 'site_id' => $batch->site_id, 'project_id' => $batch->project_id,
            ], ['uploaded_by' => $actor->id, 'source' => 'evidence_batch', 'project_contract_id' => $batch->project_contract_id]);
            if (! $result['document']) {
                throw ValidationException::withMessages(['file' => $result['reason'] ?: '원본 저장에 실패했습니다.']);
            }
            $document = $result['document'];
            abort_unless(IntelligentDocument::visibleTo($actor)->whereKey($document->id)->exists(), 403);
            $duplicate = $result['status'] === 'duplicate';
            $adopt = $duplicate && $document->source === 'evidence_batch' && $document->ai_status === 'uploaded'
                && (int) $document->project_contract_id === (int) $batch->project_contract_id;
            $item = $batch->items()->create([
                'document_id' => $document->id, 'upload_key' => $uploadKey,
                'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'original_sha256' => $sha, 'status' => $duplicate && ! $adopt ? 'duplicate' : 'waiting', 'is_duplicate' => $duplicate,
            ]);

            return ['success' => true, 'item' => $this->itemRow($item), 'batch' => $this->summary($batch)];
        });
    }

    public function start(User $actor, int $id, bool $retryFailed = false, bool $rematch = false, ?int $contractId = null): array
    {
        $batch = $this->accessible($actor, $id);
        DB::transaction(function () use ($batch, $actor, $retryFailed, $rematch, $contractId) {
            $batch = DocumentEvidenceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($contractId) {
                abort_if($batch->project_contract_id && (int) $batch->project_contract_id !== $contractId, 422, '이미 지정한 계약은 변경할 수 없습니다.');
                abort_unless($this->contracts($batch->project_id)->contains('id', $contractId), 422, '같은 프로젝트의 접근 가능한 기성 계약을 선택해 주세요.');
            }
            if (in_array($batch->status, ['running', 'matching'], true)) {
                return;
            }
            abort_unless($batch->items()->exists(), 422, '먼저 파일을 올려 주세요.');
            if ($batch->status !== 'draft' && ! $retryFailed && ! $rematch) {
                return;
            }
            if (! $batch->project_contract_id) {
                $contracts = $this->contracts($batch->project_id);
                $contractId ??= $contracts->count() === 1 ? (int) $contracts->first()->id : null;
                if ($contractId) {
                    $batch->update(['project_contract_id' => $contractId]);
                    // Bind only this intake's new sources. A canonical duplicate or a
                    // previously linked source belongs to its existing review history.
                    IntelligentDocument::whereIn('id', $batch->items()->where('is_duplicate', false)->select('document_id'))
                        ->where('source', 'evidence_batch')->whereNull('project_contract_id')
                        ->where('project_id', $batch->project_id)
                        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('claim_evidence_inbox_items as linked')
                            ->where('linked.source_type', 'document')->whereColumn('linked.source_id', 'intelligent_documents.id')
                            ->where('linked.project_contract_id', '!=', $contractId)->whereIn('linked.status', ['linked', 'common']))
                        ->update(['project_contract_id' => $contractId]);
                }
            }
            if ($retryFailed) {
                $batch->items()->where('status', 'failed')->update(['status' => 'waiting', 'error' => null]);
            }
            if ($rematch) {
                $batch->items()->where('status', 'review_required')->where('is_duplicate', true)
                    ->whereHas('document', fn ($q) => $q->where('ai_status', 'ready')
                        ->where(fn ($scope) => $scope->whereNull('project_contract_id')->orWhere('project_contract_id', $batch->project_contract_id)))
                    ->update(['status' => 'duplicate', 'error' => null]);
            }
            $batch->update(['status' => 'running', 'run_token' => (string) Str::uuid(), 'active_item_id' => null, 'started_by' => $actor->id,
                'match_cursor' => 0, 'match_inflight' => false, 'recovery_count' => 0, 'error' => null, 'started_at' => now(), 'finished_at' => null]);
            DB::afterCommit(fn () => $this->next($batch->id, $batch->run_token));
        });

        return ['success' => true, 'batch' => $this->summary($batch->fresh())];
    }

    public function show(User $actor, int $id, int $page = 1, bool $reviewOnly = false): array
    {
        $batch = $this->accessible($actor, $id);
        $items = $batch->items()->whereHas('document', fn ($q) => $q->visibleTo($actor))
            ->when($reviewOnly, fn ($q) => $q->where(fn ($review) => $review->whereIn('status', ['failed', 'review_required'])
                ->orWhereHas('document', fn ($doc) => $doc->whereIn('ai_status', ['review_required', 'failed']))
                ->when($batch->project_contract_id && app(BillingAdminService::class)->canView($actor), fn ($q) => $q->orWhereIn('document_id',
                    ClaimEvidenceInboxItem::where('project_contract_id', $batch->project_contract_id)->where('source_type', 'document')->where('status', 'review')->select('source_id')))))
            ->with('document')->orderBy('id')->paginate(25, ['*'], 'page', max(1, $page));

        return ['success' => true, 'batch' => $this->summary($batch),
            'rows' => $items->getCollection()->map(fn ($item) => $this->itemRow($item))->all(),
            'pagination' => ['page' => $items->currentPage(), 'lastPage' => $items->lastPage(), 'total' => $items->total()]];
    }

    public function accessible(User $actor, int $id): DocumentEvidenceBatch
    {
        $batch = DocumentEvidenceBatch::findOrFail($id);
        $project = $this->project($actor, (int) $batch->project_id);
        abort_unless((int) $project->company_id === (int) $batch->company_id && (int) $project->site_id === (int) $batch->site_id, 403);
        if ($batch->project_contract_id) {
            $contract = app(BillingAdminService::class)->findAccessibleContract($batch->project_contract_id);
            abort_unless($contract && (int) $contract->project_id === (int) $batch->project_id
                && (int) $contract->company_id === (int) $batch->company_id && (int) $contract->site_id === (int) $batch->site_id, 403);
        }

        return $batch;
    }

    /** A queued job restores its initiating user and revalidates today's scope before AI use. */
    public function actorForJob(int $batchId, string $token, ?int $documentId = null): User
    {
        $batch = DocumentEvidenceBatch::findOrFail($batchId);
        abort_unless(hash_equals((string) $batch->run_token, $token), 409);
        $actor = User::findOrFail($batch->started_by ?: $batch->created_by);
        $project = $this->project($actor, $batch->project_id);
        abort_unless((int) $project->company_id === (int) $batch->company_id && (int) $project->site_id === (int) $batch->site_id, 403);
        if ($documentId !== null) {
            $item = $batch->items()->whereKey($batch->active_item_id)->where('document_id', $documentId)->firstOrFail();
            $document = IntelligentDocument::visibleTo($actor)->findOrFail($item->document_id);
            abort_unless($document->source === 'evidence_batch'
                && (int) $document->project_id === (int) $batch->project_id
                && (int) $document->site_id === (int) $batch->site_id
                && (int) $document->company_id === (int) $batch->company_id
                && (int) $document->project_contract_id === (int) $batch->project_contract_id
                && hash_equals($item->original_sha256, $document->sha256), 403);
        }

        return $actor;
    }

    public function actorForDocument(IntelligentDocument $document, ?int $actorId): User
    {
        $actor = User::findOrFail($actorId ?: $document->uploaded_by);
        $project = $this->project($actor, (int) $document->project_id);
        abort_unless((int) $document->company_id === (int) $project->company_id && (int) $document->site_id === (int) $project->site_id, 403);
        abort_unless(IntelligentDocument::visibleTo($actor)->whereKey($document->id)->exists(), 403);

        return $actor;
    }

    public function next(int $batchId, string $token): void
    {
        try {
            $this->queueNext($batchId, $token);
        } catch (\Throwable $e) {
            report($e);
            $this->fail($batchId, $token, '서버 작업을 예약하지 못했습니다. 원본은 보존했습니다. 다시 처리해 주세요.');
        }
    }

    private function queueNext(int $batchId, string $token): void
    {
        DB::transaction(function () use ($batchId, $token) {
            $batch = DocumentEvidenceBatch::whereKey($batchId)->lockForUpdate()->first();
            if (! $batch || $batch->run_token !== $token || $batch->status !== 'running' || $batch->active_item_id) {
                return;
            }
            $item = $batch->items()->where('status', 'waiting')->orderBy('id')->first();
            if (! $item) {
                $batch->update(['status' => $batch->project_contract_id ? 'matching' : 'done', 'finished_at' => $batch->project_contract_id ? null : now()]);
                if ($batch->project_contract_id) {
                    MatchDocumentEvidenceBatch::dispatch($batch->id, $token, 0)->afterCommit();
                }

                return;
            }
            $document = IntelligentDocument::whereKey($item->document_id)->lockForUpdate()->firstOrFail();
            if ((int) $document->project_contract_id !== (int) $batch->project_contract_id) {
                // A previously adopted draft can gain a different contract before
                // Start. Review the reference without reassigning its canonical source.
                $item->update(['status' => 'review_required', 'error' => '기존 원본의 계약이 이 접수와 달라 자동 분석하지 않았습니다. 문서 상세에서 원본을 분석·확인한 뒤 공정 다시 연결을 눌러 주세요.']);
                DB::afterCommit(fn () => $this->next($batchId, $token));

                return;
            }
            if ($document->source !== 'evidence_batch' || ! in_array($document->ai_status, ['uploaded', 'failed'], true)) {
                $item->update(['status' => 'duplicate']);
                DB::afterCommit(fn () => $this->next($batchId, $token));

                return;
            }
            $payload = (array) $document->ai_payload;
            unset($payload['analysis_run_token'], $payload['stuck_requeues']);
            $document->update(['ai_status' => 'queued', 'ai_error' => null, 'ai_payload' => $payload]);
            $item->update(['status' => 'queued', 'error' => null]);
            $batch->update(['active_item_id' => $item->id]);
            AnalyzeIntelligentDocumentJob::dispatch($document->id, $batchId, $token)->afterCommit();
        });
    }

    public function completed(int $batchId, string $token, int $documentId, ?string $error = null): void
    {
        DB::transaction(function () use ($batchId, $token, $documentId, $error) {
            $batch = DocumentEvidenceBatch::whereKey($batchId)->lockForUpdate()->first();
            if (! $batch || $batch->run_token !== $token || $batch->status !== 'running') {
                return;
            }
            $item = $batch->items()->whereKey($batch->active_item_id)->where('document_id', $documentId)->first();
            if (! $item) {
                return;
            }
            $document = IntelligentDocument::find($documentId);
            if (! $error && $document && in_array($document->ai_status, ['queued', 'analyzing'], true)) {
                return; // Duplicate/stale jobs cannot advance the actual owner's run.
            }
            $failed = $error || ! $document || $document->ai_status === 'failed';
            $item->update(['status' => $failed ? 'failed' : 'done', 'error' => $error ?: $document?->ai_error]);
            $batch->update(['active_item_id' => null]);
            DB::afterCommit(fn () => $this->next($batchId, $token));
        });
    }

    public function fail(int $batchId, string $token, string $error): void
    {
        DB::transaction(function () use ($batchId, $token, $error) {
            $batch = DocumentEvidenceBatch::whereKey($batchId)->where('run_token', $token)->lockForUpdate()->first();
            if (! $batch || ! in_array($batch->status, ['running', 'matching'], true)) {
                return;
            }
            $item = $batch->active_item_id ? $batch->items()->whereKey($batch->active_item_id)->first() : null;
            if ($item) {
                $item->update(['status' => 'failed', 'error' => $error]);
                IntelligentDocument::whereKey($item->document_id)->where('ai_status', 'queued')->update(['ai_status' => 'failed', 'ai_error' => $error]);
            }
            $batch->update(['status' => 'failed', 'active_item_id' => null, 'match_inflight' => false, 'error' => $error, 'finished_at' => now()]);
        });
    }

    /** Existing queue maintenance repairs only explicitly started batches, never draft uploads. */
    public function recoverStartedBatches(): void
    {
        $batches = DocumentEvidenceBatch::whereIn('status', ['running', 'matching'])
            ->where('updated_at', '<', now()->subMinutes(15)->toIso8601String())->orderBy('id')->limit(50)->get();
        foreach ($batches as $batch) {
            if ($batch->status === 'running' && $batch->active_item_id) {
                $item = $batch->items()->whereKey($batch->active_item_id)->first();
                if ($item && ! in_array($item->document?->ai_status, ['queued', 'analyzing'], true)) {
                    $this->completed($batch->id, $batch->run_token, $item->document_id);
                }

                continue;
            }
            $pending = DB::connection(config('queue.connections.document-analysis.connection'))
                ->table(config('queue.connections.document-analysis.table', 'jobs'))->where('queue', 'documents')
                ->whereRaw("payload::jsonb->>'displayName' = ?", [MatchDocumentEvidenceBatch::class])
                ->whereRaw("payload::jsonb->'data'->>'command' LIKE ?", ['%"batchId";i:'.$batch->id.';%'])->exists();
            if ($pending) {
                continue;
            }
            DB::transaction(function () use ($batch) {
                $current = DocumentEvidenceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($current->run_token !== $batch->run_token || $current->updated_at->gte(now()->subMinutes(15))) {
                    return;
                }
                if ($current->match_inflight || $current->recovery_count >= 1) {
                    $this->fail($current->id, $current->run_token, '작업이 중단되었습니다. 원본과 분석 결과는 보존했습니다. 다시 처리해 주세요.');

                    return;
                }
                $current->update(['recovery_count' => $current->recovery_count + 1]);
                if ($current->status === 'matching') {
                    MatchDocumentEvidenceBatch::dispatch($current->id, $current->run_token, $current->match_cursor)->afterCommit();
                } elseif ($current->status === 'running') {
                    DB::afterCommit(fn () => $this->next($current->id, $current->run_token));
                }
            });
        }
    }

    public function summary(DocumentEvidenceBatch $batch): array
    {
        $actor = Auth::user();
        $query = $batch->items()->join('intelligent_documents as d', 'd.id', '=', 'document_evidence_batch_items.document_id');
        if ($actor) {
            $query->whereIn('d.id', IntelligentDocument::visibleTo($actor)->select('id'));
        }
        $counts = array_fill_keys(['uploaded', 'queued', 'analyzing', 'ready', 'review_required', 'failed', 'duplicate'], 0);
        foreach ($query->selectRaw("CASE WHEN document_evidence_batch_items.status = 'review_required' THEN 'review_required' WHEN document_evidence_batch_items.status = 'duplicate' AND d.ai_status NOT IN ('uploaded','queued','analyzing','failed','review_required') THEN 'duplicate' WHEN document_evidence_batch_items.status = 'failed' THEN 'failed' ELSE d.ai_status END as phase, count(*) as aggregate")->groupBy('phase')->get() as $row) {
            $counts[$row->phase] = (int) $row->aggregate;
        }
        $claimCounts = array_fill_keys(['linked', 'review', 'common', 'ignored'], 0);
        if ($batch->project_contract_id && $actor && app(BillingAdminService::class)->canView($actor)) {
            $visible = IntelligentDocument::visibleTo($actor)->whereIn('id', $batch->items()->select('document_id'))->select('id');
            foreach (ClaimEvidenceInboxItem::where('project_contract_id', $batch->project_contract_id)->where('source_type', 'document')
                ->whereIn('source_id', $visible)->selectRaw('status, count(*) as aggregate')->groupBy('status')->get() as $row) {
                $claimCounts[$row->status] = (int) $row->aggregate;
            }
        }

        return ['id' => $batch->id, 'projectId' => $batch->project_id, 'contractId' => $batch->project_contract_id,
            'status' => $batch->status, 'error' => $batch->error, 'counts' => $counts, 'claimCounts' => $claimCounts, 'total' => array_sum($counts),
            'createdAt' => $batch->created_at?->toIso8601String(), 'finishedAt' => $batch->finished_at?->toIso8601String()];
    }

    private function itemRow(DocumentEvidenceBatchItem $item): array
    {
        $doc = $item->document;
        $batch = DocumentEvidenceBatch::find($item->batch_id);
        $claim = $batch?->project_contract_id && app(BillingAdminService::class)->canView()
            ? ClaimEvidenceInboxItem::where('project_contract_id', $batch->project_contract_id)->where('source_type', 'document')->where('source_id', $item->document_id)->first() : null;
        $processNames = $claim ? ContractBoqLine::where('project_contract_id', $batch->project_contract_id)->where('status', 'accepted')->whereIn('id', array_slice((array) $claim->line_ids, 0, 3))->pluck('description')->all() : [];

        return ['id' => $item->id, 'documentId' => $doc?->id, 'originalName' => $item->original_name,
            'status' => in_array($item->status, ['failed', 'review_required'], true) ? $item->status : ($item->status === 'duplicate' && $doc?->ai_status === 'ready' ? 'duplicate' : $doc?->ai_status),
            'duplicate' => (bool) $item->is_duplicate, 'claimStatus' => $claim?->status, 'claimReason' => $claim?->reason, 'processNames' => $processNames,
            'error' => $item->error ?: $doc?->ai_error, 'evidenceKind' => $doc?->document_type,
            'detailUrl' => '/?view=document-hub&document='.$item->document_id,
            'originalUrl' => '/document-hub/documents/'.$item->document_id.'/download'];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->account_status === 'active'
            && in_array($actor->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'], true)
            && (! JobAccess::managed($actor) || JobAccess::can($actor, 'documents', 'edit'))
            && app(BillingAdminService::class)->canManage($actor), 403);
    }

    private function project(User $actor, int $id): Project
    {
        $this->authorize($actor);
        $project = Project::findOrFail($id);
        abort_unless($project->company_id && $project->site_id, 422, '회사와 현장이 지정된 프로젝트를 선택해 주세요.');
        app(DocumentScope::class)->normalize(['company_id' => $project->company_id, 'site_id' => $project->site_id, 'project_id' => $id], $actor);

        return $project;
    }

    private function contracts(int $projectId)
    {
        $project = Project::findOrFail($projectId);

        return ProjectContract::where('project_id', $projectId)->where('company_id', $project->company_id)
            ->where('site_id', $project->site_id)->where('direction', 'receivable')
            ->whereIn('id', ContractBoqLine::where('status', 'accepted')->select('project_contract_id'))->get()
            ->filter(fn ($contract) => app(BillingAdminService::class)->findAccessibleContract($contract->id));
    }
}
