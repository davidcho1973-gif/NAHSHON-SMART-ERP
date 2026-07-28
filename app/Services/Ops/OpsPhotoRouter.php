<?php

namespace App\Services\Ops;

use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Facades\Log;

/**
 * 올라온 사진이 "무엇인지" 먼저 가려낸다.
 *
 * 예전에는 사진과 글을 한 번에 판독기에 넣었다. 프롬프트가 "현장 대화"를 전제로 짜여 있어서
 * 글 없이 사진만 올리면 빈 대화를 읽으라는 꼴이 됐고, 결과가 비거나 대상 없이 되물음으로만
 * 끝났다 — 사용자 눈에는 "분석을 못 한다" 로 보였다.
 *
 * 그래서 판독을 두 단계로 나눈다.
 *   1단계(여기): 가볍게 종류만 가른다 — 영수증 / 납품서 / 시공 / 안전 / 출역명부 / 문서
 *   2단계: 종류별 전문 판독기에 넘긴다(이미 시스템에 있는 것들을 재사용한다)
 *
 * 종류를 알면 도착 모듈도 정해진다. 이것이 상황실이 "모든 정보가 모이는 곳" 이 되는 방식이다.
 */
class OpsPhotoRouter
{
    /** 영수증 — 재무(지출) + 문서함 증빙 */
    public const KIND_RECEIPT = 'receipt';

    /** 납품서·송장·발주서 — 조달(입고) + 문서함 증빙 */
    public const KIND_DELIVERY = 'delivery';

    /** 시공 사진 — 공정(진행률) */
    public const KIND_PROGRESS = 'progress';

    /** 안전 위험 — 이슈 */
    public const KIND_SAFETY = 'safety';

    /** 출역 명부·인원 사진 — 인원 보고 */
    public const KIND_LABOR = 'labor';

    /** 도면·계약서 등 문서 — 문서함 */
    public const KIND_DOCUMENT = 'document';

    /** 판별 불가 — 대화 판독기로 넘겨 일반 처리 */
    public const KIND_OTHER = 'other';

    public const KIND_LABELS = [
        self::KIND_RECEIPT => '영수증',
        self::KIND_DELIVERY => '납품서·송장',
        self::KIND_PROGRESS => '시공 사진',
        self::KIND_SAFETY => '안전 위험',
        self::KIND_LABOR => '출역 명부',
        self::KIND_DOCUMENT => '문서',
        self::KIND_OTHER => '기타',
    ];

    /** 원본을 증빙으로 영구 보관해야 하는 종류 — 나중에 돈·납품을 다툴 때 근거가 된다. */
    public const KEEP_AS_EVIDENCE = [self::KIND_RECEIPT, self::KIND_DELIVERY, self::KIND_DOCUMENT, self::KIND_SAFETY];

    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * 사진들의 종류를 한 번의 호출로 가려낸다(장당 호출하면 느리고 비싸다).
     *
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @return array<int, array{kind: string, label: string, confidence: int, summary: string}>
     */
    public function classify(array $images): array
    {
        if ($images === []) {
            return [];
        }

        try {
            $result = $this->engine->analyze($images, $this->prompt(count($images)), $this->schema());
            $rows = $result['data']['photos'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('상황실 사진 종류 판별 실패, 일반 판독으로 진행: '.$e->getMessage());
            $rows = [];
        }

        $out = [];
        foreach ($images as $i => $_) {
            $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
            $kind = (string) ($row['kind'] ?? '');
            $kind = array_key_exists($kind, self::KIND_LABELS) ? $kind : self::KIND_OTHER;

            $out[] = [
                'kind' => $kind,
                'label' => self::KIND_LABELS[$kind],
                'confidence' => max(0, min(100, (int) ($row['confidence'] ?? 0))),
                'summary' => trim((string) ($row['summary'] ?? '')),
            ];
        }

        return $out;
    }

    private function prompt(int $count): string
    {
        return <<<PROMPT
당신은 미국 내 플랜트/공장 설치현장의 문서 분류 담당자입니다.
사진 {$count}장이 순서대로 주어집니다. **각 사진이 무엇인지만** 가려내세요. 내용을 자세히
읽을 필요는 없습니다. JSON 만 반환하며, photos 배열의 길이는 반드시 {$count} 여야 하고
사진과 같은 순서여야 합니다.

## 종류(kind)
- receipt  : 영수증·카드전표. 상호·금액·결제 정보가 보이는 소매 영수증.
- delivery : 납품서·송장·거래명세서·발주서(PO). 품목과 수량이 표로 정리된 거래 서류.
- progress : 시공 현장 사진. 배관·트레이·덕트·전기 등 설치 상태가 찍힌 사진.
- safety   : 안전 위험이 주된 내용인 사진(개구부 미양생, 추락 위험, 정리불량, PPE 미착용).
- labor    : 출역 명부·출근부·인원 명단. 사람 이름과 인원수가 적힌 표나 손글씨 명단.
- document : 도면·계약서·시방서 등 그 밖의 서류.
- other    : 위 어디에도 확실히 속하지 않음.

## 규칙
- 애매하면 other 로 두고 confidence 를 낮게 주세요. **억지로 고르지 마세요.**
- 시공 사진에 사소한 위험이 보여도, 사진의 주된 목적이 시공 상태면 progress 입니다.
  safety 는 위험 자체를 알리려고 찍은 사진에만 씁니다.
- summary 는 한국어 한 줄로 짧게(예: "HILTI 앵커 납품서", "3층 트레이 포설 상태").
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'photos' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string'],
                            'confidence' => ['type' => 'integer'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['kind', 'confidence', 'summary'],
                    ],
                ],
            ],
            'required' => ['photos'],
        ];
    }
}
