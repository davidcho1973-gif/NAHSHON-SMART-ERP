<?php

namespace App\Services\Procurement;

use App\Models\ProcurementItem;
use App\Services\Ocr\OcrEngine;
use App\Support\OfficeText;
use App\Support\PdfText;
use RuntimeException;

/**
 * 조달 서류 AI 분석 — 발주서(PO)·선적서·통관서류·납품확인서 등을 읽어 벤더·PO번호·금액·ETA 를
 * 뽑고, **문서 종류로 조달 파이프라인 단계를 자동 판정**한다(업로드만 하면 단계가 앞으로 나아감).
 *
 * 공통 OcrEngine(Gemini/Claude) + PdfText/OfficeText 를 재활용한다(텍스트는 정본으로, 스캔은 비전).
 */
class ProcurementDocAnalyzer
{
    public function __construct(private readonly OcrEngine $engine)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $path, ?string $mimeType = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('서류 파일을 읽을 수 없습니다.');
        }

        $bytes = (string) file_get_contents($path);
        $mime = $mimeType ?: (mime_content_type($path) ?: 'application/octet-stream');

        $docText = null;
        if (str_contains($mime, 'pdf')) {
            $docText = PdfText::extract($bytes);
        } elseif (OfficeText::isSupported($mime, $path)) {
            $docText = OfficeText::extract($bytes, $mime, $path);
            if ($docText === null) {
                throw new RuntimeException('Word/Excel 서류에서 본문을 추출하지 못했습니다. PDF로 변환해 올려주세요.');
            }
        }

        $parts = [];
        if ($docText === null) {
            if (str_contains($mime, 'pdf') || str_contains($mime, 'image')) {
                $parts[] = ['data' => base64_encode($bytes), 'mime_type' => $mime];
            } else {
                throw new RuntimeException('조달 서류는 PDF·이미지·Word(.docx)·Excel(.xlsx)만 분석할 수 있습니다.');
            }
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
당신은 미국 내 한국 대기업 플랜트/공장 시공사의 자재 조달(구매·납기) 담당자입니다.
첨부(또는 아래 본문)의 **조달 관련 서류**를 읽고 핵심 정보를 구조화해 추출하세요.
**문서에 실제로 있는 값만** 추출하고 없으면 빈 문자열로 두세요. JSON 만 반환합니다.
{$textBlock}
가장 중요한 것: 이 서류가 **조달 진행 어느 단계**를 뜻하는지 doc_kind 로 분류하세요.
- quote: 견적서/RFQ (아직 발주 전)
- purchase_order: 발주서/PO/주문확인서 (발주됨)
- production: 생산·제작 지시/생산완료 통보
- shipping: 선적서류/B/L/패킹리스트/상업송장/출고 (배에 실림)
- customs: 통관서류/수입신고/entry summary (통관 중)
- delivery: 납품확인서/입고증/delivery receipt/GRN (현장 도착·입고)
- invoice: 대금청구 인보이스(선적/통관 단서 없을 때)
- other: 그 외

추출 항목:
- doc_kind: 위 키 중 하나.
- vendor: 공급사/제조사/셀러 이름(우리에게 납품하는 쪽).
- po_no: 발주번호/주문번호/인보이스번호/B/L 번호 등 대표 번호.
- amount / currency: 대표 금액(숫자만)과 통화(USD 등).
- order_date: 발주일/주문일 "YYYY-MM-DD"(없으면 "").
- eta: **도착예정일/납기/배송예정일**(delivery 서류면 실제 입고일). "YYYY-MM-DD"(없으면 ""). 조달의 핵심값이니 꼼꼼히 찾으세요.
- item_summary: 품목 요약(무엇을 조달하는지 한 줄).
- summary: 1문장 한국어 요약.
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
                'doc_kind' => ['type' => 'string'],
                'vendor' => ['type' => 'string'],
                'po_no' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
                'order_date' => ['type' => 'string'],
                'eta' => ['type' => 'string'],
                'item_summary' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalize(array $d): array
    {
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : null;
        $str = static fn ($v): ?string => trim((string) $v) !== '' ? trim((string) $v) : null;

        $kind = strtolower(trim((string) ($d['doc_kind'] ?? '')));

        return [
            'doc_kind' => $kind ?: 'other',
            'stage' => $this->stageFor($kind),
            'vendor' => $str($d['vendor'] ?? ''),
            'po_no' => $str($d['po_no'] ?? ''),
            'amount' => isset($d['amount']) && is_numeric($d['amount']) ? (float) $d['amount'] : null,
            'currency' => $str($d['currency'] ?? ''),
            'order_date' => $date($d['order_date'] ?? null),
            'eta' => $date($d['eta'] ?? null),
            'item_summary' => $str($d['item_summary'] ?? ''),
            'summary' => $str($d['summary'] ?? ''),
        ];
    }

    /**
     * 문서 종류 → 조달 파이프라인 단계. (견적/기타는 단계 변경 없음 → null)
     */
    private function stageFor(string $kind): ?string
    {
        return match ($kind) {
            'purchase_order' => '발주완료',
            'production' => '생산중',
            'shipping', 'invoice' => '선적중',
            'customs' => '통관중',
            'delivery' => '입고완료',
            default => null, // quote/other
        };
    }
}
