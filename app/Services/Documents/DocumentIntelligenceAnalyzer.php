<?php

namespace App\Services\Documents;

use App\Models\IntelligentDocument;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\Str;

class DocumentIntelligenceAnalyzer
{
    public function __construct(
        private readonly OcrEngine $engine,
        private readonly DocumentTextExtractor $textExtractor,
    ) {}

    /** @return array{data: array<string, mixed>, extracted_text: string|null, engine: string, model: string} */
    public function analyze(IntelligentDocument $document, string $bytes): array
    {
        $extractedText = $this->textExtractor->extract($bytes, $document->extension, $document->mime_type);
        $parts = [];

        // 한도는 엔진이 말한다 — 파일을 먼저 올려 두고 주소만 넘기는 엔진은 사실상 묶이지 않는다.
        // 예전에는 설정 한 곳(native_max_bytes)에 15MB 로 박혀 있어, 큰 도면을 보낼 수 있는
        // 엔진까지 «글자만» 경로로 밀려났다. 스캔 도면은 뽑을 글자가 없어 그대로 실패했다.
        if ($this->supportsNativeVision($document) && strlen($bytes) <= $this->engine->maxAttachmentBytes()) {
            $parts[] = [
                'data' => base64_encode($bytes),
                'mime_type' => $document->mime_type ?: 'application/octet-stream',
            ];
        }

        if ($parts === [] && blank($extractedText)) {
            $mb = (int) round($this->engine->maxAttachmentBytes() / 1048576);

            throw new \RuntimeException("이 파일은 서버에서 본문을 추출할 수 없고, AI 직접 판독 한도({$mb}MB)도 넘었습니다. PDF로 변환하거나 {$mb}MB 이하로 나눠 다시 분석해 주세요.");
        }

        $context = [
            'today' => now()->toDateString(),
            'company' => $document->company?->name,
            'site' => $document->site?->code.' '.$document->site?->name,
            'project' => $document->project?->project_code.' '.$document->project?->name,
            'file_name' => $document->original_file_name,
            'mime_type' => $document->mime_type,
        ];

        $prompt = $this->prompt($context, $extractedText);

        try {
            $result = $this->engine->analyze($parts, $prompt, $this->schema());
        } catch (\Throwable $e) {
            // 원본 첨부가 실패 원인일 수 있다 — 10MB대 PDF 는 base64 로 1.3배로 불어
            // 요청 한도·타임아웃에 걸리기 쉽다. 서버가 추출한 본문이 있으면 첨부 없이
            // 텍스트만으로 한 번 더 시도한다. (역설적으로 15MB 초과 파일은 처음부터
            // 텍스트 경로라 성공하고, 12MB 파일이 실패하던 원인이 이것이다.)
            if ($parts === [] || blank($extractedText)) {
                throw $e;
            }
            $parts = [];
            $result = $this->engine->analyze([], $prompt, $this->schema());
        }

        return [
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            'extracted_text' => $extractedText,
            'engine' => $this->engine->name(),
            'model' => (string) ($result['model'] ?? ''),
        ];
    }

    private function supportsNativeVision(IntelligentDocument $document): bool
    {
        return str_starts_with((string) $document->mime_type, 'image/')
            || $document->mime_type === 'application/pdf'
            || $document->extension === 'pdf';
    }

    /** @param array<string, mixed> $context */
    private function prompt(array $context, ?string $text): string
    {
        $sourceText = $text ? Str::limit($text, 120000, '\n[본문 길이 제한]') : '[서버 추출 본문 없음 — 첨부 원본을 직접 판독]';

        return <<<'PROMPT'
당신은 미국 건설 프로젝트의 계약관리자, Document Controller, Project Controls Manager, 안전·품질·클레임 전문가다.
첨부 문서 또는 아래 추출 본문을 읽고 단순 요약이 아니라 "사고·손실·기한 누락을 예방하는 문서 인텔리전스"를 생성하라.

반드시 수행할 작업:
1. 문서 제목, 문서유형, 공종, 발신/수신, 문서번호, Revision, 문서일자를 추출한다.
2. contract/correspondence/rfi_submittal/drawing_spec/schedule/quality/safety/finance/procurement/hr/closeout/legal/general 중 category를 고른다.
3. contract/change_order/amendment/notice/official_letter/email_correspondence/rfi/submittal/transmittal/drawing/specification/schedule/daily_report/meeting_minutes/inspection/ncr/safety_plan/incident_report/pay_application/invoice/lien_waiver/purchase_order/delivery_ticket/certificate/warranty/closeout_package/receipt/payroll_record/other 중 document_type을 고른다.
4. 가상 폴더 구성요소를 [대분류, 문서유형, 연도] 순서의 folder_parts로 제안한다. 실제 PROJECT/회사는 시스템이 앞에 붙인다.
5. 사람이 검색할 가능성이 높은 고유명사·장비·공종·도면번호·RFI/CO 번호·금액·날짜를 keywords와 tags로 충분히 만든다.
6. 반드시 기억해야 하는 사실을 key_facts로 만든다. 금액, 책임범위, 제외사항, 승인조건, 통보기간, 회신기한, 보증, 안전·품질 요구를 누락하지 않는다.
7. action_items에는 실제 행동이 필요한 것만 넣는다. 종류는 deadline/response/notice/compliance/safety/quality/financial/schedule/change/risk/missing_document 중 하나다.
8. action_items의 due_on은 문서에 명시된 날짜 또는 문서일+통보기간을 계산한 YYYY-MM-DD다. 추측이면 details에 추정이라고 밝히고 confidence를 낮춘다.
9. 권리 포기, 손해배상, Liquidated Damages, backcharge, scope gap, 승인 없는 추가작업, Notice 미제출, 잘못된 Revision, 안전중지, 검사실패, 지급보류 가능성은 high/critical로 표시한다.
10. 문서에 없는 내용을 창작하지 않는다. 불명확한 값은 빈 문자열/빈 배열로 둔다.
11. 이 문서가 "돈이 실제로 나갔거나 나가야 하는 기록"(영수증, 공급사 인보이스, 급여 지급 내역,
    청구서, 카드 명세 항목)이면 money 를 채운다: flow=out. 반대로 우리가 받을 돈의 기록
    (우리가 발행한 기성청구·인보이스)이면 flow=in. 돈 기록이 아니면 flow=none 으로 두고
    나머지 money 필드는 비워 둔다.
    - money.amount 는 문서의 대표 지급 총액(숫자만). 세금 포함 총액을 우선한다.
    - money.currency 는 USD 등 통화 코드. money.paid_on 은 지급일 YYYY-MM-DD(없으면 빈 문자열).
    - money.payee 는 돈을 받는 쪽 이름. money.purpose 는 무엇에 쓴 돈인지 한 줄.
    - money.category_hint 는 payroll/materials/equipment/lodging/fuel/meals/utilities/other 중
      가장 가까운 하나. 급여 지급 내역이면 반드시 payroll.

12. 이 문서가 <b>장비를 빌리거나 산 기록</b>(임대 계약, 장비 인보이스, 구매 주문, 반납서)이면
    equipment 를 채운다: involved 는 rental(임대)/purchase(구매)/none 중 하나.
    - equipment.name 은 장비 이름(예: JCB 18Z Mini Excavator). model 은 모델명/규격.
    - equipment.rate 는 임대 요율 숫자, rate_unit 은 day/week/month 중 문서에 적힌 단위.
    - equipment.rent_start / rent_end 는 임대 기간 YYYY-MM-DD (없으면 빈 문자열).
    장비 문서가 아니면 involved=none 으로 두고 나머지는 비운다.

direction은 incoming/outgoing/internal, confidentiality는 public/internal/confidential/restricted 중 하나다.
severity는 critical/high/warning/normal 중 하나다. confidence는 0~100 숫자다.

<b>언어</b> — 원문이 영어라도 읽는 사람은 한국인 현장 관리자다. 영어만 적으면 화면에서 읽히지 않고,
한국어만 적으면 원청·설계사에 그대로 인용할 수 없다. 그래서 다음 항목은 <b>영문 원문 뒤에 한국어를
괄호로 덧붙여</b> 한 줄에 둘 다 담는다 — 형식은 «English sentence. (한국어 풀이)».
 · summary · key_facts 의 각 줄
 · action_items 의 title · details · recommended_action
한국어는 번역투가 아니라 <b>현장에서 쓰는 말</b>로 쉽게 쓴다. 전문용어는 괄호 안에서 한 번 풀어 준다
(예: "mock-up (실물 견본 시공)", "preinstallation conference (착수 전 회의)").
source_excerpt 는 <b>원문 그대로</b> 두고 번역하지 않는다 — 근거 인용이라 손대면 안 된다.
문서가 이미 한국어면 굳이 영문을 만들지 말고 한국어만 쓴다.
PROMPT
            ."\n\n시스템 컨텍스트:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\n문서 추출 본문:\n".$sourceText;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => ['type' => 'STRING'],
                'category' => ['type' => 'STRING'],
                'document_type' => ['type' => 'STRING'],
                'discipline' => ['type' => 'STRING'],
                'direction' => ['type' => 'STRING'],
                'document_number' => ['type' => 'STRING'],
                'revision' => ['type' => 'STRING'],
                'sender' => ['type' => 'STRING'],
                'recipients' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'document_date' => ['type' => 'STRING'],
                'effective_on' => ['type' => 'STRING'],
                'expires_on' => ['type' => 'STRING'],
                'response_due_on' => ['type' => 'STRING'],
                'confidentiality' => ['type' => 'STRING'],
                'summary' => ['type' => 'STRING'],
                'folder_parts' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'key_facts' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'project_code' => ['type' => 'STRING'],
                'confidence' => ['type' => 'NUMBER'],
                'equipment' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'involved' => ['type' => 'STRING'],
                        'name' => ['type' => 'STRING'],
                        'model' => ['type' => 'STRING'],
                        'rate' => ['type' => 'NUMBER'],
                        'rate_unit' => ['type' => 'STRING'],
                        'rent_start' => ['type' => 'STRING'],
                        'rent_end' => ['type' => 'STRING'],
                    ],
                ],
                'money' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'flow' => ['type' => 'STRING'],
                        'amount' => ['type' => 'NUMBER'],
                        'currency' => ['type' => 'STRING'],
                        'paid_on' => ['type' => 'STRING'],
                        'payee' => ['type' => 'STRING'],
                        'purpose' => ['type' => 'STRING'],
                        'category_hint' => ['type' => 'STRING'],
                    ],
                ],
                'action_items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'action_type' => ['type' => 'STRING'],
                            'related_module' => ['type' => 'STRING'],
                            'severity' => ['type' => 'STRING'],
                            'title' => ['type' => 'STRING'],
                            'details' => ['type' => 'STRING'],
                            'recommended_action' => ['type' => 'STRING'],
                            'source_excerpt' => ['type' => 'STRING'],
                            'due_on' => ['type' => 'STRING'],
                            'remind_days_before' => ['type' => 'INTEGER'],
                            'confidence' => ['type' => 'NUMBER'],
                        ],
                        'required' => ['action_type', 'severity', 'title'],
                    ],
                ],
            ],
            'required' => ['title', 'category', 'document_type', 'summary', 'keywords', 'key_facts', 'action_items', 'confidence'],
        ];
    }
}
