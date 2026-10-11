<?php

namespace App\Services\Takeoff;

use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Submittal;
use App\Services\Ocr\OcrEngine;
use App\Support\AiMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 시방서에서 "제출하라"는 요구를 전수로 뽑아 대장에 꽂는다.
 *
 * 703K 에서 사람이 15개 공종을 읽어 276건을 골라낸 그 판단을 코드로 옮긴 것이다.
 * 물량과 같은 규칙 — <b>바로 대장에 넣고, 애매한 것만 표시</b>한다.
 *
 * ── 어려운 것은 찾기가 아니라 가리기다 ─────────────────────────────────
 * "submit" 이라는 낱말을 세는 일이 아니다. 세 가지를 판단해야 한다:
 *   1. 종류 — 승인이 필요한가(Action), 참고인가(Informational), 준공서류인가(Closeout)
 *   2. <b>정지 조항인가</b> — "승인 전 시공 금지" 같은 문장. 이걸 잘못 보면 양쪽으로
 *      사고가 난다. 아닌 것을 게이트로 보면 공사가 서고, 게이트를 놓치면 승인 없이
 *      시공했다가 재시공이 된다. 그래서 게이트는 <b>원문 인용</b>을 반드시 함께 받는다.
 *   3. 시점 조건 — "타설 28일 후", "21일 전 통보" 같은 것. 검사 일정의 뿌리가 된다.
 */
class SpecSubmittalService
{
    private const LOW_CONFIDENCE = 70;

    /** 대장이 쓰는 구분 — 이 밖의 값이 오면 사람이 본다. */
    private const CATEGORIES = ['Action 제출물', 'Informational 제출물', 'Closeout 제출물', '품질보증(QA)', '시험·검사'];

    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * 시방서 한 부를 읽어 제출물 행을 만든다.
     *
     * @return array{success: bool, error?: string, created?: int, review?: int, gates?: int, rows?: array<int, array<string, mixed>>}
     */
    public function extract(IntelligentDocument $document): array
    {
        $bytes = $this->fileOf($document);
        if ($bytes === null) {
            return ['success' => false, 'error' => '시방서 원본을 찾을 수 없습니다.'];
        }

        // 제출물 대장도 프로젝트로 갈라 본다 — 목적지를 못 정하면 넣지 않는다.
        $project = TakeoffTarget::resolve($document);
        if ($project === null) {
            return ['success' => false, 'error' => TakeoffTarget::reason($document)];
        }

        $sourceHash = hash('sha256', $bytes);
        if (filled($document->sha256) && ! hash_equals(strtolower($document->sha256), $sourceHash)) {
            return ['success' => false, 'error' => '등록된 시방서와 실제 원본이 다릅니다. 새 개정 문서로 등록하세요.'];
        }
        $previousHash = data_get($document->ai_payload, 'submittal_extraction.sourceSha256');
        if ($previousHash && $previousHash !== $sourceHash) {
            return ['success' => false, 'error' => '이미 추출한 시방서의 원본이 변경되었습니다. 기존 승인 이력을 보존하도록 새 개정 문서로 등록하세요.'];
        }
        if ($cached = $this->cachedExtraction($document, $project, $sourceHash)) {
            return $cached;
        }

        $startedAt = microtime(true);
        try {
            $result = $this->engine->analyze(
                [['data' => base64_encode($bytes), 'mime_type' => (string) ($document->mime_type ?: 'application/pdf')]],
                $this->prompt($document),
                $this->schema(),
            );
        } catch (Throwable $e) {
            AiMeter::record($this->engine->name(), 'spec_submittals', null,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                ok: false, error: $e->getMessage(),
                subjectType: 'document', subjectId: $document->id);

            return ['success' => false, 'error' => '시방 판독 실패: '.$e->getMessage()];
        }

        $items = is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
        if ($items === []) {
            return ['success' => true, 'created' => 0, 'review' => 0, 'gates' => 0, 'rows' => [],
                'error' => '이 문서에서는 제출물 요구를 찾지 못했습니다. 시방서가 맞는지 확인해 주세요.'];
        }

        return $this->persist($document, $items, $project, $sourceHash);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, created: int, review: int, gates: int, rows: array<int, array<string, mixed>>}
     */
    private function persist(IntelligentDocument $document, array $items, Project $project, string $sourceHash): array
    {
        // Serialize the project sequence and source import. A retry adds evidence once,
        // rather than appending another register or overwriting an approved requirement.
        return DB::transaction(function () use ($document, $items, $project, $sourceHash): array {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $document = IntelligentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($cached = $this->cachedExtraction($document, $project, $sourceHash)) {
                return $cached;
            }
            $freshBytes = $this->fileOf($document);
            if ($freshBytes === null || hash('sha256', $freshBytes) !== $sourceHash) {
                return ['success' => false, 'error' => '판독 중 시방서 원본이 변경되었습니다. 새 개정 문서로 등록하세요.'];
            }
            $nextSeq = (int) Submittal::query()->where('project_id', $project->id)->max('seq');
            $existing = Submittal::query()->where('project_id', $project->id)
                ->where('source_document_id', $document->id)->get()
                ->keyBy(fn (Submittal $row) => $this->requirementKey($row->csi, $row->category, $row->source_excerpt, $row->title));
            $created = 0;
            $rowIds = [];
            foreach ($items as $raw) {
                $title = trim((string) ($raw['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $confidence = max(0, min(100, (int) ($raw['confidence'] ?? 0)));
                $excerpt = Str::limit(trim((string) ($raw['source_excerpt'] ?? '')), 1500, '');
                $category = (string) ($raw['category'] ?? '');
                $gate = (bool) ($raw['gate'] ?? false);
                $reasons = [];
                if ($confidence < self::LOW_CONFIDENCE) {
                    $reasons[] = "확신도 {$confidence}";
                }
                if (! in_array($category, self::CATEGORIES, true)) {
                    $reasons[] = '구분 불명';
                    $category = 'Action 제출물';
                }
                if ($excerpt === '') {
                    $reasons[] = '원문 인용 없음';
                }
                if (! $gate && preg_match('/금지|승인 전|제출 전|not.{0,12}(begin|proceed|install)|prior to approval/iu', $title)) {
                    $reasons[] = '정지 조항일 수 있음(문구에 금지 표현)';
                }
                $csi = Str::limit((string) ($raw['csi'] ?? ''), 18, '');
                $title = Str::limit($title, 900, '');
                $key = $this->requirementKey($csi, $category, $excerpt, $title);
                $row = $existing->get($key);
                if (! $row) {
                    $row = Submittal::create([
                        'company_id' => $document->company_id,
                        'site_id' => $project->site_id ?: $document->site_id,
                        'project_id' => $project->id,
                        'seq' => ++$nextSeq,
                        'csi' => $csi,
                        'section' => Str::limit((string) ($raw['section'] ?? ''), 90, ''),
                        'category' => $category,
                        'title' => $title,
                        'gate' => $gate,
                        'status' => '미착수',
                        'confidence' => $confidence,
                        'needs_review' => $reasons !== [],
                        'review_reason' => $reasons === [] ? null : Str::limit(implode(' · ', $reasons), 250, ''),
                        'source_document_id' => $document->id,
                        'extracted_by' => $this->engine->name(),
                        'source_excerpt' => $excerpt !== '' ? $excerpt : null,
                        'notes' => filled($raw['timing'] ?? null) ? '시점 조건: '.Str::limit((string) $raw['timing'], 200, '') : null,
                    ]);
                    $existing->put($key, $row);
                    $created++;
                }
                $rowIds[$key] = $row->id;
            }
            $payload = $document->ai_payload ?? [];
            $payload['submittal_extraction'] = [
                'sourceSha256' => $sourceHash, 'projectId' => $project->id,
                'requirements' => $rowIds, 'extractedAt' => now()->toIso8601String(),
            ];
            $document->forceFill(['ai_payload' => $payload])->save();

            return $this->extractionResult($project, array_values($rowIds), $created, false);
        });
    }

    private function requirementKey(?string $csi, ?string $category, ?string $excerpt, ?string $title): string
    {
        $normalize = fn (?string $text): string => mb_strtolower((string) preg_replace('/[\p{P}\p{Z}\s]+/u', '', trim($text ?? '')));

        // Keep separate obligations even when the model quotes the same paragraph for both.
        return hash('sha256', $normalize($csi).'|'.$normalize($category).'|'.$normalize($excerpt).'|'.$normalize($title));
    }

    private function cachedExtraction(IntelligentDocument $document, Project $project, string $sourceHash): ?array
    {
        // Identical bytes imported again in the same project reuse the original requirement IDs.
        // A new revision (different bytes) remains a separate source and cannot inherit approval.
        $source = IntelligentDocument::query()
            ->where('ai_payload->submittal_extraction->projectId', $project->id)
            ->where('ai_payload->submittal_extraction->sourceSha256', $sourceHash)
            ->orderBy('id')->first();
        if (! $source) {
            return null;
        }
        $ids = array_values((array) data_get($source->ai_payload, 'submittal_extraction.requirements', []));
        if ($ids === [] || Submittal::where('project_id', $project->id)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            return null;
        }

        return $this->extractionResult($project, $ids, 0, true);
    }

    private function extractionResult(Project $project, array $ids, int $created, bool $reused): array
    {
        $records = Submittal::query()->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('seq')->get();

        return [
            'success' => true, 'created' => $created, 'reused' => $reused,
            'review' => $records->where('needs_review', true)->count(),
            'gates' => $records->where('gate', true)->count(),
            'rows' => $records->map(fn (Submittal $row) => [
                'seq' => $row->seq, 'csi' => $row->csi, 'title' => Str::limit($row->title, 70),
                'category' => $row->category, 'gate' => $row->gate,
                'confidence' => $row->confidence, 'review' => $row->review_reason,
            ])->all(),
            'project' => $project->name, 'projectCode' => $project->project_code,
        ];
    }

    private function fileOf(IntelligentDocument $document): ?string
    {
        try {
            $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));

            return $disk->exists((string) $document->file_path)
                ? (string) $disk->get((string) $document->file_path)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function prompt(IntelligentDocument $document): string
    {
        return implode("\n", array_filter([
            '당신은 건설 계약관리 전문가입니다. 첨부한 시방서에서 <b>발주처에 제출해야 하는 것</b>을 전부 찾아 주세요.',
            '',
            "문서: {$document->title}",
            $document->document_number ? "문서번호: {$document->document_number}" : null,
            '',
            '## 무엇을 찾는가',
            'Submittals, Product Data, Shop Drawings, Samples, Test Reports, Certificates, Qualifications,',
            'Closeout/O&M, Warranty, Mock-up, Field Quality Control — 제출·승인·시험·검사를 요구하는 모든 조항.',
            '',
            '## category 는 다음 다섯 중 하나',
            '- Action 제출물 : 설계사·발주처 <b>승인</b>이 필요한 것(제품자료·제작도·샘플)',
            '- Informational 제출물 : 정보 제공용, 승인 불요',
            '- Closeout 제출물 : 준공 시 인계(O&M·보증서·준공도서)',
            '- 품질보증(QA) : 자격·인증·실적 증빙',
            '- 시험·검사 : 현장 시험·검측 요구',
            '',
            '## gate — 가장 중요한 판단',
            'gate=true 는 <b>이것을 통과하지 못하면 다음 공정을 할 수 없는</b> 조항입니다.',
            '예: "승인 전 발주 금지", "결과 제출·승인 전 작업 착수 금지", "검사·승인 전 은폐 금지",',
            '     "입회 없이 타설 금지".',
            'gate=true 로 표시할 때는 <b>반드시 source_excerpt 에 그 금지 문장을 원문 그대로</b> 넣으세요.',
            '원문을 인용할 수 없으면 gate 를 false 로 두고 confidence 를 낮추세요 —',
            '잘못된 게이트는 공사를 세우고, 놓친 게이트는 재시공을 부릅니다.',
            '',
            '## 그 밖의 규칙',
            '- title 은 무엇을 내야 하는지 한 줄로. 조항 번호가 있으면 끝에 (3.1.C.4) 처럼 붙이세요.',
            '- timing 에 시점 조건을 적으세요("타설 28일 경과 후", "작업 21일 전 통보").',
            '- csi 는 "09 6519" 같은 번호, section 은 그 이름("비닐타일 바닥").',
            '- confidence 는 정직하게. 조항이 흐릿하거나 해석이 갈리면 낮게 매기세요.',
            '- source_excerpt 에 각 요구의 원문 조항을 그대로 인용하세요. 번역하거나 다른 요구의 문장을 합치지 마세요.',
            '- 같은 요구가 여러 번 나오면 한 줄로 합치세요.',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'csi' => ['type' => 'string'],
                            'section' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'gate' => ['type' => 'boolean'],
                            'source_excerpt' => ['type' => 'string'],
                            'timing' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer'],
                        ],
                        'required' => ['category', 'title', 'gate', 'confidence'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }
}
