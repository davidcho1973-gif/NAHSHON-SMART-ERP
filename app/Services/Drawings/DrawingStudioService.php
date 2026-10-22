<?php

namespace App\Services\Drawings;

use App\Models\DrawingSheet;
use App\Models\User;
use App\Services\Finance\ClaimWriteReceipt;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** CAD overlays belong to a specific PDF page/revision, never to earned quantities. */
class DrawingStudioService
{
    public function canEdit(User $user): bool
    {
        return JobAccess::managed($user) ? JobAccess::can($user, 'progress', 'edit') : app(SectionDrawingService::class)->canManage($user);
    }

    public function access(User $user, DrawingSheet $sheet, bool $write = false): void
    {
        $drawings = app(SectionDrawingService::class);
        abort_unless($write ? $this->canEdit($user) : $drawings->canView($user), 403);
        abort_if(JobAccess::managed($user) && ! JobAccess::can($user, 'progress', $write ? 'edit' : 'view'), 403);
        abort_unless($sheet->site && AiInformationAccess::canUseSite($user, $sheet->site), 404);
        abort_unless($drawings->findDocument($user, $sheet->intelligent_document_id), 404);
    }

    public function read(DrawingSheet $sheet): array
    {
        $row = DB::table('drawing_studios')->where('drawing_sheet_id', $sheet->id)->first();

        return ['revision' => $row?->revision ?? 0, 'document' => $row ? json_decode($row->document, true) : [
            'version' => 1, 'unit' => 'ft', 'precision' => 2,
            'layers' => [['id' => 'default', 'name' => '작도·주석', 'visible' => true, 'locked' => false]], 'entities' => [],
        ]];
    }

    public function validateDocument(array $input): array
    {
        $v = Validator::make($input, [
            'version' => ['required', 'integer', 'in:1'],
            'unit' => ['required', Rule::in(['mm', 'm', 'ft', 'ft-in'])],
            'precision' => ['required', 'integer', 'between:0,4'],
            'layers' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'layers.*' => ['array:id,name,visible,locked'],
            'layers.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'layers.*.name' => ['required', 'string', 'max:80'],
            'layers.*.visible' => ['required', 'boolean'], 'layers.*.locked' => ['required', 'boolean'],
            'entities' => ['present', 'array', 'list', 'max:2000'],
            'entities.*' => ['array:id,type,layer,points,text,color,fill,width,opacity,dash,fontSize,holes,group,symbol,dimensionOffset,calibration'],
            'entities.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'entities.*.type' => ['required', Rule::in(['line', 'polyline', 'polygon', 'rect', 'ellipse', 'cloud', 'arrow', 'text', 'pen', 'highlight', 'dimension', 'angle', 'symbol'])],
            'entities.*.layer' => ['required', 'string', Rule::in(array_column(is_array($input['layers'] ?? null) ? $input['layers'] : [], 'id'))],
            'entities.*.points' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'entities.*.points.*' => ['required', 'array', 'list', 'size:2'],
            'entities.*.points.*.*' => ['required', 'numeric', 'between:0,1'],
            'entities.*.text' => ['present', 'nullable', 'string', 'max:2000'],
            'entities.*.color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'entities.*.fill' => ['required', 'regex:/^(none|#[a-fA-F0-9]{6})$/'],
            'entities.*.width' => ['required', 'numeric', 'between:0.25,20'],
            'entities.*.opacity' => ['required', 'numeric', 'between:0.05,1'],
            'entities.*.dash' => ['required', Rule::in(['solid', 'dash', 'dot'])],
            'entities.*.fontSize' => ['required', 'numeric', 'between:6,72'],
            'entities.*.group' => ['sometimes', 'nullable', 'string', 'max:64'],
            'entities.*.symbol' => ['sometimes', 'string', 'max:8'],
            'entities.*.dimensionOffset' => ['sometimes', 'numeric', 'between:-500,500'],
            'entities.*.holes' => ['sometimes', 'array', 'list', 'max:30'],
            'entities.*.holes.*' => ['array', 'list', 'min:3', 'max:1000'],
            'entities.*.holes.*.*' => ['array', 'list', 'size:2'],
            'entities.*.holes.*.*.*' => ['numeric', 'between:0,1'],
            'entities.*.calibration' => ['sometimes', 'array:feetPerPoint,widthPt,heightPt,label'],
            'entities.*.calibration.feetPerPoint' => ['required_with:entities.*.calibration', 'numeric', 'gt:0', 'max:100000'],
            'entities.*.calibration.widthPt' => ['required_with:entities.*.calibration', 'numeric', 'gt:0', 'max:100000'],
            'entities.*.calibration.heightPt' => ['required_with:entities.*.calibration', 'numeric', 'gt:0', 'max:100000'],
            'entities.*.calibration.label' => ['sometimes', 'nullable', 'string', 'max:200'],
            'views' => ['sometimes', 'array', 'list', 'max:30'],
            'views.*' => ['array:id,name,x,y,zoom,layers'],
            'views.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'views.*.name' => ['required', 'string', 'max:80'],
            'views.*.x' => ['required', 'numeric', 'between:-2,3'],
            'views.*.y' => ['required', 'numeric', 'between:-2,3'],
            'views.*.zoom' => ['required', 'numeric', 'between:0.05,30'],
            'views.*.layers' => ['present', 'array', 'list', 'max:30'],
            'views.*.layers.*' => ['string', Rule::in(array_column(is_array($input['layers'] ?? null) ? $input['layers'] : [], 'id'))],
            'blocks' => ['sometimes', 'array', 'list', 'max:30'],
            'blocks.*' => ['array:id,name,entities'],
            'blocks.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'blocks.*.name' => ['required', 'string', 'max:80'],
            'blocks.*.entities' => ['required', 'array', 'list', 'min:1', 'max:100'],
        ])->validate();
        $total = 0;
        foreach ($v['entities'] as &$entity) {
            $entity['text'] ??= '';
            $count = count($entity['points']);
            $total += $count;
            $valid = match ($entity['type']) {
                'text' => $count === 1 && trim($entity['text']) !== '',
                'symbol' => $count === 1,
                'line', 'rect', 'ellipse', 'cloud', 'arrow', 'dimension' => $count === 2,
                'angle' => $count === 3,
                'polygon' => $count >= 3,
                default => $count >= 2,
            };
            foreach ($entity['holes'] ?? [] as $hole) {
                $total += count($hole);
            }
            if (! $valid || $total > 30000 || (! empty($entity['holes']) && ! in_array($entity['type'], ['polygon', 'rect', 'ellipse']))) {
                throw ValidationException::withMessages(['document' => '도형의 점 개수 또는 전체 크기가 올바르지 않습니다.']);
            }
            DrawingGeometry::validate($entity);
        }
        unset($entity);
        foreach ($v['blocks'] ?? [] as $i => $block) {
            $validated = $this->validateDocument(array_merge(array_intersect_key($v, array_flip(['version', 'unit', 'precision', 'layers'])), ['entities' => $block['entities']]));
            $v['blocks'][$i]['entities'] = $validated['entities'];
            foreach ($validated['entities'] as $entity) {
                $total += count($entity['points']) + array_sum(array_map('count', $entity['holes'] ?? []));
            }
        }
        if ($total > 30000) {
            throw ValidationException::withMessages(['document' => '도형과 부품의 전체 점은 30,000개까지 저장할 수 있습니다.']);
        }

        return $v;
    }

    public function save(User $user, DrawingSheet $sheet, int $revision, array $document, ?string $requestId = null): array
    {
        $this->access($user, $sheet, true);
        $document = $this->validateDocument($document);

        return DB::transaction(function () use ($user, $sheet, $revision, $document, $requestId): array {
            // Lock the parent even before the first overlay exists: two initial saves cannot both win.
            DrawingSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $input = compact('revision', 'document', 'requestId');
            if ($replay = ClaimWriteReceipt::replay($input, 'drawing-overlay', $sheet->id)) {
                return $replay;
            }
            $current = $this->read($sheet);
            abort_if($current['revision'] !== $revision, 409, '다른 창에서 도면을 수정했습니다. 작업 파일을 내려받고 최신 내용을 다시 여세요.');
            $next = $revision + 1;
            $json = json_encode($document, JSON_THROW_ON_ERROR);
            DB::table('drawing_studios')->updateOrInsert(['drawing_sheet_id' => $sheet->id], [
                'site_id' => $sheet->site_id, 'revision' => $next, 'document' => $json,
                'updated_by' => $user->id, 'updated_at' => now(),
            ] + ($revision === 0 ? ['created_at' => now()] : []));
            $id = DB::table('drawing_studios')->where('drawing_sheet_id', $sheet->id)->value('id');
            DB::table('drawing_studio_revisions')->insert(['drawing_studio_id' => $id, 'revision' => $next,
                'document' => $json, 'created_by' => $user->id, 'created_at' => now()]);

            return ClaimWriteReceipt::remember($input, 'drawing-overlay', $sheet->id, ['success' => true, 'revision' => $next]);
        });
    }
}
