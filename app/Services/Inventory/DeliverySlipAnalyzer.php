<?php

namespace App\Services\Inventory;

use App\Services\Ocr\OcrEngine;
use App\Support\PdfText;
use RuntimeException;

/**
 * 납품서·패킹리스트 사진을 읽어 <b>무엇이 몇 개 왔는지</b> 뽑는다.
 *
 * ── 기존 조달 분석기와 무엇이 다른가 ─────────────────────────────────
 * `ProcurementDocAnalyzer` 는 이미 있고 벤더·PO번호·금액·ETA 를 읽는다. 그런데 그것은
 * <b>납기 추적</b>을 위한 것이라 품목을 «한 줄 요약(item_summary)» 으로만 뽑는다.
 * 입고에 필요한 것은 요약이 아니라 <b>줄마다의 수량</b>이다 — 그게 곧 재고이고 원가다.
 *
 * 그래서 같은 엔진(OcrEngine)을 쓰되 묻는 것을 달리한다. 엔진을 또 만들지 않는다.
 *
 * ── AI 가 저장하지 않는다 ─────────────────────────────────────────────
 * 여기가 돌려주는 것은 <b>초안</b>이다. 수량은 사람이 눈으로 보고 확정해야 한다.
 * 글씨가 번진 납품서에서 10 을 100 으로 읽는 일은 반드시 생기고, 그것이 조용히
 * 장부가 되면 나중에 아무도 그 숫자의 출처를 설명하지 못한다.
 */
class DeliverySlipAnalyzer
{
    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $path, ?string $mimeType = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('사진 파일을 읽을 수 없습니다.');
        }

        $bytes = (string) file_get_contents($path);
        $mime = $mimeType ?: (mime_content_type($path) ?: 'application/octet-stream');

        $docText = str_contains($mime, 'pdf') ? PdfText::extract($bytes) : null;

        $parts = [];
        if ($docText === null) {
            if (! str_contains($mime, 'pdf') && ! str_contains($mime, 'image')) {
                throw new RuntimeException('납품서는 사진(JPG·PNG) 또는 PDF 만 읽을 수 있습니다.');
            }
            $parts[] = ['data' => base64_encode($bytes), 'mime_type' => $mime];
        }

        $result = $this->engine->analyze($parts, $this->prompt($docText), $this->schema());

        return $this->normalize(is_array($result['data'] ?? null) ? $result['data'] : [])
            + ['engine' => $this->engine->name(), 'model' => (string) ($result['model'] ?? '')];
    }

    private function prompt(?string $docText): string
    {
        $textBlock = $docText !== null
            ? "\n[서류 본문 텍스트 — 정본, 이것을 근거로 추출]\n─────────\n".mb_substr($docText, 0, 12000)."\n─────────\n"
            : '';

        return <<<PROMPT
당신은 미국 건설 현장의 자재 입고 담당자입니다.
첨부된 **납품서 / 패킹리스트 / 배송전표 / 인보이스 또는 실제 자재·포장 라벨 사진** 을
보고, 실제 입고 품목과 확인 가능한 수량의 초안을 한 줄씩 뽑아 주세요. JSON 만 반환합니다.
{$textBlock}
규칙:
- **서류·라벨에 실제로 적혔거나 사진에서 명확히 확인되는 것만** 뽑습니다. 안 보이면 빈 문자열이나 null 로 두세요.
  추측해서 채우지 마세요 — 틀린 수량은 빈 칸보다 나쁩니다.
- 수량이 없는 줄(소계·세금·운임·서명란·안내문구)은 **제외**합니다.
- 주문 수량과 실제 납품 수량이 따로 있으면 **실제 납품(shipped/delivered)** 수량을 씁니다.
  일부만 왔으면 온 수량을 적고, 그 사실을 line 의 note 에 적으세요.
- 단위는 서류 표기 그대로(EA, PCS, FT, LF, BOX, CS, LB, KG, GAL…).
- 실제 자재 사진이면 라벨의 품명·모델·규격을 읽고, 사진에 온전히 보이는 개별 물체나
  포장의 수만 셉니다. 겹쳐 있거나 가려진 자재, 상자 내부, 화면 밖 자재는 추정하지 마세요.
  예를 들어 닫힌 상자 두 개만 보이면 확인 가능한 단위는 BOX 이며, 안의 EA 수량으로
  바꾸지 마세요. 라벨의 포장당 수량과 실제 납품 수량은 서로 다른 정보입니다.
- 실제 입고 수량·단위를 확실하게 구분할 수 없으면 해당 줄은 제외합니다. 수량을 믿을 수
  있는 품목이 하나도 없으면 lines=[]로 반환하고 summary에 보이는 품명·라벨 정보와
  사람이 직접 수량을 확인해야 하는 이유를 적으세요. 수량 1을 기본값으로 만들지 마세요.
- 사진의 제조사·브랜드를 공급사(vendor)로 단정하지 마세요. 납품일이나 PO가 안 보이면
  빈 칸으로 두세요. 사진 촬영일·오늘 날짜를 납품일로 추정하지 마세요.

추출 항목:
- vendor: 납품한 회사(공급사) 이름.
- po_no: 발주번호(PO). 없으면 "".
- delivery_no: 납품서/전표/인보이스 번호. 없으면 "".
- received_on: 납품일 "YYYY-MM-DD". 없으면 "".
- lines: 품목 배열. 각 항목은
    name(품목명, 규격 포함해 서류·라벨 그대로),
    quantity(숫자),
    unit(단위),
    unit_price(단가, 없으면 null),
    note(부분 납품·손상 등 특이사항, 없으면 "")
- confidence: 0~1. 글씨가 번지거나 가려서 확신이 낮으면 낮게 주세요.
- summary: 한국어 한 문장 요약.
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
                'vendor' => ['type' => 'string'],
                'po_no' => ['type' => 'string'],
                'delivery_no' => ['type' => 'string'],
                'received_on' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'summary' => ['type' => 'string'],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => ['type' => 'string'],
                            'unit_price' => ['type' => 'number'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalize(array $d): array
    {
        $str = static fn ($v): ?string => trim((string) $v) !== '' ? trim((string) $v) : null;
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : null;

        $lines = [];
        foreach (is_array($d['lines'] ?? null) ? $d['lines'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $name = $str($raw['name'] ?? '');
            $qty = isset($raw['quantity']) && is_numeric($raw['quantity']) ? (float) $raw['quantity'] : null;

            // 이름이 없거나 수량이 없는 줄은 입고가 아니다(소계·운임·서명란).
            // 여기서 거르지 않으면 «합계» 가 품목 한 줄로 재고에 들어간다.
            if ($name === null || $qty === null || $qty <= 0) {
                continue;
            }

            $lines[] = [
                'name' => $name,
                'quantity' => $qty,
                'unit' => $str($raw['unit'] ?? ''),
                'unit_price' => isset($raw['unit_price']) && is_numeric($raw['unit_price'])
                    ? (float) $raw['unit_price'] : null,
                'note' => $str($raw['note'] ?? ''),
            ];
        }

        $confidence = isset($d['confidence']) && is_numeric($d['confidence'])
            ? max(0.0, min(1.0, (float) $d['confidence']))
            : null;

        return [
            'vendor' => $str($d['vendor'] ?? ''),
            'po_no' => $str($d['po_no'] ?? ''),
            'delivery_no' => $str($d['delivery_no'] ?? ''),
            'received_on' => $date($d['received_on'] ?? null),
            'lines' => $lines,
            'confidence' => $confidence,
            'summary' => $str($d['summary'] ?? ''),
        ];
    }
}
