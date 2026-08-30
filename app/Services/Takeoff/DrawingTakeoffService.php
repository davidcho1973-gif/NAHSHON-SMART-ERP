<?php

namespace App\Services\Takeoff;

use App\Models\BoqItem;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Services\Ocr\OcrEngine;
use App\Support\AiMeter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 도면에서 물량을 뽑아 <b>대장에 바로 꽂는다</b>.
 *
 * ── 왜 승인 대기줄을 두지 않는가 ─────────────────────────────────────────
 * 한 현장이 400줄이 넘는다. 그걸 하나씩 승인하는 화면은 아무도 끝까지 누르지 않고,
 * 결국 통째로 [전체 승인]을 눌러 검토가 형식이 된다. 그래서 반대로 간다 —
 * <b>확신이 서는 줄은 그냥 들어가고, 애매한 줄만 표시</b>한다. 사람은 표시된
 * 몇 줄만 보면 되고, 그게 실제로 지켜지는 검토다.
 *
 * ── 무엇을 "애매하다"고 보는가 ──────────────────────────────────────────
 * 1. AI 가 스스로 확신도를 낮게 매긴 것(70 미만)
 * 2. 근거 치수를 대지 못한 것 — 숫자는 냈는데 어디서 나왔는지 못 말하면 믿을 수 없다
 * 3. 수량이 0 이거나 단위가 비어 있는 것
 * 4. 같은 도면·같은 품목이 이미 대장에 있는데 수량이 다른 것(이중 계상 위험)
 *
 * 물량은 돈이다. 그래서 틀렸을 때 조용한 쪽(그냥 들어감)이 아니라 시끄러운
 * 쪽(표시)이 기본이어야 한다.
 */
class DrawingTakeoffService
{
    /** 이 아래면 사람이 본다. */
    private const LOW_CONFIDENCE = 70;

    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * 도면 한 장을 판독해 BOQ 행을 만든다.
     *
     * @return array{success: bool, error?: string, created?: int, review?: int, rows?: array<int, array<string, mixed>>}
     */
    public function extract(IntelligentDocument $document, ?string $disciplineHint = null): array
    {
        $bytes = $this->fileOf($document);
        if ($bytes === null) {
            return ['success' => false, 'error' => '도면 원본을 찾을 수 없습니다.'];
        }

        // 넣을 곳부터 정한다. 물량 대장은 <b>프로젝트</b>로 갈라져 있어서, 프로젝트가
        // 없는 줄은 어느 화면에서도 보이지 않는다 — 넣었다고 말해 놓고 찾을 수 없는
        // 것이 가장 나쁘다. 그래서 보이지 않을 곳에는 아예 넣지 않는다.
        $project = TakeoffTarget::resolve($document);
        if ($project === null) {
            return ['success' => false, 'error' => TakeoffTarget::reason($document)];
        }

        $startedAt = microtime(true);
        try {
            $result = $this->engine->analyze(
                [['data' => base64_encode($bytes), 'mime_type' => (string) ($document->mime_type ?: 'application/pdf')]],
                $this->prompt($document, $disciplineHint),
                $this->schema(),
            );
        } catch (Throwable $e) {
            AiMeter::record($this->engine->name(), 'takeoff', null,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                ok: false, error: $e->getMessage(),
                subjectType: 'document', subjectId: $document->id);

            return ['success' => false, 'error' => '도면 판독 실패: '.$e->getMessage()];
        }

        $items = is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
        if ($items === []) {
            return ['success' => true, 'created' => 0, 'review' => 0, 'rows' => [],
                'error' => '이 도면에서는 수량을 읽어내지 못했습니다.'];
        }

        return $this->persist($document, $items, (string) ($result['model'] ?? ''), $project);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, created: int, review: int, rows: array<int, array<string, mixed>>}
     */
    private function persist(IntelligentDocument $document, array $items, string $model, Project $project): array
    {
        $nextSeq = (int) BoqItem::query()
            ->where('project_id', $project->id)
            ->max('seq');

        $created = 0;
        $review = 0;
        $rows = [];

        foreach ($items as $raw) {
            $name = trim((string) ($raw['name'] ?? ''));
            $qty = (float) ($raw['qty'] ?? 0);
            $unit = trim((string) ($raw['unit'] ?? ''));
            if ($name === '') {
                continue;
            }

            $confidence = max(0, min(100, (int) ($raw['confidence'] ?? 0)));
            $basisText = trim((string) ($raw['basis'] ?? ''));

            $reasons = [];
            if ($confidence < self::LOW_CONFIDENCE) {
                $reasons[] = "확신도 {$confidence}";
            }
            if ($basisText === '') {
                $reasons[] = '근거 치수 없음';
            }
            if ($qty <= 0 || $unit === '') {
                $reasons[] = '수량·단위 불완전';
            }

            // 같은 프로젝트에 같은 품목이 이미 있는데 수량이 다르면 이중 계상 위험이 있다.
            $twin = BoqItem::query()
                ->where('project_id', $project->id)
                ->where('name_kr', $name)
                ->first();
            if ($twin && abs((float) $twin->qty - $qty) > 0.01) {
                $reasons[] = "기존 {$twin->qty}{$twin->unit} 과 다름";
            }

            $row = BoqItem::create([
                'company_id' => $document->company_id,
                // 대장은 프로젝트·현장으로 갈라 보므로 둘 다 목적지 기준으로 맞춘다.
                'site_id' => $project->site_id ?: $document->site_id,
                'project_id' => $project->id,
                'seq' => ++$nextSeq,
                'discipline_code' => (string) ($raw['discipline_code'] ?? ''),
                'discipline' => (string) ($raw['discipline'] ?? ''),
                'name_kr' => $name,
                'name_en' => (string) ($raw['name_en'] ?? ''),
                'spec' => Str::limit((string) ($raw['spec'] ?? ''), 480, ''),
                'unit' => $unit,
                'qty' => $qty,
                // 도면에서 읽은 값임을 명시한다 — 문서확정(계약서 확정)과 구별해야 한다.
                'qty_basis' => '도면판독',
                'unit_price' => 0,
                'source' => (string) ($document->document_number ?: $document->title ?: $document->original_file_name),
                'source_document_id' => $document->id,
                'extracted_by' => $this->engine->name(),
                'confidence' => $confidence,
                'needs_review' => $reasons !== [],
                'review_reason' => $reasons === [] ? null : Str::limit(implode(' · ', $reasons), 250, ''),
                'note' => $basisText !== '' ? Str::limit($basisText, 480, '') : null,
            ]);

            $created++;
            if ($reasons !== []) {
                $review++;
            }
            $rows[] = [
                'seq' => $row->seq, 'name' => $row->name_kr,
                'qty' => (float) $row->qty, 'unit' => $row->unit,
                'confidence' => $confidence, 'review' => $row->review_reason,
            ];
        }

        return ['success' => true, 'created' => $created, 'review' => $review, 'rows' => $rows,
            'model' => $model, 'project' => $project->name, 'projectCode' => $project->project_code];
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

    private function prompt(IntelligentDocument $document, ?string $hint): string
    {
        return implode("\n", array_filter([
            '당신은 건설 물량산출(QTO) 전문가입니다. 첨부한 도면에서 시공 물량을 뽑아 주세요.',
            '',
            "도면: {$document->title}",
            $document->document_number ? "도면번호: {$document->document_number}" : null,
            $hint ? "공종 힌트: {$hint}" : null,
            '',
            '## 반드시 지킬 것',
            '1. 도면에 <b>실제로 표기된 치수·개수</b>만 씁니다. 보이지 않는 값을 추정하지 마세요.',
            '2. 각 항목마다 basis 에 <b>어디서 그 숫자가 나왔는지</b> 적으세요.',
            '   예: "서측 벽 21\'-11.5\" + 남측 10\'-6.9\" 합산", "평면도 기기 심볼 12개 계수".',
            '   근거를 댈 수 없으면 그 항목은 넣지 마세요.',
            '3. confidence 는 스스로에게 정직하게 매기세요. 치수가 흐리거나 축척이 불확실하면 낮게.',
            '   틀린 수량이 그대로 발주되면 돈이 나갑니다 — 낮게 매기는 편이 낫습니다.',
            '4. 단위는 현장 표기를 씁니다: LF(연장 피트), SF(제곱피트), EA(개), CY(입방야드), LS(일식).',
            '5. 같은 품목이 여러 구역에 있으면 합쳐서 한 줄로, basis 에 구역별 내역을 적으세요.',
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
                            'discipline_code' => ['type' => 'string'],
                            'discipline' => ['type' => 'string'],
                            'name_kr' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'name_en' => ['type' => 'string'],
                            'spec' => ['type' => 'string'],
                            'unit' => ['type' => 'string'],
                            'qty' => ['type' => 'number'],
                            'basis' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer'],
                        ],
                        'required' => ['name', 'unit', 'qty', 'basis', 'confidence'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }
}
