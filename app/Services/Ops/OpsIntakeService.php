<?php

namespace App\Services\Ops;

use App\Http\Controllers\OpsPhotoController;
use App\Jobs\AnalyzeOpsIntakeJob;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Procurement\ProcurementService;
use App\Services\Wbs\WbsService;
use App\Support\ImageDownscale;
use App\Support\ImageParts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

    private const CATEGORIES = ['progress', 'plan', 'procurement', 'labor', 'expense', 'issue', 'noise'];

    public function __construct(
        private readonly OpsIntakeAnalyzer $analyzer,
        private readonly OpsLearningService $learning,
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

        $activities = $this->activityContext($site);
        $purchases = $this->purchaseContext($site);
        $today = Carbon::today()->toDateString();

        try {
            // 지금까지 축적된 현장 용어·오판 사례를 함께 넘긴다 — 쓸수록 정확해진다.
            $learned = $this->learning->promptBlock($site?->id);
            $raw = $this->analyzer->read($text, $activities->all(), $purchases->all(), $today, $images, $learned);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'AI 판독에 실패했습니다: '.$e->getMessage()];
        }

        $validCodes = $activities->pluck('code')->filter()->all();
        $validPos = $purchases->pluck('po')->filter()->all();

        // 붙여넣은 원문 전체를 근거로 보관한다 — 나중에 "왜 이렇게 반영됐지?"를 되짚을 수 있게.
        $batch = OpsIntakeBatch::create([
            'site_id' => $site?->id,
            'created_by_id' => $userId,
            'source' => $source,
            'communication_message_id' => $messageId,
            'raw_text' => $text,
            'image_count' => count($images),
        ]);

        $saved = [];
        foreach ($raw as $r) {
            $item = $this->persist($r, $site, $userId, $source, $messageId, $validCodes, $validPos, $text, $batch->id);
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
            $learned = $this->learning->promptBlock($site?->id);

            $raw = $this->analyzer->read(
                $text,
                $activities->all(),
                $purchases->all(),
                Carbon::today()->toDateString(),
                $images,
                $learned,
            );

            $validCodes = $activities->pluck('code')->filter()->all();
            $validPos = $purchases->pluck('po')->filter()->all();

            $saved = [];
            foreach ($raw as $r) {
                $item = $this->persist($r, $site, $batch->created_by_id, $batch->source, $batch->communication_message_id, $validCodes, $validPos, $text, $batch->id);
                if ($item !== null) {
                    $saved[] = $item;
                }
            }

            $items = collect($saved);
            $batch->update([
                'parsed_count' => $items->count(),
                'actionable_count' => $items->where('category', '!=', 'noise')->count(),
                'noise_count' => $items->where('category', 'noise')->count(),
                'status' => 'done',
                'error' => null,
                'analyzed_at' => now(),
            ]);

            $this->discardPhotos($batch);
        } catch (\Throwable $e) {
            report($e);
            $batch->update(['status' => 'failed', 'error' => $e->getMessage(), 'analyzed_at' => now()]);
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

    /** 판독이 끝나면 원본 사진은 지운다 — 근거는 원문 텍스트와 판독 항목으로 남는다. */
    private function discardPhotos(OpsIntakeBatch $batch): void
    {
        $paths = is_array($batch->photo_paths) ? $batch->photo_paths : [];
        if ($paths === []) {
            return;
        }

        try {
            Storage::disk((string) ($batch->photo_disk ?: OpsPhotoController::disk()))->delete($paths);
        } catch (\Throwable $e) {
            Log::warning('상황실 사진 정리 실패: '.$e->getMessage());
        }

        $batch->update(['photo_paths' => null]);
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
     * 제안을 실제 공정표·조달에 반영한다.
     *
     * 반영 전 값을 함께 저장해 되돌릴 수 있게 한다. TBM 게이트 등 기존 업무 규칙에 걸리면
     * 그 사유를 그대로 돌려주고 반영하지 않는다(규칙을 우회하지 않는다).
     *
     * @param  array<string, mixed>|null  $overrides  사람이 값을 고쳐서 적용할 때
     * @return array<string, mixed>
     */
    public function apply(int $id, ?array $overrides = null, ?int $userId = null): array
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

        return $item->target_type === 'procurement'
            ? $this->applyProcurement($item, $patch, $userId)
            : $this->applyWbs($item, $patch, $userId);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function applyWbs(OpsIntakeItem $item, array $patch, ?int $userId): array
    {
        $wbs = WbsItem::query()->where('wbs_code', $item->target_code)->first();
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

        $item->update([
            'status' => 'applied',
            'previous' => $previous,
            'proposed' => $patch,
            'applied_at' => now(),
            'applied_by_id' => $userId,
            'result_note' => '공정표 반영 완료',
        ]);

        return ['success' => true, 'target' => $item->target_code, 'applied' => $clean];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function applyProcurement(OpsIntakeItem $item, array $patch, ?int $userId): array
    {
        // 제안의 대상 코드는 PO 번호다 — 실제 갱신은 project_code + wbs_code 로 이뤄진다.
        $po = ProcurementItem::query()->where('po_no', $item->target_code)->first();
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
            'result_note' => '조달 반영 완료',
        ]);

        return ['success' => true, 'target' => $item->target_code, 'applied' => $clean];
    }

    /**
     * 확신도가 높고 대상이 확실한 제안을 한 번에 반영한다(확인 필요 항목은 건너뛴다).
     *
     * @return array<string, mixed>
     */
    public function applyAll(?int $siteId = null, ?int $userId = null): array
    {
        $rows = OpsIntakeItem::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
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

        $res = $item->target_type === 'procurement'
            ? $this->applyProcurement($item, $previous, $userId)
            : $this->applyWbs($item, $previous, $userId);

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
     * AI 결과 1건을 검증해 저장한다. 지어낸 대상 코드는 버리고 되물음으로 돌린다.
     *
     * @param  array<string, mixed>  $r
     * @param  array<int, string>  $validCodes
     * @param  array<int, string>  $validPos
     */
    private function persist(array $r, ?Site $site, ?int $userId, string $source, ?int $messageId, array $validCodes, array $validPos, string $fallbackText, ?int $batchId = null): ?OpsIntakeItem
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
            $known = $targetType === 'procurement'
                ? in_array($targetCode, $validPos, true)
                : in_array($targetCode, $validCodes, true);
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
            $question = '어느 작업/발주 건인지 확인해 주세요. (AI 가 대상을 특정하지 못했습니다)';
        }

        // 잡담은 기록만 하고 목록에서 빠진다. 그 외는 확신도·대상 유무로 상태를 정한다.
        $status = 'pending';
        if ($category === 'noise') {
            $status = 'ignored';
        } elseif ($question !== '' || $confidence < self::LOW_CONFIDENCE || ($targetCode === '' && $proposed !== [])) {
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
            'status' => $status,
        ]);
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
        return WbsItem::query()
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
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
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseContext(?Site $site): Collection
    {
        return ProcurementItem::query()
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->whereNotNull('po_no')->where('po_no', '!=', '')
            ->latest()->limit(100)->get()
            ->map(fn (ProcurementItem $p) => [
                'po' => (string) $p->po_no,
                'vendor' => (string) $p->vendor,
                'eta' => $p->eta?->toDateString() ?? '',
            ]);
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
            'status' => $i->status,
            'batchId' => $i->ops_intake_batch_id,
            'previous' => $i->previous ?: [],
            'appliedAt' => $i->applied_at?->toDateTimeString(),
            'resultNote' => $i->result_note,
        ];
    }
}
