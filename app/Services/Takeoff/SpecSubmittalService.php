<?php

namespace App\Services\Takeoff;

use App\Models\IntelligentDocument;
use App\Models\Submittal;
use App\Services\Ocr\OcrEngine;
use App\Support\AiMeter;
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

        return $this->persist($document, $items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, created: int, review: int, gates: int, rows: array<int, array<string, mixed>>}
     */
    private function persist(IntelligentDocument $document, array $items): array
    {
        $nextSeq = (int) Submittal::query()->where('project_id', $document->project_id)->max('seq');

        $created = 0;
        $review = 0;
        $gates = 0;
        $rows = [];

        foreach ($items as $raw) {
            $title = trim((string) ($raw['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $confidence = max(0, min(100, (int) ($raw['confidence'] ?? 0)));
            $excerpt = trim((string) ($raw['source_excerpt'] ?? ''));
            $category = (string) ($raw['category'] ?? '');
            $gate = (bool) ($raw['gate'] ?? false);

            $reasons = [];
            if ($confidence < self::LOW_CONFIDENCE) {
                $reasons[] = "확신도 {$confidence}";
            }
            if (! in_array($category, self::CATEGORIES, true)) {
                $reasons[] = '구분 불명';
                $category = 'Action 제출물';   // 가장 무거운 쪽으로 둔다 — 승인 필요로 보는 편이 안전하다
            }
            // 게이트는 원문 없이는 믿지 않는다. 잘못 판정하면 공사가 서거나 재시공이 된다.
            if ($gate && $excerpt === '') {
                $reasons[] = '정지 조항인데 원문 인용 없음';
            }
            if (! $gate && preg_match('/금지|승인 전|제출 전|not.{0,12}(begin|proceed|install)|prior to approval/iu', $title)) {
                $reasons[] = '정지 조항일 수 있음(문구에 금지 표현)';
            }

            $row = Submittal::create([
                'company_id' => $document->company_id,
                'site_id' => $document->site_id,
                'project_id' => $document->project_id,
                'seq' => ++$nextSeq,
                'csi' => Str::limit((string) ($raw['csi'] ?? ''), 18, ''),
                'section' => Str::limit((string) ($raw['section'] ?? ''), 90, ''),
                'category' => $category,
                'title' => Str::limit($title, 900, ''),
                'gate' => $gate,
                'status' => '미착수',
                'confidence' => $confidence,
                'needs_review' => $reasons !== [],
                'review_reason' => $reasons === [] ? null : Str::limit(implode(' · ', $reasons), 250, ''),
                'source_document_id' => $document->id,
                'extracted_by' => $this->engine->name(),
                'source_excerpt' => $excerpt !== '' ? Str::limit($excerpt, 1500, '') : null,
                'notes' => filled($raw['timing'] ?? null) ? '시점 조건: '.Str::limit((string) $raw['timing'], 200, '') : null,
            ]);

            $created++;
            if ($reasons !== []) {
                $review++;
            }
            if ($gate) {
                $gates++;
            }
            $rows[] = [
                'seq' => $row->seq, 'csi' => $row->csi, 'title' => Str::limit($row->title, 70),
                'category' => $row->category, 'gate' => $gate,
                'confidence' => $confidence, 'review' => $row->review_reason,
            ];
        }

        return ['success' => true, 'created' => $created, 'review' => $review, 'gates' => $gates, 'rows' => $rows];
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
