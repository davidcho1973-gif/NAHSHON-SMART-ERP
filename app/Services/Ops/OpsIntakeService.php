<?php

namespace App\Services\Ops;

use App\Http\Controllers\OpsPhotoController;
use App\Jobs\AnalyzeOpsIntakeJob;
use App\Models\FieldDrawing;
use App\Models\IntelligentDocument;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Admin\ProjectRegisterService;
use App\Services\Communication\DecisionReplyConnector;
use App\Services\IntegratedDocumentService;
use App\Services\Procurement\ProcurementService;
use App\Services\Wbs\WbsService;
use App\Support\ImageDownscale;
use App\Support\ImageParts;
use App\Support\SiteFromText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 현장 상황실 — 올라온 글을 판독해 "반영 제안"으로 바꾼다.
 *
 * 1단계: 판독 + 제안 생성/조회/무시.
 * 2단계: 제안을 실제 공정표(WbsService)·조달(ProcurementService)에 반영하고 되돌린다.
 */
class OpsIntakeService
{
    /** 이 값 미만이면 사람에게 되물어야 하는 제안으로 본다. */
    public const LOW_CONFIDENCE = 60;

    private const CATEGORIES = [
        'progress', 'plan', 'procurement', 'labor', 'expense', 'issue',
        // 검사·검측 일정 — 제출물 대장의 계획일로 간다(공정 계획인 plan 과 구분).
        'inspection',
        // 공정·자재·인원 어디에도 안 들어가는 것들 — 액션 아이템으로 간다.
        'request', 'approval', 'decision', 'todo',
        // 사무·관리자의 하루 — 일일보고는 모이는 곳이라 사무의 보고도 같은 장에 든다.
        // submittal 은 제출물 대장으로 간다. 나머지는 마감 보고서의 «사무 업무» 에 실린다.
        'submittal', 'billing', 'permit', 'hr', 'admin',
        'noise',
    ];

    public function __construct(
        private readonly OpsIntakeAnalyzer $analyzer,
        private readonly OpsLearningService $learning,
        private readonly OpsPhotoRouter $photoRouter,
        private readonly OpsModuleRouter $modules,
        private readonly IntegratedDocumentService $documents,
        private readonly TradeReportService $tradeReports,
    ) {}

    /**
     * 자유 형식 텍스트(카톡 붙여넣기 포함)를 판독해 제안을 저장한다.
     *
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @return array<string, mixed>
     */
    public function ingest(string $text, ?Site $site, ?int $userId = null, array $images = [], string $source = 'paste', ?int $messageId = null): array
    {
        $text = trim($text);
        $images = ImageParts::sanitize($images, ImageParts::MAX_IMAGES);
        if ($text === '' && $images === []) {
            return ['success' => false, 'error' => '판독할 내용이 없습니다.'];
        }

        $site = $this->resolveSite($site, $text);

        $activities = $this->activityContext($site);
        $purchases = $this->purchaseContext($site);
        $inspections = $this->inspectionContext($site);
        $submittals = $this->submittalContext($site);
        $today = Carbon::today()->toDateString();

        try {
            // 지금까지 축적된 현장 용어·오판 사례를 함께 넘긴다 — 쓸수록 정확해진다.
            $learned = $this->learning->promptBlock($site?->id);
            $raw = $this->analyzer->read($text, $activities->all(), $purchases->all(), $today, $images, $learned, [], $this->specContext($site)->all(), $inspections->all(), $submittals->all());
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'AI 판독에 실패했습니다: '.$e->getMessage()];
        }

        $validCodes = $activities->pluck('code')->filter()->all();
        $validPos = $purchases->pluck('po')->filter()->all();
        $validSeqs = $this->validSeqs($inspections, $submittals);

        // 붙여넣은 원문 전체를 근거로 보관한다 — 나중에 "왜 이렇게 반영됐지?"를 되짚을 수 있게.
        $batch = OpsIntakeBatch::create([
            'site_id' => $site?->id,
            'created_by_id' => $userId,
            'source' => $source,
            'communication_message_id' => $messageId,
            'raw_text' => $text,
            'image_count' => count($images),
        ]);

        // 그날 그 공종의 보고에 묶는다 — 반장에게 "어느 보고에 넣을까요" 를 묻지
        // 않는다. 그 답은 이미 알고 있다(올린 사람의 공종, 오늘 날짜).
        $this->tradeReports->attach($batch);

        $saved = [];
        foreach ($raw as $r) {
            $item = $this->persist($r, $site, $userId, $source, $messageId, $validCodes, $validPos, $text, $batch->id, $validSeqs);
            if ($item !== null) {
                $saved[] = $item;
            }
        }

        $items = collect($saved);
        $batch->update([
            'parsed_count' => $items->count(),
            'actionable_count' => $items->where('category', '!=', 'noise')->count(),
            'noise_count' => $items->where('category', 'noise')->count(),
        ]);

        return [
            'success' => true,
            'batchId' => $batch->id,
            'parsed' => $items->count(),
            'actionable' => $items->where('category', '!=', 'noise')->count(),
            'noise' => $items->where('category', 'noise')->count(),
            'needsInput' => $items->where('status', 'needs_input')->count(),
            'items' => $items->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 판독을 예약하고 즉시 돌아온다 — 실제 AI 호출은 응답을 보낸 뒤에 한다.
     *
     * 사진 여러 장을 비전 AI 로 읽으면 수십 초~수 분이 걸린다. 이걸 요청 안에서 기다리면
     * 게이트웨이가 먼저 끊어 504 가 난다(David 리포트). 그래서 원문만 먼저 저장하고 상태를
     * 'analyzing' 으로 두면, 프론트는 그 batchId 로 진행 상태를 폴링한다.
     * 요청은 짧게 끝나고 판독은 시간 제한 없이 돌 수 있다.
     *
     * @param  array<int, string>  $photoPaths  업로드해 둔 사진의 저장 경로
     * @return array<string, mixed>
     */
    public function queue(string $text, ?Site $site, ?int $userId = null, array $photoPaths = [], string $source = 'paste', ?int $messageId = null): array
    {
        $text = trim($text);
        $photoPaths = array_values(array_slice($photoPaths, 0, ImageParts::MAX_IMAGES));

        if ($text === '' && $photoPaths === []) {
            return ['success' => false, 'error' => '판독할 내용이 없습니다.'];
        }

        $site = $this->resolveSite($site, $text);

        $batch = OpsIntakeBatch::create([
            'site_id' => $site?->id,
            'created_by_id' => $userId,
            'source' => $source,
            'communication_message_id' => $messageId,
            'raw_text' => $text,
            'image_count' => count($photoPaths),
            'status' => 'analyzing',
            'photo_disk' => $photoPaths !== [] ? OpsPhotoController::disk() : null,
            'photo_paths' => $photoPaths ?: null,
        ]);

        $this->tradeReports->attach($batch);

        AnalyzeOpsIntakeJob::dispatch($batch->id)->afterResponse();

        return [
            'success' => true,
            'status' => 'analyzing',
            'batchId' => $batch->id,
            'imageCount' => count($photoPaths),
        ];
    }

    /**
     * 예약된 원문을 실제로 판독한다(백그라운드에서 호출).
     *
     * 여기는 게이트웨이 제한 밖이라 AI 호출 타임아웃을 넉넉히 올린다 — 한 번에 다 읽게 하는 편이
     * 짧게 끊고 여러 모델로 재시도하는 것보다 빠르고 정확하다.
     */
    public function analyze(int $batchId): void
    {
        $batch = OpsIntakeBatch::find($batchId);
        if (! $batch || $batch->status === 'done') {
            return;
        }

        config([
            'services.gemini.timeout' => max(300, (int) config('services.gemini.timeout')),
            'services.anthropic.timeout' => max(300, (int) config('services.anthropic.timeout')),
        ]);

        try {
            $site = $batch->site_id ? Site::find($batch->site_id) : null;
            $images = $this->loadPhotos($batch);
            $text = (string) $batch->raw_text;

            $activities = $this->activityContext($site);
            $purchases = $this->purchaseContext($site);
            $inspections = $this->inspectionContext($site);
            $submittals = $this->submittalContext($site);
            $specs = $this->specContext($site);
            $learned = $this->learning->promptBlock($site?->id);

            // 1단계: 사진이 무엇인지 먼저 가른다(영수증/납품서/시공/안전/출역명부).
            // 글이 비어 있어도 여기서 종류가 잡히므로 "사진만 올려도" 판독이 된다.
            $photoKinds = $images !== [] ? $this->photoRouter->classify($images) : [];

            // 2단계: 종류를 알려주고 본 판독을 돌린다.
            $raw = $this->analyzer->read(
                $text,
                $activities->all(),
                $purchases->all(),
                Carbon::today()->toDateString(),
                $images,
                $learned,
                $photoKinds,
                $specs->all(),
                $inspections->all(),
                $submittals->all(),
            );

            $validCodes = $activities->pluck('code')->filter()->all();
            $validPos = $purchases->pluck('po')->filter()->all();
            $validSeqs = $this->validSeqs($inspections, $submittals);

            $saved = [];
            $autoLabor = 0;
            $autoAction = 0;
            foreach ($raw as $r) {
                $item = $this->persist($r, $site, $batch->created_by_id, $batch->source, $batch->communication_message_id, $validCodes, $validPos, $text, $batch->id, $validSeqs);
                if ($item === null) {
                    continue;
                }
                $saved[] = $item;
                // 3단계: 인원 보고처럼 바로 반영해도 되는 것은 여기서 즉시 모듈로 보낸다.
                $routed = $this->modules->autoRoute($batch, $item);
                $autoLabor += $routed['labor'];
                $autoAction += $routed['action'];
            }

            $items = collect($saved);
            $batch->update([
                'parsed_count' => $items->count(),
                'actionable_count' => $items->where('category', '!=', 'noise')->count(),
                'noise_count' => $items->where('category', 'noise')->count(),
                'status' => 'done',
                'error' => null,
                'analyzed_at' => now(),
                'photo_kinds' => $photoKinds ?: null,
                'auto_applied' => $autoLabor + $autoAction,
            ]);

            $this->discardPhotos($batch, $photoKinds);

            // 제출한 뒤에 올린 사진이 이제야 읽혔다.
            //
            // 사진 판독은 몇 분씩 걸린다. 반장이 사진을 올리자마자 「오늘 보고 제출」을
            // 누르면, 제출 시점에는 아직 항목이 하나도 없어서 반영에서 통째로 빠진다.
            // 여기서 이어 넘기지 않으면 그 보고는 영원히 «확인 대기» 로 남고, 반장은
            // 올렸으니 됐다고 생각한다 — 아무도 틀렸다고 말해 주지 않는 상태가 된다.
            $this->reflectIfSubmitted($batch);
        } catch (\Throwable $e) {
            report($e);
            $batch->update(['status' => 'failed', 'error' => $e->getMessage(), 'analyzed_at' => now()]);

            // 판독이 실패해도 보고의 결과 줄은 다시 써야 한다. 안 그러면 제출 때 적힌
            // 「사진 판독 중 — 끝나면 이어서 반영됩니다」가 그대로 굳어, 반장은 폰에서
            // 오지 않을 것을 계속 기다린다.
            $this->reflectIfSubmitted($batch, restampOnly: true);
        }
    }

    /**
     * 판독이 끝난 기록이 <b>이미 제출된</b> 보고에 속하면 그 자리에서 반영을 이어 돌린다.
     *
     * TradeReportReflector 는 이 서비스를 쓰므로 생성자 주입을 하면 순환이 된다.
     * 필요한 순간에만 꺼내 쓴다.
     *
     * @param  bool  $restampOnly  판독 실패 뒤처리 — 반영은 하지 않고 결과 줄만 다시 센다.
     */
    public function reflectIfSubmitted(OpsIntakeBatch $batch, bool $restampOnly = false): void
    {
        try {
            $report = $batch->tradeReport;
            if (! $report || ! $report->isSubmitted()) {
                return;
            }

            $reflector = app(TradeReportReflector::class);
            $restampOnly ? $reflector->restamp($report) : $reflector->reflect($report);
        } catch (\Throwable $e) {
            report($e); // 이어붙이기 실패가 판독 결과를 잃게 만들면 안 된다.
        }
    }

    /**
     * 판독 진행 상태 — 프론트가 이걸 폴링한다. 요청 하나가 수십 ms 라 시간 제한에 걸리지 않는다.
     *
     * @return array<string, mixed>
     */
    public function job(int $batchId): array
    {
        $batch = OpsIntakeBatch::with('items')->find($batchId);
        if (! $batch) {
            return ['success' => false, 'error' => '판독 작업을 찾을 수 없습니다.'];
        }

        $status = (string) ($batch->status ?: 'done');
        $out = [
            'success' => true,
            'status' => $status,
            'batchId' => $batch->id,
            'imageCount' => (int) $batch->image_count,
            'elapsed' => (int) $batch->created_at->diffInSeconds(now()),
        ];

        if ($status === 'failed') {
            return $out + ['error' => (string) $batch->error];
        }
        if ($status !== 'done') {
            return $out;
        }

        $items = $batch->items;

        return $out + [
            'parsed' => $items->count(),
            'actionable' => $items->where('category', '!=', 'noise')->count(),
            'noise' => $items->where('category', 'noise')->count(),
            'needsInput' => $items->where('status', 'needs_input')->count(),
            // 사진만 올린 경우. 사진은 보고에 붙었지만 «무슨 일이 있었는지» 는
            // 아무도 말해 주지 않았다 — 그 사실을 그대로 알린다. 예전에는 AI 가
            // 사진을 보고 그럴듯한 이야기를 지어내 그 자리를 채웠다.
            'photoOnly' => trim((string) $batch->raw_text) === '' && (int) $batch->image_count > 0,
            'evidenceFiled' => (int) $batch->evidence_filed,
            'items' => $items->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 저장해 둔 사진을 읽어 비전 입력 형태로 만든다. 이때 서버가 크기를 줄인다 —
     * 그래서 사용자는 원본을 크기 제한 없이 올릴 수 있다.
     *
     * @return array<int, array{data: string, mime_type: string}>
     */
    private function loadPhotos(OpsIntakeBatch $batch): array
    {
        $paths = is_array($batch->photo_paths) ? $batch->photo_paths : [];
        if ($paths === []) {
            return [];
        }

        $disk = Storage::disk((string) ($batch->photo_disk ?: OpsPhotoController::disk()));
        $out = [];

        foreach ($paths as $path) {
            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                $shrunk = ImageDownscale::shrink((string) $disk->get($path));
                $out[] = ['data' => base64_encode($shrunk['data']), 'mime_type' => $shrunk['mime']];
            } catch (\Throwable $e) {
                Log::warning('상황실 사진 로드 실패: '.$e->getMessage());
            }
        }

        return $out;
    }

    /**
     * 판독이 끝난 뒤 사진을 정리한다.
     *
     * 영수증·납품서·도면·안전 사진은 **원본이 곧 증빙**이라 문서함으로 옮겨 영구 보관한다.
     * 단순 시공 사진만 지운다 — 진행률로 이미 반영됐고 원본을 계속 둘 이유가 없다.
     *
     * @param  array<int, array<string, mixed>>  $photoKinds
     */
    private function discardPhotos(OpsIntakeBatch $batch, array $photoKinds = []): void
    {
        $paths = is_array($batch->photo_paths) ? $batch->photo_paths : [];
        if ($paths === []) {
            return;
        }

        $diskName = (string) ($batch->photo_disk ?: OpsPhotoController::disk());
        $disk = Storage::disk($diskName);
        $toDelete = [];
        $filed = 0;

        foreach ($paths as $i => $path) {
            $kind = (string) ($photoKinds[$i]['kind'] ?? OpsPhotoRouter::KIND_OTHER);

            if (! in_array($kind, OpsPhotoRouter::KEEP_AS_EVIDENCE, true)) {
                $toDelete[] = $path;

                continue;
            }

            try {
                $title = trim((string) ($photoKinds[$i]['summary'] ?? '')) ?: (OpsPhotoRouter::KIND_LABELS[$kind] ?? '증빙');
                $doc = $this->documents->fileOpsEvidence($diskName, $path, $kind, $title, $batch->site_id, $batch->created_by_id);
                if ($doc !== null) {
                    $filed++;
                    $toDelete[] = $path;   // 문서함으로 복사됐으니 임시본은 지운다.
                }
            } catch (\Throwable $e) {
                Log::warning('상황실 증빙 편철 실패: '.$e->getMessage());
                // 편철에 실패하면 원본을 지우지 않는다 — 증빙을 잃는 것보다 남겨 두는 편이 낫다.
            }
        }

        try {
            if ($toDelete !== []) {
                $disk->delete($toDelete);
            }
        } catch (\Throwable $e) {
            Log::warning('상황실 사진 정리 실패: '.$e->getMessage());
        }

        $remaining = array_values(array_diff($paths, $toDelete));
        $batch->update(['photo_paths' => $remaining ?: null, 'evidence_filed' => $filed]);
    }

    /**
     * 확인 대기 중인 제안 목록(잡담 제외).
     *
     * @return array<string, mixed>
     */
    public function pending(?int $siteId = null, int $limit = 100): array
    {
        $rows = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->whereIn('status', ['pending', 'needs_input'])
            ->where('category', '!=', 'noise')
            ->latest()->limit($limit)->get();

        return [
            'success' => true,
            'count' => $rows->count(),
            'items' => $rows->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 붙여넣은 원문 이력 — 최근 것부터.
     *
     * @return array<string, mixed>
     */
    public function batches(?int $siteId = null, int $limit = 50): array
    {
        $rows = OpsIntakeBatch::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with('createdBy')
            ->withCount(['items as items_applied_count' => fn ($q) => $q->where('status', 'applied')])
            ->latest()->limit($limit)->get();

        return [
            'success' => true,
            'count' => $rows->count(),
            'batches' => $rows->map(fn (OpsIntakeBatch $b) => [
                'id' => $b->id,
                'preview' => $b->preview(),
                'source' => $b->source,
                'imageCount' => $b->image_count,
                'parsed' => $b->parsed_count,
                'actionable' => $b->actionable_count,
                'noise' => $b->noise_count,
                'by' => $b->createdBy?->name,
                'at' => $b->created_at?->format('Y-m-d H:i'),
                'edited' => $b->edited_at !== null,
                'applied' => $b->items_applied_count ?? 0,
            ])->all(),
        ];
    }

    /**
     * 원문 1건 + 그때 뽑힌 판독 결과.
     *
     * @return array<string, mixed>
     */
    public function batch(int $id): array
    {
        $b = OpsIntakeBatch::with(['items', 'createdBy', 'editedBy'])->find($id);
        if (! $b) {
            return ['success' => false, 'error' => '원문을 찾을 수 없습니다.'];
        }

        return [
            'success' => true,
            'id' => $b->id,
            'raw' => (string) $b->raw_text,
            'source' => $b->source,
            'imageCount' => $b->image_count,
            'by' => $b->createdBy?->name,
            'at' => $b->created_at?->format('Y-m-d H:i'),
            // 수정 이력 — 고친 사람과 고치기 전 원문을 함께 돌려준다.
            'editedBy' => $b->editedBy?->name,
            'editedAt' => $b->edited_at?->format('Y-m-d H:i'),
            'originalText' => $b->original_text,
            'appliedCount' => $b->items->where('status', 'applied')->count(),
            'items' => $b->items->map(fn (OpsIntakeItem $i) => $this->row($i))->all(),
        ];
    }

    /**
     * 원문 수정 — 오타·오인식(OCR)을 고칠 수 있게 한다.
     *
     * 원문은 "왜 이렇게 반영됐지?"를 되짚는 근거라, 처음 올라온 내용을 original_text 로
     * 한 번만 보존하고 누가 언제 고쳤는지 남긴다. (판독 결과는 건드리지 않는다 —
     * 다시 판독하고 싶으면 새로 올리는 편이 이력이 깔끔하다.)
     *
     * @return array<string, mixed>
     */
    public function updateBatch(int $id, string $raw, ?int $userId = null): array
    {
        $batch = OpsIntakeBatch::find($id);
        if (! $batch) {
            return ['success' => false, 'error' => '원문을 찾을 수 없습니다.'];
        }

        $raw = trim($raw);
        if ($raw === '' && (int) $batch->image_count === 0) {
            return ['success' => false, 'error' => '내용을 비울 수 없습니다. 지우려면 삭제를 사용하세요.'];
        }

        if ($raw === (string) $batch->raw_text) {
            return ['success' => true, 'unchanged' => true];
        }

        $batch->forceFill([
            // 최초 원문은 한 번만 남긴다 — 두 번째 수정부터는 덮어쓰지 않는다.
            'original_text' => $batch->original_text ?? (string) $batch->raw_text,
            'raw_text' => $raw,
            'edited_by_id' => $userId,
            'edited_at' => now(),
        ])->save();

        return ['success' => true, 'id' => $batch->id];
    }

    /**
     * 원문 삭제.
     *
     * 이미 공정표·조달에 반영된 항목이 딸려 있으면 지우지 않는다 — 원문이 사라지면
     * 되돌릴 근거(previous 값)를 확인할 길이 없어진다. 먼저 되돌리게 안내한다.
     *
     * @return array<string, mixed>
     */
    public function deleteBatch(int $id): array
    {
        $batch = OpsIntakeBatch::with('items')->find($id);
        if (! $batch) {
            return ['success' => false, 'error' => '원문을 찾을 수 없습니다.'];
        }

        $applied = $batch->items->where('status', 'applied')->count();
        if ($applied > 0) {
            return [
                'success' => false,
                'error' => "이미 공정표에 반영된 항목 {$applied}건이 있습니다. 먼저 되돌린 뒤 삭제하세요.",
                'appliedCount' => $applied,
            ];
        }

        $items = $batch->items->count();
        $batch->items()->delete();
        $batch->delete();

        return ['success' => true, 'deletedItems' => $items];
    }

    /** 공정(WBS)에 반영을 허용하는 필드. 그 외는 무시한다. */
    private const WBS_FIELDS = ['progress', 'status', 'planned_start', 'planned_end', 'name', 'crew_size', 'days', 'manhours'];

    /** 조달에 반영을 허용하는 필드. */
    private const PROC_FIELDS = ['eta', 'status', 'vendor', 'po_no', 'amount', 'ordered_on'];

    /**
     * 제출물 대장(검사·검측 포함)에 반영을 허용하는 필드.
     *
     * 조항 내용(title·csi·category)은 임포트가 정본이라 여기 없다 — 대화로 시방 문구가
     * 바뀌는 일은 없어야 한다.
     */
    private const SUBMITTAL_FIELDS = ['planned_on', 'status', 'assignee', 'submitted_on', 'approved_on', 'notes'];

    /**
     * 제안을 실제 공정표·조달에 반영한다.
     *
     * 반영 전 값을 함께 저장해 되돌릴 수 있게 한다. TBM 게이트 등 기존 업무 규칙에 걸리면
     * 그 사유를 그대로 돌려주고 반영하지 않는다(규칙을 우회하지 않는다).
     *
     * @param  array<string, mixed>|null  $overrides  사람이 값을 고쳐서 적용할 때
     * @param  string  $via  어떤 경로로 반영됐는가(OpsIntakeItem::VIA_*). 나중에 숫자를
     *                       되짚을 때 "사람이 눌렀나, 보고 제출로 넘어갔나" 를 가른다.
     * @return array<string, mixed>
     */
    public function apply(int $id, ?array $overrides = null, ?int $userId = null, string $via = OpsIntakeItem::VIA_MANUAL): array
    {
        $item = OpsIntakeItem::find($id);
        if (! $item) {
            return ['success' => false, 'error' => '항목을 찾을 수 없습니다.'];
        }
        if ($item->status === 'applied') {
            return ['success' => false, 'error' => '이미 반영된 항목입니다.'];
        }

        $patch = is_array($overrides) && $overrides !== [] ? $overrides : (array) ($item->proposed ?? []);
        if ($patch === []) {
            return ['success' => false, 'error' => '반영할 변경 내용이 없습니다.'];
        }
        if (blank($item->target_code)) {
            return ['success' => false, 'error' => '반영 대상이 지정되지 않았습니다. 대상을 먼저 지정하세요.'];
        }

        // 두 경로가 같은 항목을 동시에 반영하지 못하게 자리를 먼저 잡는다.
        //
        // 위의 `status === 'applied'` 검사만으로는 막지 못한다 — 읽고 나서 쓰기까지
        // 사이가 비어 있어, 그 틈에 다른 요청이 같은 검사를 통과한다. 값 자체는
        // 두 번 써도 같지만 <b>previous 는 다르다</b>: 두 번째가 «이미 반영된 값» 을
        // 되돌리기 근거로 저장해 버려, 그 뒤의 되돌리기는 성공을 돌려주면서 아무것도
        // 되돌리지 않는다. 자동 반영이 붙은 뒤로 이 경쟁은 예외가 아니라 일상이다
        // (제출 잡 · 사진 판독 이어달리기 · 상황실의 사람 클릭이 겹친다).
        $claimed = OpsIntakeItem::query()
            ->whereKey($id)
            ->whereIn('status', ['pending', 'needs_input'])
            ->update(['status' => 'applying']);

        if ($claimed === 0) {
            return ['success' => false, 'error' => '이미 반영됐거나 다른 곳에서 처리 중인 항목입니다.'];
        }

        $before = (string) $item->status;
        $item->status = 'applying';

        try {
            // 지출은 공정·조달과 달리 전용 경로로 — 재무(MobileExpense)에 등록된다.
            $res = $item->category === 'expense'
                ? $this->modules->applyExpense($item, $userId, $via)
                : match ($item->target_type) {
                    'procurement' => $this->applyProcurement($item, $patch, $userId, $via),
                    'submittal' => $this->applySubmittal($item, $patch, $userId, false, $via),
                    default => $this->applyWbs($item, $patch, $userId, $via),
                };
        } catch (\Throwable $e) {
            $this->releaseClaim($id, $before);

            throw $e;
        }

        // 실패했으면 자리를 놓아준다 — 'applying' 인 채로 남으면 그 항목은
        // 확인 대기 목록에서도 사라져 아무도 다시 손대지 못한다.
        if (! ($res['success'] ?? false)) {
            $this->releaseClaim($id, $before);
        }

        return $res;
    }

    /** 반영에 실패한 항목을 원래 상태로 되돌린다(잡아 둔 자리 반납). */
    private function releaseClaim(int $id, string $status): void
    {
        OpsIntakeItem::query()->whereKey($id)->where('status', 'applying')->update(['status' => $status]);
    }

    /**
     * 「이 현장 것, 또는 현장이 안 붙은 본사 공통」으로 좁힌다.
     *
     * 공정표·조달 정본(WbsService::itemsFor)이 쓰는 것과 같은 규칙이다. 현장을 아예
     * 안 거는 것은 위험하고(po_no 에는 유일 제약이 없어 남의 현장 발주를 집는다),
     * 현장만 정확히 거는 것은 너무 좁다 — 임포트에서 현장이 안 잡힌 행은 site_id 가
     * null 로 들어오고, ERP 의 다른 화면들은 그 행을 일부러 포함한다.
     *
     * 정확히 맞는 행을 먼저 준다 — 순서를 정하지 않으면 같은 코드에 현장 행과 공통
     * 행이 함께 있을 때 apply 와 revert 가 서로 다른 행을 집을 수 있다.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function scopeToSite($query, ?int $siteId)
    {
        if ($siteId === null) {
            return $query;
        }

        return $query
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $siteId))
            ->orderByRaw('case when site_id = ? then 0 else 1 end', [$siteId])
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function applyWbs(OpsIntakeItem $item, array $patch, ?int $userId, string $via = OpsIntakeItem::VIA_MANUAL): array
    {
        // 현장으로 좁힌다. wbs_code 는 전역 유일이라 지금은 결과가 같지만, 좁히지 않으면
        // 「이 현장 것이 맞나」를 코드가 확인하지 않는다는 뜻이 된다 — 조달(po_no)에서는
        // 그 확인이 없어서 실제로 남의 현장 행을 집을 수 있었다.
        $wbs = self::scopeToSite(WbsItem::query()->where('wbs_code', $item->target_code), $item->site_id)->first();
        if (! $wbs) {
            return ['success' => false, 'error' => '공정을 찾을 수 없습니다: '.$item->target_code];
        }

        $clean = [];
        $previous = [];
        foreach ($patch as $key => $value) {
            if (! in_array($key, self::WBS_FIELDS, true) || $value === null || $value === '') {
                continue;
            }
            $previous[$key] = $this->currentWbsValue($wbs, $key);

            // crew_size 는 직접 컬럼이 아니라 투입조 텍스트로 파싱된다 — 기존 규칙을 그대로 탄다.
            if ($key === 'crew_size') {
                $clean['crew_text'] = ((int) $value).'명';

                continue;
            }
            if ($key === 'progress') {
                $clean['progress'] = max(0, min(100, (int) $value));

                continue;
            }
            if ($key === 'status') {
                $clean['status'] = (string) $value;

                continue;
            }
            if (in_array($key, ['planned_start', 'planned_end'], true)) {
                $d = $this->safeDate((string) $value);
                if ($d === null) {
                    continue;
                }
                $clean[$key] = $d;

                continue;
            }
            $clean[$key] = $value;
        }

        if ($clean === []) {
            return ['success' => false, 'error' => '반영 가능한 항목이 없습니다.'];
        }

        $res = app(WbsService::class)->updateRow((string) $item->target_code, $clean);
        if (! ($res['success'] ?? false)) {
            $item->update(['result_note' => mb_substr((string) ($res['error'] ?? '반영 실패'), 0, 300)]);

            return ['success' => false, 'error' => $res['error'] ?? '반영에 실패했습니다.', 'gated' => $res['gated'] ?? false];
        }

        // CPM 엔진이 이 편집의 파급(후속 이동·예상 준공)을 함께 알려준다 — 결과 메모에 남긴다.
        $cpm = is_array($res['cpm'] ?? null) ? $res['cpm'] : null;
        $note = '공정표 반영 완료';
        if ($cpm !== null && ! ($cpm['skipped'] ?? true) && (int) ($cpm['movedCount'] ?? 0) > 0) {
            $note .= ' · 후속 '.$cpm['movedCount'].'건 일정 이동';
            if (! empty($cpm['projectedEnd'])) {
                $note .= ' · 예상 준공 '.$cpm['projectedEnd'];
            }
        }

        $item->update([
            'status' => 'applied',
            'previous' => $previous,
            'proposed' => $patch,
            'applied_at' => now(),
            'applied_by_id' => $userId,
            'applied_via' => $via,
            'result_note' => mb_substr($note, 0, 300),
        ]);

        // 보고가 올라온 그 메시지에 결과를 붙인다 — "반영됐나요?"를 묻지 않게.
        // 소통 회로가 막혀도 반영은 이미 끝났다 — 여기서 터지면 반영이 실패한 것처럼 보인다.
        try {
            app(DecisionReplyConnector::class)
                ->intakeApplied($item, "공정표 반영 완료 — {$item->target_name}".mb_substr(str_replace('공정표 반영 완료', '', $note), 0, 200));
        } catch (\Throwable $e) {
            report($e);
        }

        return ['success' => true, 'target' => $item->target_code, 'applied' => $clean, 'cpm' => $cpm];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function applyProcurement(OpsIntakeItem $item, array $patch, ?int $userId, string $via = OpsIntakeItem::VIA_MANUAL): array
    {
        // 제안의 대상 코드는 PO 번호다 — 실제 갱신은 project_code + wbs_code 로 이뤄진다.
        //
        // 현장으로 좁히는 것이 중요하다. po_no 에는 유일 제약이 없고(유일한 것은
        // project_code+wbs_code 뿐이다), 같은 번호가 두 현장에 있으면 ->first() 가
        // 남의 현장 발주를 집는다. 판독 단계에서 후보를 현장별로 걸렀더라도
        // 반영 단계에서 그 현장을 다시 확인하지 않으면 격리가 성립하지 않는다.
        $po = self::scopeToSite(ProcurementItem::query()->where('po_no', $item->target_code), $item->site_id)->first();
        if (! $po) {
            return ['success' => false, 'error' => '발주 건을 찾을 수 없습니다: '.$item->target_code];
        }

        $clean = [];
        $previous = [];
        foreach ($patch as $key => $value) {
            if (! in_array($key, self::PROC_FIELDS, true) || $value === null || $value === '') {
                continue;
            }
            $previous[$key] = $this->currentProcurementValue($po, $key);

            if (in_array($key, ['eta', 'ordered_on'], true)) {
                $d = $this->safeDate((string) $value);
                if ($d === null) {
                    continue;
                }
                $clean[$key] = $d;

                continue;
            }
            if ($key === 'status' && ! in_array($value, ProcurementItem::STATUSES, true)) {
                continue; // 정의된 조달 단계가 아니면 무시
            }
            $clean[$key] = $value;
        }

        if ($clean === []) {
            return ['success' => false, 'error' => '반영 가능한 항목이 없습니다.'];
        }

        $res = app(ProcurementService::class)->update(
            (string) $po->project_code,
            (string) $po->wbs_code,
            $clean,
            'ALL',
            $userId,
        );
        if (! ($res['success'] ?? false)) {
            $item->update(['result_note' => mb_substr((string) ($res['error'] ?? '반영 실패'), 0, 300)]);

            return ['success' => false, 'error' => $res['error'] ?? '반영에 실패했습니다.'];
        }

        $item->update([
            'status' => 'applied',
            'previous' => $previous,
            'proposed' => $patch,
            'applied_at' => now(),
            'applied_by_id' => $userId,
            'applied_via' => $via,
            'result_note' => '조달 반영 완료',
        ]);

        // "그 자재 언제 와요?"의 답이 방으로 돌아간다 — 특히 입고완료가 그렇다.
        $summary = isset($clean['status']) ? "상태 {$clean['status']}" : '';
        $summary .= isset($clean['eta']) ? ($summary ? ' · ' : '')."ETA {$clean['eta']}" : '';
        try {
            app(DecisionReplyConnector::class)
                ->intakeApplied($item, "조달 반영 완료 — PO {$item->target_code}".($summary ? " ({$summary})" : ''));
        } catch (\Throwable $e) {
            report($e);
        }

        return ['success' => true, 'target' => $item->target_code, 'applied' => $clean];
    }

    /**
     * 제출물 대장 반영 — 주로 검사·검측 계획일이 여기로 온다.
     *
     * "다음 주 화요일 앵커 검사 입회" 같은 말이 시방 제출물의 계획일이 되는 자리다.
     * 조항 내용은 건드리지 않고(임포트가 정본) 상태·담당·날짜·메모만 갱신한다.
     *
     * <b>부분 갱신 주의:</b> saveSubmittal 은 넘기지 않은 키를 null 로 덮는다. 그래서
     * 현재 값을 먼저 읽어 채운 뒤 바뀌는 것만 얹는다 — 계획일 하나 넣으려다 담당자와
     * 제출일이 지워지면 안 된다.
     *
     * @param  array<string, mixed>  $patch
     * @param  bool  $allowClear  되돌리기 전용 — 빈 값을 "원래대로 비우기"로 받아들인다.
     *                            검사 계획일은 원래 비어 있는 것이 정상이라, 이걸 막으면
     *                            "계획일을 넣은 뒤 되돌리기"가 영원히 안 된다.
     * @return array<string, mixed>
     */
    private function applySubmittal(OpsIntakeItem $item, array $patch, ?int $userId, bool $allowClear = false, string $via = OpsIntakeItem::VIA_MANUAL): array
    {
        // seq 는 프로젝트 안에서만 유일하다 — 현장으로 좁혀 남의 프로젝트 행을 집지 않는다.
        $row = Submittal::query()
            ->where('seq', (int) $item->target_code)
            ->when($item->site_id, fn ($q) => $q->where('site_id', $item->site_id))
            ->orderBy('id')
            ->first();

        if (! $row) {
            return ['success' => false, 'error' => '제출물 항목을 찾을 수 없습니다: #'.$item->target_code];
        }

        $clean = [];
        $previous = [];
        foreach ($patch as $key => $value) {
            if (! in_array($key, self::SUBMITTAL_FIELDS, true)) {
                continue;
            }
            $clearing = ($value === null || $value === '');
            // 상태는 비울 수 없다(제출물은 항상 어떤 상태에 있다).
            if ($clearing && (! $allowClear || $key === 'status')) {
                continue;
            }
            $previous[$key] = $this->currentSubmittalValue($row, $key);
            if ($clearing) {
                $clean[$key] = null;

                continue;
            }

            if (in_array($key, ['planned_on', 'submitted_on', 'approved_on'], true)) {
                $d = $this->safeDate((string) $value);
                if ($d === null) {
                    continue;
                }
                $clean[$key] = $d;

                continue;
            }
            if ($key === 'status' && ! array_key_exists((string) $value, Submittal::STATUS_OPTIONS)) {
                continue; // 정의된 제출물 상태가 아니면 무시
            }
            $clean[$key] = $value;
        }

        if ($clean === []) {
            return ['success' => false, 'error' => '반영 가능한 항목이 없습니다.'];
        }

        // 현재 값을 바탕에 깔고 바뀌는 것만 덮는다(saveSubmittal 이 전량 덮어쓰기라서).
        // array_key_exists 로 봐야 "null 로 비우기"가 기존 값으로 되돌아가지 않는다.
        $pick = fn (string $key, mixed $current): mixed => array_key_exists($key, $clean) ? $clean[$key] : $current;

        $payload = [
            'id' => $row->id,
            'status' => $clean['status'] ?? $row->status,
            'assignee' => $pick('assignee', $row->assignee),
            'plannedOn' => $pick('planned_on', $row->planned_on?->toDateString()),
            'submittedOn' => $pick('submitted_on', $row->submitted_on?->toDateString()),
            'approvedOn' => $pick('approved_on', $row->approved_on?->toDateString()),
            'notes' => $pick('notes', $row->notes),
        ];

        // 누구의 권한으로 고치는지 명시한다. 보고 제출로 반영될 때는 세션이 없어서,
        // auth() 에만 기대면 그 길로는 검사 일정이 영영 반영되지 않는다.
        $res = app(ProjectRegisterService::class)->saveSubmittal(
            $payload,
            $userId ? User::find($userId) : null,
        );
        if (! ($res['success'] ?? false)) {
            $item->update(['result_note' => mb_substr((string) ($res['error'] ?? '반영 실패'), 0, 300)]);

            return ['success' => false, 'error' => $res['error'] ?? '반영에 실패했습니다.'];
        }

        // 시방이 정지·실격을 명문화한 항목(gate)은 반영 사실을 눈에 띄게 남긴다.
        $note = '제출물 반영 완료';
        if (isset($clean['planned_on'])) {
            $note .= ' · 계획일 '.$clean['planned_on'];
        }
        if ($row->gate) {
            $note .= ' · ★ 정지 조항 항목(통과 전 다음 공정 금지)';
        }

        $item->update([
            'status' => 'applied',
            'previous' => $previous,
            'proposed' => $patch,
            'applied_at' => now(),
            'applied_by_id' => $userId,
            'applied_via' => $via,
            'result_note' => mb_substr($note, 0, 300),
        ]);

        try {
            app(DecisionReplyConnector::class)
                ->intakeApplied($item, "{$note} — #{$row->seq} ".mb_substr((string) $row->title, 0, 80));
        } catch (\Throwable $e) {
            report($e);
        }

        return ['success' => true, 'target' => $item->target_code, 'applied' => $clean];
    }

    /**
     * 확신도가 높고 대상이 확실한 제안을 한 번에 반영한다(확인 필요 항목은 건너뛴다).
     *
     * @return array<string, mixed>
     */
    public function applyAll(?int $siteId = null, ?int $userId = null): array
    {
        // 현장을 안 고르면 «전체 반영» 은 전 현장·전 회사의 대기 항목을 한 번에
        // 반영해 버린다. 되돌리기는 항목 하나씩뿐이라 원상복구가 사실상 불가능하다.
        // 일괄 작업은 그 범위를 사람이 눈으로 보고 있을 때만 성립한다.
        if (! $siteId) {
            return [
                'success' => false,
                'error' => '현장을 먼저 고른 뒤에 일괄 반영하세요. 「전체」 상태에서는 어느 현장에 반영되는지 화면에서 확인할 수 없습니다.',
                'needsSite' => true,
            ];
        }

        $rows = OpsIntakeItem::query()
            ->where('site_id', $siteId)
            ->where('status', 'pending')
            ->where('category', '!=', 'noise')
            ->whereNotNull('target_code')
            ->get();

        $ok = 0;
        $failed = [];
        foreach ($rows as $row) {
            $res = $this->apply($row->id, null, $userId);
            if ($res['success'] ?? false) {
                $ok++;
            } else {
                $failed[] = ['id' => $row->id, 'summary' => $row->summary, 'error' => $res['error'] ?? '실패'];
            }
        }

        return ['success' => true, 'applied' => $ok, 'failed' => count($failed), 'failures' => $failed];
    }

    /**
     * 반영을 되돌린다 — 저장해 둔 이전 값으로 복원.
     *
     * @return array<string, mixed>
     */
    public function revert(int $id, ?int $userId = null): array
    {
        $item = OpsIntakeItem::find($id);
        if (! $item || $item->status !== 'applied') {
            return ['success' => false, 'error' => '반영된 항목이 아닙니다.'];
        }
        $previous = (array) ($item->previous ?? []);
        if ($previous === []) {
            return ['success' => false, 'error' => '되돌릴 이전 값이 없습니다.'];
        }

        $res = match ($item->target_type) {
            'procurement' => $this->applyProcurement($item, $previous, $userId),
            // allowClear=true — 원래 비어 있던 값(계획일 미등록)으로 되돌리려면 비울 수 있어야 한다.
            'submittal' => $this->applySubmittal($item, $previous, $userId, true),
            default => $this->applyWbs($item, $previous, $userId),
        };

        if (! ($res['success'] ?? false)) {
            return $res;
        }

        $item->update([
            'status' => 'dismissed',
            'result_note' => '반영 취소(되돌림)',
            'applied_at' => null,
        ]);

        return ['success' => true, 'reverted' => $previous];
    }

    private function currentWbsValue(WbsItem $w, string $key): mixed
    {
        return match ($key) {
            'progress' => (int) $w->progress,
            'status' => (string) $w->status,
            'planned_start' => $w->planned_start?->toDateString(),
            'planned_end' => $w->planned_end?->toDateString(),
            'crew_size' => $w->crew_size !== null ? (int) round((float) $w->crew_size) : null,
            'days' => $w->days,
            'manhours' => $w->manhours,
            'name' => (string) $w->name,
            default => null,
        };
    }

    /** 되돌리기용 현재값 — 날짜는 문자열로 내야 previous 를 그대로 patch 로 되먹일 수 있다. */
    private function currentSubmittalValue(Submittal $s, string $key): mixed
    {
        return match ($key) {
            'planned_on' => $s->planned_on?->toDateString(),
            'submitted_on' => $s->submitted_on?->toDateString(),
            'approved_on' => $s->approved_on?->toDateString(),
            'status' => (string) $s->status,
            'assignee' => $s->assignee,
            'notes' => $s->notes,
            default => null,
        };
    }

    private function currentProcurementValue(ProcurementItem $p, string $key): mixed
    {
        return match ($key) {
            'eta' => $p->eta?->toDateString(),
            'ordered_on' => $p->ordered_on?->toDateString(),
            'status' => (string) $p->status,
            'vendor' => $p->vendor,
            'po_no' => $p->po_no,
            'amount' => $p->amount !== null ? (float) $p->amount : null,
            default => null,
        };
    }

    /**
     * 제안을 무시 처리한다(잘못 읽었거나 반영할 필요 없음).
     *
     * @return array<string, mixed>
     */
    public function dismiss(int $id, ?string $note = null): array
    {
        $item = OpsIntakeItem::find($id);
        if (! $item) {
            return ['success' => false, 'error' => '항목을 찾을 수 없습니다.'];
        }
        $item->update(['status' => 'dismissed', 'result_note' => $note]);

        return ['success' => true];
    }

    // ───────────────────────── 내부 ─────────────────────────

    /**
     * 현장 없는 판독을 어떻게 다루는가.
     *
     * 화면 위쪽 현장이 «전체» 이면 여기 오는 $site 가 null 이다. 예전에는 그 상태에서
     * <b>전 현장의 공정 코드</b>가 AI 후보 목록으로 들어갔다. 그러면 AI 가 남의 현장
     * 코드를 골라도 «지어낸 코드» 검사를 통과하고, 그 항목은 현장이 안 붙은 채
     * 저장돼(site_id=null) 반영 단계의 현장 확인도 통과한다 — A현장 소장이 붙여넣은
     * 카톡이 B현장(다른 회사) 공정표를 고칠 수 있었다.
     *
     * 이제 두 단계로 막는다.
     *   1. 글자에서 현장을 찾아본다 — "HFF-02 3층 배관 끝" 이면 HFF-02 로 붙인다.
     *      (영수증·문서함과 같은 판단 규칙 한 벌을 쓴다. 애매하면 붙이지 않는다.)
     *   2. 그래도 모르면 후보 목록을 <b>비운다</b>. 대상을 못 찾은 항목은 «어느 현장·
     *      어느 작업인지 확인해 주세요» 로 남는다. 사람에게 한 번 묻는 편이,
     *      틀린 현장에 조용히 반영되는 것보다 낫다.
     */
    private function resolveSite(?Site $site, string $text): ?Site
    {
        return $site ?? SiteFromText::match($text);
    }

    /**
     * AI 결과 1건을 검증해 저장한다. 지어낸 대상 코드는 버리고 되물음으로 돌린다.
     *
     * @param  array<string, mixed>  $r
     * @param  array<int, string>  $validCodes
     * @param  array<int, string>  $validPos
     * @param  array<int, string>  $validSeqs  등록된 검사·제출물 번호
     */
    private function persist(array $r, ?Site $site, ?int $userId, string $source, ?int $messageId, array $validCodes, array $validPos, string $fallbackText, ?int $batchId = null, array $validSeqs = []): ?OpsIntakeItem
    {
        $category = (string) ($r['category'] ?? 'noise');
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'noise';
        }

        $targetType = (string) ($r['target_type'] ?? '');
        $targetCode = trim((string) ($r['target_code'] ?? ''));

        // AI 가 목록에 없는 코드를 지어냈으면 버린다(잘못된 대상에 반영되는 사고 방지).
        $hallucinated = false;
        if ($targetCode !== '') {
            $known = match ($targetType) {
                'procurement' => in_array($targetCode, $validPos, true),
                'submittal' => in_array($targetCode, $validSeqs, true),
                default => in_array($targetCode, $validCodes, true),
            };
            if (! $known) {
                $hallucinated = true;
                $targetCode = '';
                $targetType = '';
            }
        }

        $confidence = (int) ($r['confidence'] ?? 0);
        $confidence = max(0, min(100, $confidence));

        $proposed = is_array($r['proposed'] ?? null) ? $r['proposed'] : [];
        $question = trim((string) ($r['question'] ?? ''));
        if ($hallucinated && $question === '') {
            $question = '어느 작업/발주/검사 항목인지 확인해 주세요. (AI 가 대상을 특정하지 못했습니다)';
        }

        // 확정된 것과 어긋나는가. AI 가 찾은 것(도면 사양 대조)과 코드가 확실히 아는 것
        // (숫자가 뒤로 감)을 합친다 — 숫자 역행까지 AI 판단에 맡기지 않는다.
        $conflict = $this->normalizeConflict($r['conflict'] ?? null)
            ?? app(OpsConflictDetector::class)->check($targetType, $targetCode, $proposed, $site?->id);

        if ($conflict !== null) {
            // 어긋난 값을 조용히 덮어쓰지 않는다 — 도면이 바뀐 것인지 착오인지는 사람만 안다.
            $proposed = [];
            if ($question === '') {
                $question = app(OpsConflictDetector::class)->question($conflict);
            }
        }

        // 잡담은 기록만 하고 목록에서 빠진다. 그 외는 확신도·대상 유무로 상태를 정한다.
        $status = 'pending';
        if ($category === 'noise') {
            $status = 'ignored';
        } elseif ($conflict !== null || $question !== '' || $confidence < self::LOW_CONFIDENCE || ($targetCode === '' && $proposed !== [])) {
            // 어긋남은 언제나 사람 확인 대상이다.
            $status = 'needs_input';
        }

        $occurred = trim((string) ($r['occurred_on'] ?? ''));

        return OpsIntakeItem::create([
            'site_id' => $site?->id,
            'ops_intake_batch_id' => $batchId,
            'source' => $source,
            'communication_message_id' => $messageId,
            'created_by_id' => $userId,
            'raw_text' => mb_substr(trim((string) ($r['raw_text'] ?? '')) ?: $fallbackText, 0, 4000),
            'speaker' => mb_substr(trim((string) ($r['speaker'] ?? '')), 0, 80) ?: null,
            'occurred_on' => $this->safeDate($occurred),
            'category' => $category,
            'confidence' => $confidence,
            'summary' => mb_substr(trim((string) ($r['summary'] ?? '')), 0, 300) ?: null,
            'target_type' => $targetType ?: null,
            'target_code' => $targetCode ?: null,
            'target_name' => mb_substr(trim((string) ($r['target_name'] ?? '')), 0, 200) ?: null,
            'proposed' => $proposed ?: null,
            'question' => $question ?: null,
            'conflict' => $conflict,
            'status' => $status,
        ]);
    }

    /**
     * AI 가 준 어긋남을 믿을 수 있는 모양으로만 받는다 — 근거(무엇과)와 양쪽 값이
     * 다 있어야 사람에게 보여줄 수 있다. 반쪽짜리 지적은 잔소리가 된다.
     *
     * @return array{with: string, expected: string, heard: string, note: string}|null
     */
    private function normalizeConflict(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $with = mb_substr(trim((string) ($raw['with'] ?? '')), 0, 200);
        $expected = mb_substr(trim((string) ($raw['expected'] ?? '')), 0, 300);
        $heard = mb_substr(trim((string) ($raw['heard'] ?? '')), 0, 300);

        if ($with === '' || $expected === '' || $heard === '') {
            return null;
        }

        return [
            'with' => $with,
            'expected' => $expected,
            'heard' => $heard,
            'note' => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 300),
        ];
    }

    private function safeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * AI 에게 넘길 공정 후보 — 이름으로 대조해 코드를 찾게 한다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activityContext(?Site $site): Collection
    {
        // 현장을 모르면 후보를 주지 않는다 — 아래 «현장 없는 판독» 주석 참고.
        if (! $site) {
            return collect();
        }

        return WbsItem::query()
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where('status', '!=', WbsItem::STATUS_DONE)
            ->orderByRaw('planned_start is null, planned_start')
            ->limit(200)->get()
            ->map(fn (WbsItem $w) => [
                'code' => (string) $w->wbs_code,
                'name' => (string) $w->name,
                'status' => (string) $w->status,
                'start' => $w->planned_start?->toDateString() ?? '',
                'end' => $w->planned_end?->toDateString() ?? '',
            ]);
    }

    /**
     * AI 에게 넘길 검사·시험 후보 — 대화 속 "앵커 검사" 를 제출물 대장의 번호로 잇는다.
     *
     * 시방이 요구하는 시험·검사가 정본이다(제출물 대장 category='시험·검사'). 목록에
     * 없는 검사를 AI 가 지어내면 persist 의 환각 차단이 버린다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function inspectionContext(?Site $site): Collection
    {
        if (! $site) {
            return collect();
        }

        return Submittal::query()
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where(function ($q): void {
                $q->where('category', '시험·검사')
                    ->orWhere('title', 'ilike', '%검사%')
                    ->orWhere('title', 'ilike', '%시험%');
            })
            ->orderByDesc('gate')
            ->orderBy('seq')
            ->limit(150)->get()
            ->map(fn (Submittal $s) => [
                'seq' => (string) $s->seq,
                'section' => trim($s->csi.' '.$s->section),
                'title' => mb_substr((string) $s->title, 0, 110),
                'planned_on' => $s->planned_on?->toDateString() ?? '',
                'status' => (string) $s->status,
                'gate' => $s->gate ? 'Y' : '',
            ]);
    }

    /**
     * AI 에게 넘길 제출물 대장 — 사무관리자의 "샵드로잉 냈다·승인 받았다" 를 대장 번호로 잇는다.
     *
     * 검사 후보(inspectionContext)는 시험·검사만 담는다. 사무의 하루는 그 밖의 제출물
     * (제품자료·제작도·마감자재 승인·준공 서류)에서 일어나므로, 아직 끝나지 않은
     * 것을 먼저 싣고 승인이 끝난 것은 뒤로 보낸다 — 오늘 보고될 확률 순이다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function submittalContext(?Site $site): Collection
    {
        if (! $site) {
            return collect();
        }

        return Submittal::query()
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where('category', '!=', '시험·검사')
            ->orderByRaw("case when status in ('승인', '조건부승인') then 1 else 0 end")
            ->orderBy('seq')
            ->limit(150)->get()
            ->map(fn (Submittal $s) => [
                'seq' => (string) $s->seq,
                'section' => trim($s->csi.' '.$s->section),
                'title' => mb_substr((string) $s->title, 0, 90),
                'status' => (string) $s->status,
                'planned_on' => $s->planned_on?->toDateString() ?? '',
                'submitted_on' => $s->submitted_on?->toDateString() ?? '',
                'gate' => $s->gate ? 'Y' : '',
            ]);
    }

    /**
     * AI 가 target_code 로 써도 되는 제출물 번호 — 검사 후보와 대장 후보의 합.
     *
     * @param  Collection<int, array<string, mixed>>  $inspections
     * @param  Collection<int, array<string, mixed>>  $submittals
     * @return array<int, string>
     */
    private function validSeqs(Collection $inspections, Collection $submittals): array
    {
        return $inspections->pluck('seq')->concat($submittals->pluck('seq'))
            ->filter()->unique()->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseContext(?Site $site): Collection
    {
        if (! $site) {
            return collect();
        }

        return ProcurementItem::query()
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->whereNotNull('po_no')->where('po_no', '!=', '')
            ->latest()->limit(100)->get()
            ->map(fn (ProcurementItem $p) => [
                'po' => (string) $p->po_no,
                'vendor' => (string) $p->vendor,
                'eta' => $p->eta?->toDateString() ?? '',
            ]);
    }

    /**
     * 이 현장에서 이미 <b>확정된 사양</b> — 판독된 도면의 스펙과 문서의 핵심 사실.
     *
     * 대화만 읽으면 "6인치로 간다" 가 그냥 보고로 보인다. 도면에 4인치로 적혀 있다는
     * 것을 알아야 비로소 "어긋났다" 가 보인다. 개입의 근거가 되는 자료다.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function specContext(?Site $site): Collection
    {
        $specs = collect();

        // 남의 현장 도면 사양으로 우리 현장 보고를 «어긋났다» 고 지적하면 안 된다.
        if (! $site) {
            return $specs;
        }

        try {
            if (Schema::hasTable('field_drawings')) {
                $specs = $specs->merge(
                    FieldDrawing::query()
                        ->when($site, fn ($q) => $q->where('site_id', $site->id))
                        ->where('status', 'analyzed')
                        ->latest('analyzed_at')
                        ->limit(20)->get()
                        ->map(fn ($d): array => [
                            'source' => trim(($d->drawing_no ?: '도면').' '.($d->version ? "Rev.{$d->version}" : '')),
                            'title' => (string) $d->title,
                            'facts' => collect(is_array($d->specs) ? $d->specs : [])->take(8)->values()->all(),
                        ])
                        ->filter(fn (array $r): bool => $r['facts'] !== [])
                );
            }

            if (Schema::hasTable('intelligent_documents')) {
                $specs = $specs->merge(
                    IntelligentDocument::query()
                        ->when($site, fn ($q) => $q->where('site_id', $site->id))
                        ->whereIn('document_type', ['drawing', 'specification', 'submittal', 'contract', 'change_order'])
                        ->where('ai_status', 'ready')
                        ->latest('analyzed_at')
                        ->limit(15)->get()
                        ->map(fn ($d): array => [
                            'source' => trim(($d->document_number ?: $d->original_file_name).' '.($d->revision ? "Rev.{$d->revision}" : '')),
                            'title' => (string) $d->title,
                            'facts' => collect(is_array($d->key_facts) ? $d->key_facts : [])->take(8)->values()->all(),
                        ])
                        ->filter(fn (array $r): bool => $r['facts'] !== [])
                );
            }
        } catch (\Throwable $e) {
            report($e); // 사양을 못 읽어도 판독 자체는 돌아야 한다.
        }

        return $specs->take(25)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OpsIntakeItem $i): array
    {
        return [
            'id' => $i->id,
            'category' => $i->category,
            'categoryLabel' => $i->categoryLabel(),
            'confidence' => $i->confidence,
            'summary' => $i->summary,
            'raw' => $i->raw_text,
            'speaker' => $i->speaker,
            'occurredOn' => $i->occurred_on?->toDateString(),
            'targetType' => $i->target_type,
            'targetCode' => $i->target_code,
            'targetName' => $i->target_name,
            'proposed' => $i->proposed ?: [],
            'question' => $i->question,
            'conflict' => $i->conflict ?: null,
            'status' => $i->status,
            'batchId' => $i->ops_intake_batch_id,
            'previous' => $i->previous ?: [],
            'appliedAt' => $i->applied_at?->toDateTimeString(),
            'resultNote' => $i->result_note,
        ];
    }
}
