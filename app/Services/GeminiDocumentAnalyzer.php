<?php

namespace App\Services;

use App\Services\Ocr\OcrEngine;
use App\Support\OfficeText;
use App\Support\PdfText;
use RuntimeException;

/**
 * 계약·컴플라이언스 문서(계약서·COI·W-9·Bond·NTP·기성청구·비자·자격증 등) AI 자동분석.
 *
 * 공통 OcrEngine(Gemini/Claude 전환)에 위임하고, 텍스트 PDF 는 PdfText 로 본문을 뽑아
 * 프롬프트에 정본으로 넣어 정확도를 높인다(스캔/이미지는 비전으로 읽는다). 문서 종류를 분류하고
 * 문서번호·발행/효력/만료일·발행처·금액과 그 밖의 핵심 필드를 구조화해 폼 자동채움에 쓴다.
 */
class GeminiDocumentAnalyzer
{
    /** 계약 서류 유형 키(ProjectContractDocument::TYPE_OPTIONS 와 일치). */
    private const TYPES = 'executed_contract, amendment, change_order, scope_of_work, purchase_order, '
        . 'notice_to_proceed, certificate_of_insurance, w9, bond, safety_plan, schedule, lien_waiver, '
        . 'pay_application, correspondence, visa, work_authorization, license, certificate, other';

    public function __construct(private readonly OcrEngine $engine)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $path, ?string $mimeType = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('문서 파일을 읽을 수 없습니다.');
        }

        $bytes = (string) file_get_contents($path);
        $mime = $mimeType ?: (mime_content_type($path) ?: 'application/octet-stream');

        // 본문 텍스트를 뽑을 수 있으면(텍스트 PDF·Word·Excel) 정본으로 넣는다 — 정확·저렴.
        $docText = null;
        if (str_contains($mime, 'pdf')) {
            $docText = PdfText::extract($bytes);
        } elseif (OfficeText::isSupported($mime, $path)) {
            $docText = OfficeText::extract($bytes, $mime, $path);
            if ($docText === null) {
                throw new RuntimeException('Word/Excel 문서에서 본문 텍스트를 추출하지 못했습니다(빈 문서이거나 구형 .doc/.xls). PDF로 변환해 올려주세요.');
            }
        }

        // 텍스트를 못 뽑은 경우: 이미지/스캔 PDF 는 비전으로, 그 밖의 형식은 미지원 안내.
        $parts = [];
        if ($docText === null) {
            if (str_contains($mime, 'pdf') || str_contains($mime, 'image')) {
                $parts[] = ['data' => base64_encode($bytes), 'mime_type' => $mime];
            } else {
                throw new RuntimeException('AI 자동분석은 PDF·이미지·Word(.docx)·Excel(.xlsx) 문서만 지원합니다.');
            }
        }

        $result = $this->engine->analyze($parts, $this->prompt($docText), $this->schema());

        return $this->normalize(is_array($result['data'] ?? null) ? $result['data'] : [])
            + [
                'engine' => $this->engine->name(),
                'model' => (string) ($result['model'] ?? ''),
                // 추출한 본문 원문 — 전문검색용으로 보관한다(스캔/이미지는 null).
                'body_text' => $docText,
            ];
    }

    private function prompt(?string $pdfText): string
    {
        $types = self::TYPES;
        $textBlock = $pdfText !== null
            ? "\n[문서 본문 텍스트 — 정본, 이것을 근거로 추출]\n─────────\n" . mb_substr($pdfText, 0, 12000) . "\n─────────\n"
            : '';

        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장 시공사의 계약·컴플라이언스 문서 담당자입니다.
첨부(또는 아래 본문)의 문서를 읽고 핵심 정보를 구조화해 추출하세요.
**문서에 실제로 있는 값만** 추출하고, 없는 값은 지어내지 말고 빈 문자열로 두세요. JSON 만 반환합니다.
{$textBlock}
추출 항목:
- document_type: 다음 키 중 하나로 분류 — {$types}. (예: 보험증서=certificate_of_insurance, 착공지시=notice_to_proceed, 기성청구/인보이스=pay_application)
- title: 문서 제목(한 줄).
- document_number: 증서번호/계약번호/인보이스번호/정책번호 등 대표 번호.
- issued_on / effective_on / expires_on: 각각 발행일/효력발생일/만료일. "YYYY-MM-DD" 형식(없으면 "").
  **특히 COI·Bond·License 의 만료일(expires_on)을 반드시 꼼꼼히 찾으세요 — 컴플라이언스 핵심값입니다.**
- issuer: 발행처(보험사/발주처/발급기관).
- counterparty: 상대방(피보험자/수신자/계약 상대).
- amount / currency: 대표 금액(숫자만)과 통화(USD 등).
- summary: 1~2문장 한국어 요약.
- fields: 위 항목에 없는 그 밖의 핵심 값들을 [{label, value}] 배열로(보장한도·정책번호·NAICS·면허종류·유효기간 등).
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
                'document_type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'document_number' => ['type' => 'string'],
                'issued_on' => ['type' => 'string'],
                'effective_on' => ['type' => 'string'],
                'expires_on' => ['type' => 'string'],
                'issuer' => ['type' => 'string'],
                'counterparty' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'fields' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string']],
                ]],
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

        $fields = [];
        foreach ((array) ($d['fields'] ?? []) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? ''));
            $value = trim((string) ($f['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $fields[$label] = $value;
            }
        }

        $str = static fn ($v): ?string => trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'document_type' => $str($d['document_type'] ?? ''),
            'title' => $str($d['title'] ?? ''),
            'document_number' => $str($d['document_number'] ?? ''),
            'issued_on' => $date($d['issued_on'] ?? null),
            'effective_on' => $date($d['effective_on'] ?? null),
            'expires_on' => $date($d['expires_on'] ?? null),
            'issuer' => $str($d['issuer'] ?? ''),
            'counterparty' => $str($d['counterparty'] ?? ''),
            'amount' => isset($d['amount']) && is_numeric($d['amount']) ? (float) $d['amount'] : null,
            'currency' => $str($d['currency'] ?? ''),
            'summary' => $str($d['summary'] ?? ''),
            'fields' => $fields,
        ];
    }
}
