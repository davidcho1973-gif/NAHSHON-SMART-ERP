<?php

namespace App\Services\Wbs;

use App\Models\Project;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Claude(Anthropic) 기반 "AI 메뉴얼 분석" for 공정관리(WBS).
 *
 * GeminiWbsAnalyzer 와 동일한 결과 형태(processManual → {success, processed, results})를 반환하되
 * Anthropic Messages API(claude-opus-4-8)로 프로젝트 작업범위를 Stage→Task→SubTask WBS 로 분해한다.
 * Claude 는 PDF/이미지를 네이티브로 읽으므로(문서 이해=OCR), 매뉴얼 파일(base64 PDF)을 함께 넘기면
 * 그 본문을 근거로 분석한다 — 파일이 없으면 projects.scope_of_work 텍스트를 grounding 으로 사용한다.
 *
 * 코드베이스의 GeminiWbsAnalyzer 와 마찬가지로 SDK 대신 Laravel HTTP 클라이언트(raw HTTP)를 쓴다
 * (형제 클래스와 아키텍처 일관성 + 원격 컨테이너에서 composer 의존성 추가 회피).
 */
class ClaudeWbsAnalyzer
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly WbsService $wbs,
        private readonly ScheduleImporter $scheduleImporter,
    ) {
    }

    /**
     * 프로젝트의 작업범위(+선택적 매뉴얼 PDF) → Claude WBS 생성 → 영속화.
     * 프론트(runWbsAiAnalysis)가 기대하는 결과 형태 반환.
     *
     * @param  array{data: string, media_type?: string}|null  $pdf  base64 PDF (선택) — 있으면 문서 본문을 근거로 분석
     * @return array<string, mixed>
     */
    public function processManual(string $projectCode, string $siteId = 'ALL', ?array $pdf = null): array
    {
        if ((string) config('services.anthropic.api_key') === '') {
            return ['success' => false, 'processed' => 0, 'results' => [], 'error' => 'ANTHROPIC_API_KEY 가 설정되지 않았습니다.'];
        }

        $context = $this->buildContext($projectCode);
        // PDF 면 서버가 표 텍스트를 뽑아 정본으로 준다(vision 이 조밀한 표의 행을 빠뜨리는 문제 회피).
        // 텍스트가 충분하면 바이너리(vision)는 생략해 텍스트 전량 추출을 강제한다.
        $pdfText = \App\Support\PdfText::fromPayload($pdf);
        $binary = $pdfText !== null ? null : $pdf;
        // 충실한 추출 우선: 문서가 CPM/공정표면 모든 행을 activities 로, 산문형이면 stages 로 반환.
        $structure = $this->generate(CpmExtraction::prompt($context, $pdfText), CpmExtraction::schema(true), $binary);

        $activities = is_array($structure['activities'] ?? null) ? $structure['activities'] : [];
        if ($activities !== []) {
            $milestones = is_array($structure['milestones'] ?? null) ? $structure['milestones'] : [];
            $counts = $this->scheduleImporter->persistExtracted($projectCode, $siteId, $activities, $milestones);

            return [
                'success' => true,
                'processed' => 1,
                'results' => [[
                    'file' => $context['label'],
                    'status' => 'success',
                    'engine' => 'claude',
                    'mode' => 'cpm',
                    'activities' => $counts['activities'],
                    'milestones' => $counts['milestones'],
                    'stages' => $counts['stages'],
                    'tasks' => $counts['tasks'],
                    'subTasks' => $counts['subtasks'],
                ]],
            ];
        }

        // 폴백: 산문형 작업범위 → 표준 WBS 생성.
        $stages = is_array($structure['stages'] ?? null) ? $structure['stages'] : [];
        if ($stages === []) {
            return ['success' => true, 'processed' => 0, 'results' => [], 'error' => 'AI가 생성한 WBS가 비어 있습니다.'];
        }

        $counts = $this->wbs->importGenerated($projectCode, $stages, $siteId);

        return [
            'success' => true,
            'processed' => 1,
            'results' => [[
                'file' => $context['label'],
                'status' => 'success',
                'engine' => 'claude',
                'mode' => 'wbs',
                'stages' => $counts['stages'],
                'tasks' => $counts['tasks'],
                'subTasks' => $counts['subtasks'],
            ]],
        ];
    }

    /**
     * @return array{label: string, project: string, type: string, scope: string}
     */
    private function buildContext(string $projectCode): array
    {
        $project = Project::query()
            ->where('project_code', $projectCode)
            ->orWhere('po_number', $projectCode)
            ->first();

        if (! $project) {
            return [
                'label' => "{$projectCode} (작업범위 미등록)",
                'project' => $projectCode,
                'type' => '기계·전기·배관 설치',
                'scope' => '미국 내 한국 대기업 플랜트/공장 설치 공사. 상세 작업범위가 등록되지 않아 일반적인 설치 공정으로 분해합니다.',
            ];
        }

        $type = Project::CONSTRUCTION_TYPE_OPTIONS[$project->construction_type] ?? ($project->construction_type ?? '설치공사');

        return [
            'label' => "{$project->project_code} · {$project->name}",
            'project' => $project->name,
            'type' => $type,
            'scope' => (string) ($project->scope_of_work ?: '작업범위 텍스트가 비어 있습니다. 공종 기준 표준 설치 공정으로 분해하세요.'),
        ];
    }

    /**
     * Anthropic Messages API 호출 → 구조화 JSON(WBS) 반환.
     *
     * @param  array<string, mixed>  $schema
     * @param  array{data: string, media_type?: string}|null  $pdf
     * @return array<string, mixed>
     */
    private function generate(string $prompt, array $schema, ?array $pdf = null): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $endpoint = rtrim((string) config('services.anthropic.endpoint', 'https://api.anthropic.com'), '/') . '/v1/messages';
        $model = (string) config('services.anthropic.model', 'claude-opus-4-8');

        // 문서(PDF)가 있으면 텍스트 프롬프트 앞에 document 블록을 둔다 — Claude 가 본문을 직접 읽는다(OCR).
        $content = [];
        if ($pdf !== null && ($pdf['data'] ?? '') !== '') {
            $content[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => (string) ($pdf['media_type'] ?? 'application/pdf'),
                    'data' => (string) $pdf['data'],
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $response = $this->http
            ->timeout((int) config('services.anthropic.timeout', 180))
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
            ->post($endpoint, [
                'model' => $model,
                // CPM 전량 추출(수십~수백 행 × 다필드)이면 출력이 커진다 — 잘리지 않게 넉넉히.
                'max_tokens' => 32000,
                // 구조화 출력: 응답을 WBS JSON 스키마로 강제 (Gemini 의 responseSchema 와 동일 역할).
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API returned status ' . $response->status() . ': ' . $response->body());
        }

        $json = $response->json();

        // 안전 안내: 안전성 분류기가 요청을 거부하면 refusal 로 멈춘다 — content 를 읽기 전에 확인.
        if (($json['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Claude 가 요청을 거부했습니다(refusal). 입력 매뉴얼/범위를 확인하세요.');
        }

        // 응답 content 블록 중 첫 text 블록을 취합.
        $text = '';
        foreach ((is_array($json['content'] ?? null) ? $json['content'] : []) as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException('Claude returned no text.');
        }

        $decoded = json_decode($this->stripJsonFence($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude response was not valid JSON.');
        }

        return $decoded;
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
