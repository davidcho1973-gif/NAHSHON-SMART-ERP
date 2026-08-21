<?php

namespace App\Services\FieldApp;

use App\Models\FieldDrawing;
use App\Support\AnthropicChat;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * FieldApp 도면 Vision 판독 & Q&A 엔진.
 * Claude(Anthropic) 비전으로 도면 이미지/PDF 를 판독해 시공 스펙·안전 요구사항을 구조화하고,
 * 판독 결과를 컨텍스트로 현장 Q&A 에 답한다. API 키가 없으면 오프라인 규칙 기반으로 폴백한다.
 */
class DrawingVisionService
{
    public function __construct(private readonly AnthropicChat $chat)
    {
    }

    public function isLive(): bool
    {
        return $this->chat->available();
    }

    /**
     * 도면 파일을 판독해 summary/specs/safety_notes/analysis 를 채운다.
     */
    public function analyze(FieldDrawing $drawing): FieldDrawing
    {
        if (! $this->isLive() || ! $drawing->file_path || ! Storage::disk('local')->exists($drawing->file_path)) {
            return $this->analyzeOffline($drawing);
        }

        $data = base64_encode((string) Storage::disk('local')->get($drawing->file_path));
        $mime = $drawing->file_mime ?: 'image/jpeg';

        $content = [
            str_contains($mime, 'pdf')
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]],
            ['type' => 'text', 'text' => implode("\n", [
                '당신은 건설현장 도면 판독 전문가입니다. 첨부된 도면을 정밀 분석하세요.',
                "도면 명칭: {$drawing->title}",
                "도면 분류: {$drawing->category}",
                '다음을 한국어로 추출하세요:',
                '- summary: 도면의 핵심 내용 요약 (2~3문장, 구역/공종/주요 설비 포함)',
                '- discipline: 공종 분류 (예: MEP 배관, 전기, 철골 구조, 건축 마감)',
                '- specs: 시공 스펙 목록 (관경/규격/재질/간격/하중 등 수치 위주, 각 30자 이내)',
                '- safety_requirements: 이 도면 구역 작업 시 필수 안전 조치 목록',
                '- key_notes: 도면 주석/노트에서 발견한 중요 시공 지시사항',
                '도면에서 확인할 수 없는 값은 추정하지 말고 목록에서 제외하세요.',
            ])],
        ];

        $json = $this->callClaude([
            'max_tokens' => 4000,
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->analysisSchema()]],
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        $decoded = json_decode($this->collectText($json), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude 도면 분석 결과가 유효한 JSON 이 아닙니다.');
        }

        $drawing->update([
            'summary' => (string) ($decoded['summary'] ?? ''),
            'specs' => array_slice((array) ($decoded['specs'] ?? []), 0, 8),
            'safety_notes' => (array) ($decoded['safety_requirements'] ?? []),
            'category' => $drawing->category ?: (string) ($decoded['discipline'] ?? ''),
            'analysis' => $decoded,
            'ai_model' => (string) config('services.anthropic.model', 'claude-opus-4-8'),
            'status' => 'analyzed',
            'analyzed_at' => now(),
        ]);

        return $drawing->refresh();
    }

    /**
     * 판독된 도면 컨텍스트 + 대화 이력 기반 Q&A.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{text: string, sources: array<int, string>}
     */
    public function answer(FieldDrawing $drawing, string $question, array $history = []): array
    {
        if (! $this->isLive()) {
            return $this->answerOffline($drawing, $question);
        }

        $context = [
            '당신은 건설현장 AI 도면 지식 도우미입니다. 아래 판독된 도면 정보를 근거로 현장 작업자의 질문에 답하세요.',
            '답변 원칙: 한국어, 수치·규격은 굵게(**) 강조, 안전 관련 질문은 반드시 허가서/보호구/감시자 조치를 포함, 도면에 없는 내용은 "도면에서 확인 불가"라고 명시.',
            "도면 ID: {$drawing->drawing_no}",
            "도면 명칭: {$drawing->title}",
            "도면 분류: {$drawing->category}",
            '판독 결과: ' . json_encode($drawing->analysis ?: [
                'summary' => $drawing->summary,
                'specs' => $drawing->specs,
                'safety' => $drawing->safety_notes,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $messages = [];
        foreach (array_slice($history, -6) as $msg) {
            $messages[] = [
                'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $msg['content'],
            ];
        }

        $userContent = [];
        if ($drawing->file_path && Storage::disk('local')->exists($drawing->file_path)) {
            $mime = $drawing->file_mime ?: 'image/jpeg';
            $data = base64_encode((string) Storage::disk('local')->get($drawing->file_path));
            $userContent[] = str_contains($mime, 'pdf')
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];
        }
        $userContent[] = ['type' => 'text', 'text' => $question];
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $json = $this->callClaude([
            'max_tokens' => 2000,
            'system' => implode("\n", $context),
            'messages' => $messages,
        ]);

        $text = trim($this->collectText($json));
        if ($text === '') {
            throw new RuntimeException('Claude 가 답변을 반환하지 않았습니다.');
        }

        return [
            'text' => $text,
            'sources' => [$drawing->drawing_no, (string) config('services.anthropic.model', 'claude-opus-4-8') . ' Vision'],
        ];
    }

    /* ---------------------------------------------------------------- border
       오프라인 폴백 (ANTHROPIC_API_KEY 미설정 시)
    ------------------------------------------------------------------ */

    private function analyzeOffline(FieldDrawing $drawing): FieldDrawing
    {
        $drawing->update([
            'summary' => "[오프라인 등록] '{$drawing->title}' 도면이 지식베이스에 등록되었습니다. ANTHROPIC_API_KEY 설정 시 AI Vision 정밀 판독이 활성화됩니다.",
            'specs' => ['AI 정밀 판독 대기', '수동 스펙 확인 필요'],
            'safety_notes' => ['작업 전 현장 반장 도면 검토 필수'],
            'ai_model' => null,
            'status' => 'analyzed',
            'analyzed_at' => now(),
        ]);

        return $drawing->refresh();
    }

    /**
     * @return array{text: string, sources: array<int, string>}
     */
    private function answerOffline(FieldDrawing $drawing, string $question): array
    {
        $text = "🔍 **도면 지식베이스 답변 [{$drawing->drawing_no}]** (오프라인 모드):\n";

        if (preg_match('/배관|관경|서포트|간격|스펙|규격/u', $question)) {
            $specs = $drawing->specs ?: ['등록된 스펙 없음'];
            $text .= "• **등록된 시공 스펙**:\n" . implode("\n", array_map(fn ($s) => "  - {$s}", $specs));
        } elseif (preg_match('/안전|주의|LOTO|화기|추락|보호구/u', $question)) {
            $safety = $drawing->safety_notes ?: ['작업 전 TBM 실시 및 현장 반장 확인'];
            $text .= "• **안전 요구사항**:\n" . implode("\n", array_map(fn ($s) => "  - {$s}", $safety));
        } else {
            $text .= "• **도면 요약**: {$drawing->summary}\n• AI Vision 정밀 답변은 ANTHROPIC_API_KEY 설정 후 이용 가능합니다.";
        }

        return ['text' => $text, 'sources' => [$drawing->drawing_no, '오프라인 지식베이스']];
    }

    /* ---------------------------------------------------------------- border
       Anthropic API 공통 호출
    ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function callClaude(array $payload): array
    {
        return $this->chat->raw($payload);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function collectText(array $json): string
    {
        return $this->chat->textOf($json);
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'discipline', 'specs', 'safety_requirements', 'key_notes'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'discipline' => ['type' => 'string'],
                'specs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'safety_requirements' => ['type' => 'array', 'items' => ['type' => 'string']],
                'key_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
