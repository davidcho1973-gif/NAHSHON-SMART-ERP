<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\CadLocation;
use App\Models\CadLocationLink;
use App\Models\CadPurchaseDraft;
use App\Models\CadTask;
use App\Models\User;
use App\Support\JobAccess;
use App\Support\PurchaseAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadWorkflowService
{
    public function __construct(private CadDrawingService $cad, private CadTargetService $targets) {}

    public function context(CadDrawing $drawing, int $revision): array
    {
        $this->cad->access(auth()->user(), $drawing);
        $source = $drawing->revisions()->where('revision', $revision)->firstOrFail();
        $marks = collect($source->markups ?? [])->keyBy('id');
        $locations = CadLocation::where('cad_drawing_id', $drawing->id)->with(['sourceRevision', 'links', 'tasks.assignee'])->get();
        $rows = $locations->map(function ($location) use ($drawing, $marks): array {
            $links = [];
            foreach ($location->links->whereNull('retired_at') as $link) {
                try {
                    $links[] = $this->targets->resolve($drawing, $link->target_type, $link->target_id) + ['linkId' => $link->id];
                } catch (HttpExceptionInterface $e) {
                    if (! in_array($e->getStatusCode(), [403, 404], true)) {
                        throw $e;
                    }
                } catch (ModelNotFoundException) {
                }
            }

            return ['id' => $location->id, 'markupId' => $location->markup_id, 'present' => $marks->has($location->markup_id),
                'sourceRevision' => $location->sourceRevision->revision,
                'url' => $this->url($location), 'links' => $links,
                'tasks' => $location->tasks->map(fn ($t) => $this->taskRow($t))->all()];
        })->all();
        $assignees = User::where('account_status', 'active')->orderBy('name')->get()->filter(fn ($u) => $this->cad->visibleTo($u, $drawing, true))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
        $drafts = CadPurchaseDraft::whereIn('cad_location_id', $locations->pluck('id'))->where('user_id', auth()->id())->latest()->get()->map(fn ($d) => $this->draftRow($d))->all();

        return ['locations' => $rows, 'assignees' => $assignees, 'canManage' => $this->cad->canManage(auth()->user()), 'canClose' => $this->canClose(),
            'canRequestPurchase' => PurchaseAccess::canRequest(auth()->user()), 'purchaseDrafts' => $drafts,
            'events' => DB::table('cad_workflow_events')->where('cad_drawing_id', $drawing->id)->orderByDesc('id')->limit(100)->get(['id', 'kind', 'user_id', 'created_at'])->all()];
    }

    /** A stable annotation UUID is anchored to its first saved revision. It is never remapped by proximity. */
    public function location(CadDrawing $drawing, int $revision, string $markupId): CadLocation
    {
        $this->cad->access(auth()->user(), $drawing, true);
        abort_unless((int) $drawing->revision === $revision, 409, '저장된 최신 개정에서 연결하세요.');
        $source = $drawing->revisions()->where('revision', $revision)->firstOrFail();
        abort_unless(collect($source->markups ?? [])->contains('id', $markupId), 422, '먼저 도면 표시를 저장하세요.');

        return CadLocation::firstOrCreate(['cad_drawing_id' => $drawing->id, 'markup_id' => $markupId],
            ['id' => (string) Str::uuid(), 'source_revision_id' => $source->id, 'created_by' => auth()->id(), 'created_at' => now()]);
    }

    public function event(CadDrawing $drawing, ?CadLocation $location, string $kind, array $payload, ?string $key = null): void
    {
        DB::table('cad_workflow_events')->insert(['request_key' => $key ?: (string) Str::uuid(), 'cad_drawing_id' => $drawing->id,
            'cad_location_id' => $location?->id, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'user_id' => auth()->id(), 'created_at' => now()]);
    }

    public function link(CadDrawing $drawing, array $data): array
    {
        $this->cad->access(auth()->user(), $drawing, true);

        return DB::transaction(function () use ($drawing, $data): array {
            $drawing = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $target = $this->targets->resolve($drawing, $data['type'], $data['targetId']);
            $location = $this->location($drawing, $data['revision'], $data['markupId']);
            $link = CadLocationLink::firstOrCreate(['cad_location_id' => $location->id, 'target_type' => $data['type'], 'target_id' => $data['targetId']],
                ['id' => (string) Str::uuid(), 'created_by' => auth()->id(), 'created_at' => now()]);
            if ($link->wasRecentlyCreated || $link->retired_at) {
                if ($link->retired_at) {
                    $link->update(['retired_at' => null]);
                }
                $this->event($drawing, $location, 'link', ['linkId' => $link->id, 'type' => $data['type'], 'targetId' => $data['targetId'], 'revision' => $data['revision']]);
            }

            return ['locationId' => $location->id, 'target' => $target + ['linkId' => $link->id]];
        });
    }

    public function retire(CadDrawing $drawing, CadLocationLink $link): void
    {
        $this->cad->access(auth()->user(), $drawing, true);
        abort_unless((int) $link->location->cad_drawing_id === (int) $drawing->id, 404);
        DB::transaction(function () use ($drawing, $link): void {
            $link = CadLocationLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->retired_at) {
                return;
            }
            $link->update(['retired_at' => now()]);
            $this->event($drawing, $link->location, 'unlink', ['linkId' => $link->id]);
        });
    }

    public function backlinks(CadDrawing $drawing, string $type, int $targetId): array
    {
        $this->targets->resolve($drawing, $type, $targetId);
        $links = CadLocationLink::where('target_type', $type)->where('target_id', $targetId)->whereNull('retired_at')->with('location.drawing.site', 'location.sourceRevision')->get();

        return $links->filter(fn ($l) => (int) $l->location->drawing->site_id === (int) $drawing->site_id && $this->cad->visibleTo(auth()->user(), $l->location->drawing))
            ->map(fn ($l) => ['drawingId' => $l->location->cad_drawing_id, 'title' => $l->location->drawing->title, 'revision' => $l->location->sourceRevision->revision, 'markupId' => $l->location->markup_id, 'url' => $this->url($l->location)])->values()->all();
    }

    public function url(CadLocation $location): string
    {
        return '/cad?drawing_id='.$location->cad_drawing_id.'&revision='.$location->sourceRevision->revision.'&markup_id='.rawurlencode($location->markup_id);
    }

    public function createTask(CadDrawing $drawing, array $input): array
    {
        $data = Validator::make($input, ['id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'markupId' => 'required|string|max:80',
            'kind' => ['required', Rule::in(['design_question', 'inspection', 'corrective'])], 'title' => 'required|string|max:255', 'details' => 'required|string|max:10000',
            'assignedUserId' => 'required|integer|min:1', 'dueOn' => 'required|date_format:Y-m-d'])->validate();
        $this->cad->access(auth()->user(), $drawing, true);

        return DB::transaction(function () use ($drawing, $data): array {
            $drawing = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $assignee = User::findOrFail($data['assignedUserId']);
            abort_unless($this->cad->visibleTo($assignee, $drawing, true), 422, '현장 업무 및 원본 문서 권한이 있는 담당자를 선택하세요.');
            $location = $this->location($drawing, $data['revision'], $data['markupId']);
            $values = ['cad_location_id' => $location->id, 'kind' => $data['kind'], 'title' => trim($data['title']), 'details' => trim($data['details']), 'assigned_user_id' => $assignee->id, 'due_on' => $data['dueOn']];
            $task = CadTask::find($data['id']);
            if ($task) {
                abort_unless((int) $task->created_by === (int) auth()->id() && $task->cad_location_id === $location->id && $task->title === $values['title'] && $task->details === $values['details'] && $task->kind === $values['kind'] && (int) $task->assigned_user_id === (int) $assignee->id && $task->due_on->toDateString() === $data['dueOn'], 409, '처리번호가 다른 작업에 사용되었습니다.');
            } else {
                $task = CadTask::create($values + ['id' => $data['id'], 'created_by' => auth()->id(), 'status' => 'open', 'version' => 1]);
                $this->event($drawing, $location, 'task_created', ['taskId' => $task->id, 'kind' => $task->kind]);
            }

            return $this->taskRow($task);
        });
    }

    public function updateTask(CadDrawing $drawing, CadTask $task, array $input): array
    {
        $this->cad->access(auth()->user(), $drawing);
        abort_unless($this->cad->canManage(auth()->user()) || $this->canClose(), 403);
        abort_unless((int) $task->location->cad_drawing_id === (int) $drawing->id, 404);
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'requestKey' => 'required|uuid',
            'status' => ['required', Rule::in(['open', 'in_progress', 'submitted', 'closed'])], 'resolution' => 'nullable|string|max:10000',
            'evidence' => 'present|array|max:20', 'evidence.*' => 'required|array:type,id', 'evidence.*.type' => ['required', Rule::in(['photo', 'document', 'submittal', 'contract_change'])], 'evidence.*.id' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($drawing, $task, $data): array {
            $task = CadTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = DB::table('cad_workflow_events')->where('request_key', $data['requestKey'])->first();
            if ($prior) {
                $payload = json_decode($prior->payload, true);
                abort_unless((int) $prior->user_id === (int) auth()->id() && ($payload['taskId'] ?? null) === $task->id && hash_equals($payload['fingerprint'] ?? '', $fingerprint), 409);

                return $this->taskRow($task);
            }
            abort_unless($task->version === (int) $data['version'], 409, '다른 사용자가 작업을 변경했습니다. 다시 열어 확인하세요.');
            $transitions = ['open' => ['in_progress', 'submitted'], 'in_progress' => ['open', 'submitted'], 'submitted' => ['in_progress', 'closed'], 'closed' => ['open']];
            abort_unless(in_array($data['status'], $transitions[$task->status], true), 422, '작업 상태의 순서를 확인하세요.');
            abort_unless((int) $task->assigned_user_id === (int) auth()->id() || $this->canClose(), 403, '담당자 또는 검토자가 처리할 수 있습니다.');
            if ($data['status'] === 'closed' || $task->status === 'closed') {
                abort_unless($this->canClose(), 403, '검토 권한이 필요합니다.');
            }
            $evidence = [];
            foreach ($data['evidence'] as $ref) {
                $evidence[] = $this->targets->resolve($drawing, $ref['type'], $ref['id']);
            }
            if (in_array($data['status'], ['submitted', 'closed'], true)) {
                abort_unless(filled($data['resolution'] ?? null), 422, '처리 결과 또는 답변을 입력하세요.');
                if ($task->kind !== 'design_question') {
                    abort_unless(collect($evidence)->contains(fn ($e) => in_array($e['type'], ['photo', 'document'], true)), 422, '검측·시정 완료 근거 사진 또는 문서를 연결하세요.');
                }
            }
            $from = $task->status;
            $task->update(['status' => $data['status'], 'resolution' => $data['resolution'] ?? null, 'evidence' => $evidence, 'version' => $task->version + 1,
                'closed_by' => $data['status'] === 'closed' ? auth()->id() : null, 'closed_at' => $data['status'] === 'closed' ? now() : null]);
            $this->event($drawing, $task->location, 'task_status', ['taskId' => $task->id, 'from' => $from, 'to' => $task->status, 'fingerprint' => $fingerprint, 'resolution' => $task->resolution, 'evidence' => $evidence], $data['requestKey']);

            return $this->taskRow($task);
        });
    }

    public function canClose(): bool
    {
        return JobAccess::managed(auth()->user()) ? (JobAccess::can(auth()->user(), 'progress', 'approve') || JobAccess::can(auth()->user(), 'reports', 'approve')) : app(SectionDrawingService::class)->canManage(auth()->user());
    }

    public function taskRow(CadTask $task): array
    {
        $evidence = [];
        foreach ($task->evidence ?? [] as $ref) {
            try {
                $this->targets->resolve($task->location->drawing, $ref['type'], $ref['id']);
                $evidence[] = $ref;
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            } catch (ModelNotFoundException) {
            }
        }

        return ['id' => $task->id, 'kind' => $task->kind, 'title' => $task->title, 'details' => $task->details, 'status' => $task->status, 'version' => $task->version,
            'assigneeId' => $task->assigned_user_id, 'assignee' => $task->assignee?->name, 'dueOn' => $task->due_on->toDateString(), 'resolution' => $task->resolution,
            'evidence' => $evidence, 'closedBy' => $task->closed_by, 'closedAt' => $task->closed_at?->toIso8601String()];
    }

    public function draftRow(CadPurchaseDraft $draft): array
    {
        return ['id' => $draft->id, 'locationId' => $draft->cad_location_id, 'version' => $draft->version, 'content' => $draft->content, 'requestId' => $draft->purchase_request_id];
    }
}
