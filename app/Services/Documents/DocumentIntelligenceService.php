<?php

namespace App\Services\Documents;

use App\Models\DocumentActionItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\UnifiedAlert;
use App\Services\Alerts\UnifiedAlertService;
use App\Services\Communication\ChatDocumentReplyConnector;
use App\Services\Equipment\DocumentEquipmentConnector;
use App\Services\Finance\BillingInflowConnector;
use App\Services\Finance\DocumentExpenseConnector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentIntelligenceService
{
    public function __construct(
        private readonly DocumentIntelligenceAnalyzer $analyzer,
        private readonly UnifiedAlertService $alerts,
    ) {}

    public function process(IntelligentDocument $document): IntelligentDocument
    {
        $runToken = $document->ai_payload['analysis_run_token'] ?? null;
        $document->loadMissing(['company', 'site', 'project']);
        $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));

        if (! $disk->exists($document->file_path)) {
            throw new \RuntimeException('업로드된 원본 파일을 찾을 수 없습니다. 서버 배포로 저장소가 초기화됐을 수 있습니다 — 같은 파일을 다시 올리면 이 문서에 복원되어 분석이 재개됩니다.');
        }

        if ($runToken === null) {
            $document->update(['ai_status' => 'analyzing', 'ai_error' => null]);
        }
        $bytes = (string) $disk->get($document->file_path);
        $analysis = $this->analyzer->analyze($document, $bytes);
        $data = $analysis['data'];
        // These are application state, never instructions supplied by the AI response.
        unset($data['duplicate_document_id'], $data['duplicate_target_scope'], $data['duplicate_reason'], $data['scope_review_reason'], $data['analysis_run_token'], $data['stuck_requeues']);
        foreach (['analysis_run_token', 'stuck_requeues'] as $key) {
            if (array_key_exists($key, (array) $document->ai_payload)) {
                $data[$key] = $document->ai_payload[$key];
            }
        }
        $confidence = $this->confidence($data['confidence'] ?? null);

        // 두 번째 눈 — 돈·장비처럼 틀리면 장부가 틀어지는 문서만, 회사가 다른 모델이
        // 원본에서 독립적으로 다시 읽는다. 바깥 호출이므로 트랜잭션 밖에서 끝낸다.
        // 검증이 죽어도 분석은 살아야 하므로 서비스가 안에서 전부 삼킨다.
        $verification = app(DocumentCrossCheck::class)->check($document, $data, $bytes, $analysis);
        if ($verification !== null) {
            $data['verification'] = $verification;
        }

        $superseded = false;
        $fresh = DB::transaction(function () use ($document, $analysis, $data, $confidence, $runToken, &$superseded): IntelligentDocument {
            $document = IntelligentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($runToken !== null && ($document->ai_status !== 'analyzing' || ($document->ai_payload['analysis_run_token'] ?? null) !== $runToken)) {
                $superseded = true;

                return $document;
            }
            $scopes = app(DocumentScope::class);
            $resolution = $scopes->resolveForAnalysis($document, (string) ($data['project_code'] ?? ''));
            $scope = $resolution['scope'];
            $projectId = $scope['project_id'];
            if ($resolution['review_reason']) {
                $data['scope_review_reason'] = $resolution['review_reason'];
            }
            $category = $this->option((string) ($data['category'] ?? ''), IntelligentDocument::CATEGORY_OPTIONS, 'general');
            $documentType = $this->option((string) ($data['document_type'] ?? ''), IntelligentDocument::TYPE_OPTIONS, 'other');
            $documentDate = $this->date($data['document_date'] ?? null);
            $folderParts = $this->folderParts($document, $projectId, $category, $documentType, $documentDate, $data['folder_parts'] ?? []);
            $title = $this->cleanText($data['title'] ?? null) ?: pathinfo($document->original_file_name, PATHINFO_FILENAME);
            $keywords = $this->stringList($data['keywords'] ?? []);
            $tags = $this->stringList($data['tags'] ?? []);
            $keyFacts = $this->stringList($data['key_facts'] ?? []);
            $summary = $this->cleanText($data['summary'] ?? null);
            $extractedText = $analysis['extracted_text'];

            $attributes = [
                // 계약 문서 ↔ 계약 모듈 자동 링크 — 죽은 칼럼(project_contract_id)을 살린다.
                // 그 프로젝트의 계약이 하나일 때만: 애매하면 잇지 않는다(연계 점검: 계약서 자동 링크 없음).
                'project_contract_id' => $document->project_contract_id
                    ?: $this->resolveContractId($documentType, $projectId),
                'title' => $title,
                'category' => $category,
                'document_type' => $documentType,
                'discipline' => $this->cleanText($data['discipline'] ?? null),
                'direction' => $this->option((string) ($data['direction'] ?? ''), array_fill_keys(['incoming', 'outgoing', 'internal'], true), 'internal'),
                'document_number' => $this->cleanText($data['document_number'] ?? null),
                'revision' => $this->cleanText($data['revision'] ?? null),
                'sender' => $this->cleanText($data['sender'] ?? null),
                'recipients' => $this->stringList($data['recipients'] ?? []),
                'confidentiality' => $this->option((string) ($data['confidentiality'] ?? ''), array_fill_keys(['public', 'internal', 'confidential', 'restricted'], true), 'internal'),
                'folder_structure' => $folderParts,
                'virtual_path' => implode(' / ', $folderParts),
                'tags' => $tags,
                'keywords' => $keywords,
                'summary' => $summary,
                'key_facts' => $keyFacts,
                'extracted_text' => $extractedText,
                'document_date' => $documentDate,
                'effective_on' => $this->date($data['effective_on'] ?? null),
                'expires_on' => $this->date($data['expires_on'] ?? null),
                'response_due_on' => $this->date($data['response_due_on'] ?? null),
                'ai_status' => $confidence >= 70 && ! $resolution['review_reason'] ? 'ready' : 'review_required',
                'ai_engine' => $analysis['engine'],
                'ai_model' => $analysis['model'],
                'ai_confidence' => $confidence,
                'ai_payload' => $data,
                'ai_error' => null,
                'analyzed_at' => now(),
            ];

            $attributes['search_text'] = implode("\n", array_filter([
                $title,
                $document->original_file_name,
                $attributes['document_number'],
                $attributes['revision'],
                $attributes['sender'],
                $summary,
                implode(' ', $keywords),
                implode(' ', $tags),
                implode("\n", $keyFacts),
                $extractedText,
            ]));
            $document = $scopes->saveResolved($document, $scope, $attributes);
            $folderParts = $this->rebuildFolder($document);
            $document->update(['folder_structure' => $folderParts, 'virtual_path' => implode(' / ', $folderParts)]);

            // Preserve both originals and existing links. A duplicate/uncertain scope must
            // not replace action items, create expenses, send replies or harvest knowledge.
            if (! empty($document->ai_payload['duplicate_document_id']) || $resolution['review_reason']) {
                return $document->fresh(['company', 'site', 'project', 'actionItems']);
            }

            $this->organizeFile($document, $folderParts);
            $this->replaceActionItems($document, is_array($data['action_items'] ?? null) ? $data['action_items'] : []);

            // 문서가 가리키는 모듈로 흘려보낸다 — 분류·편철에서 끝나면 같은 내용을
            // 사람이 각 화면에 다시 입력해야 한다. 어느 쪽이 실패해도 분석 결과
            // 저장은 살아야 하므로 각각 삼킨다.
            try {
                // 돈이 나간 문서(영수증·인보이스·급여 지급 내역) → 재무(경비)
                DB::transaction(fn () => app(DocumentExpenseConnector::class)->sync($document));
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                // 들어오는 돈(입금 통지·발행 청구서) → 기성 수금 원장
                DB::transaction(fn () => app(BillingInflowConnector::class)->sync($document));
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                // 장비 임대·구매 문서 → 장비 대장(자재/장비·렌탈 화면)
                DB::transaction(fn () => app(DocumentEquipmentConnector::class)->sync($document));
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                // 채팅방에서 올라온 파일이면 그 자리에 결과를 알린다 — 결과가 보이지
                // 않으면 사람들은 자동화를 믿지 않고 각 화면에 다시 입력한다.
                DB::transaction(fn () => app(ChatDocumentReplyConnector::class)->sync($document));
            } catch (\Throwable $e) {
                report($e);
            }

            return $document->fresh(['company', 'site', 'project', 'actionItems']);
        });

        if ($superseded || ! empty($fresh->ai_payload['duplicate_document_id']) || ! empty($fresh->ai_payload['scope_review_reason'])) {
            return $fresh;
        }

        // 지식 창고 수확 — 임베딩(외부 호출)이 있어 트랜잭션 밖에서 돈다.
        // 실패해도 분석 결과는 이미 저장됐다: erp:harvest-knowledge 로 다시 수확하면 된다.
        try {
            app(KnowledgeKeeper::class)->harvest($fresh);
        } catch (\Throwable $e) {
            report($e);
        }

        return $fresh;
    }

    /** @param array<int, mixed> $items */
    private function replaceActionItems(IntelligentDocument $document, array $items): void
    {
        $previousIds = $document->actionItems()->pluck('id')->map(fn (int $id): string => (string) $id);
        if ($previousIds->isNotEmpty()) {
            UnifiedAlert::query()
                ->where('source_type', DocumentActionItem::class)
                ->whereIn('source_id', $previousIds->all())
                ->delete();
        }
        $document->actionItems()->delete();

        foreach (array_slice($items, 0, 30) as $raw) {
            if (! is_array($raw) || blank($raw['title'] ?? null)) {
                continue;
            }

            $dueAt = $this->dateTime($raw['due_on'] ?? null);
            $remindDays = max(0, min(90, (int) ($raw['remind_days_before'] ?? 7)));
            $severity = $this->option((string) ($raw['severity'] ?? ''), array_fill_keys(['critical', 'high', 'warning', 'normal'], true), 'normal');
            $item = $document->actionItems()->create([
                'company_id' => $document->company_id,
                'site_id' => $document->site_id,
                'project_id' => $document->project_id,
                'action_type' => $this->option((string) ($raw['action_type'] ?? ''), array_fill_keys([
                    'deadline', 'response', 'notice', 'compliance', 'safety', 'quality', 'financial',
                    'schedule', 'change', 'risk', 'missing_document',
                ], true), 'risk'),
                'related_module' => $this->cleanText($raw['related_module'] ?? null),
                'severity' => $severity,
                'status' => 'open',
                'title' => $this->cleanText($raw['title']) ?: '문서 후속조치',
                'details' => $this->cleanText($raw['details'] ?? null),
                'recommended_action' => $this->cleanText($raw['recommended_action'] ?? null),
                'source_excerpt' => $this->cleanText($raw['source_excerpt'] ?? null),
                'due_at' => $dueAt,
                'remind_at' => $dueAt?->copy()->subDays($remindDays),
                'confidence' => $this->confidence($raw['confidence'] ?? null),
                'payload' => $raw,
            ]);

            if (in_array($severity, ['critical', 'high', 'warning'], true) || ($dueAt && $dueAt->lte(now()->addDays(90)))) {
                $this->alerts->emit('document-action:'.$item->id, [
                    'company_id' => $item->company_id,
                    'site_id' => $item->site_id,
                    'project_id' => $item->project_id,
                    'source_module' => 'DOC',
                    'source_type' => DocumentActionItem::class,
                    'source_id' => (string) $item->id,
                    'event_type' => $item->action_type,
                    'severity' => in_array($severity, ['critical', 'high'], true) ? 'critical' : ($severity === 'warning' ? 'warning' : 'normal'),
                    'title' => $item->title,
                    'content' => $item->details ?: $item->recommended_action,
                    'action_url' => '/document-hub?document='.$document->id,
                    'due_at' => $dueAt,
                    'metadata' => ['document_id' => $document->id, 'recommended_action' => $item->recommended_action],
                ]);
            }
        }
    }

    /** @param array<int, string> $folderParts */
    private function organizeFile(IntelligentDocument $document, array $folderParts): void
    {
        $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));
        $physicalParts = collect($folderParts)->map(fn (string $part): string => $this->safeSegment($part))->filter()->all();
        $fileName = $document->uuid.'-'.$this->safeFileName($document->original_file_name);
        $newPath = 'document-intelligence/library/'.implode('/', $physicalParts).'/'.$fileName;

        if ($newPath === $document->file_path) {
            return;
        }

        $oldPath = $document->file_path;
        // Filesystem moves cannot roll back with PostgreSQL. Keep the original until all
        // result/action writes commit so a later failure cannot leave a dangling file_path.
        if (! $disk->copy($oldPath, $newPath)) {
            throw new \RuntimeException('AI 분류 폴더에 원본 사본을 저장하지 못했습니다.');
        }

        $document->update(['file_path' => $newPath, 'stored_file_name' => $fileName]);
        DB::afterCommit(function () use ($document, $disk, $oldPath, $newPath): void {
            try {
                if (IntelligentDocument::query()->whereKey($document->id)->value('file_path') === $newPath
                    && ! $disk->delete($oldPath)) {
                    throw new \RuntimeException('편철 완료 후 이전 원본 사본을 정리하지 못했습니다.');
                }
            } catch (\Throwable $e) {
                // The committed new path is valid; cleanup failure must not undo analysis.
                report($e);
            }
        });
    }

    /**
     * 사람이 프로젝트·분류를 고친 뒤 폴더를 같은 규칙으로 다시 만든다.
     *
     * 폴더는 AI 가 분석할 때 한 번 정해지고 그 뒤로 굳어 있었다. 그래서 «PROJECT 를
     * 잘못 잡았다» 는 것을 나중에 알아도 문서는 계속 GLOBAL/GENERAL 밑에 남았다 —
     * 목록에서 프로젝트를 바꿔도 폴더 경로는 옛 프로젝트를 가리켜, 어느 쪽이 맞는지
     * 화면만 봐서는 알 수 없었다. 고친 값으로 폴더를 다시 만들어야 그 어긋남이 사라진다.
     *
     * AI 가 제안했던 하위 폴더(분류·유형 아래 단계)는 그대로 살린다 — 사람이 고친 것은
     * «어느 프로젝트인가» 이지 «어떤 서랍에 넣을 것인가» 가 아니기 때문이다.
     *
     * @return array<int, string>
     */
    public function rebuildFolder(IntelligentDocument $document): array
    {
        // 프로젝트·현장을 방금 바꿨다면 관계가 옛 값을 물고 있다 — 다시 읽어야 한다.
        $document->unsetRelation('company')->unsetRelation('site')->unsetRelation('project');
        $document->loadMissing(['company:id,name,code', 'site:id,code', 'project:id,project_code']);

        $aiParts = $document->ai_payload['folder_parts'] ?? [];

        return $this->folderParts(
            $document,
            $document->project_id,
            (string) $document->category,
            (string) $document->document_type,
            $document->document_date?->toDateString(),
            $aiParts,
        );
    }

    /** @param mixed $aiParts @return array<int, string> */
    private function folderParts(
        IntelligentDocument $document,
        ?int $projectId,
        string $category,
        string $type,
        ?string $documentDate,
        mixed $aiParts,
    ): array {
        $project = $projectId ? Project::query()->find($projectId) : null;
        $company = $document->company?->code ?: $document->company?->name ?: 'GLOBAL';
        $projectCode = $project?->project_code ?: $document->site?->code ?: 'GENERAL';
        $suggested = $this->stringList(is_array($aiParts) ? $aiParts : []);

        return array_values(array_unique(array_filter([
            $company,
            $projectCode,
            $suggested[0] ?? $category,
            $suggested[1] ?? $type,
            $suggested[2] ?? (string) ($documentDate ? Carbon::parse($documentDate)->year : now()->year),
        ])));
    }

    /**
     * 계약류 문서 → 계약 모듈. 프로젝트에 계약이 정확히 하나일 때만 잇는다.
     */
    private function resolveContractId(string $documentType, ?int $projectId): ?int
    {
        if ($projectId === null
            || ! in_array($documentType, ['contract', 'change_order', 'amendment', 'lien_waiver', 'pay_application'], true)) {
            return null;
        }

        try {
            $ids = ProjectContract::query()
                ->where('project_id', $projectId)
                ->limit(2)
                ->pluck('id');

            return $ids->count() === 1 ? (int) $ids->first() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $options */
    private function option(string $value, array $options, string $default): string
    {
        $value = strtolower(trim($value));

        return array_key_exists($value, $options) ? $value : $default;
    }

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $parsed = $this->dateTime($value);

        return $parsed?->toDateString();
    }

    private function dateTime(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function confidence(mixed $value): float
    {
        return round(max(0, min(100, is_numeric($value) ? (float) $value : 0)), 2);
    }

    private function safeSegment(string $value): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : 'folder-'.substr(hash('sha256', $value), 0, 10);
    }

    private function safeFileName(string $value): string
    {
        $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($value, PATHINFO_FILENAME)) ?: 'document';

        return $base.($extension ? '.'.$extension : '');
    }
}
