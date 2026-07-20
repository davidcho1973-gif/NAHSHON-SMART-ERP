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
        $structure = $this->generate($this->wbsPrompt($context), $this->wbsSchema(), $pdf);

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
                'max_tokens' => 16000,
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

    /**
     * @param  array{label: string, project: string, type: string, scope: string}  $c
     */
    private function wbsPrompt(array $c): string
    {
        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장(LG배터리·SK반도체·현대차 등) 설치공사의 공정관리 전문가입니다.
아래 프로젝트의 작업범위(첨부 매뉴얼이 있으면 그 본문을 우선 근거로)를 분석하여 **WBS(작업분해구조)**를
Stage → Task → SubTask 3단계로 분해하세요. 현실적인 설치 시퀀스(반입→설치→배관/배선→시운전)를 따릅니다.

[프로젝트] {$c['project']}
[공종] {$c['type']}
[작업범위]
{$c['scope']}

요구사항:
- stages: 3~6개. 각 stage 는 stage_no(예 "1"), stage_name, tasks 를 가짐.
- tasks: stage 당 2~5개. 각 task 는 task_no(예 "1.2"), task_name, sub_tasks 를 가짐.
- sub_tasks: task 당 2~6개. 각 항목:
  - sub_no: 예 "1.2.3"
  - sub_name: 구체적 작업명
  - trade: 공종(담당 협력사 아님). 전기 / 배관 / 기계설치 / 리깅 / 용접 / 강구조 / 장비설치 / 시운전 / 안전 / 일반 중 하나. 실제 협력사는 사람이 나중에 배정하므로 회사명은 절대 넣지 마라.
  - manhours: 예상 공수(정수, man-hour)
  - days: 예상 소요일(정수)
  - ehs: 안전 위험도. high(고소·중량물·전기·밀폐) / medium / low 중 하나.
PROMPT;
    }

    /**
     * Anthropic structured outputs 스키마. 모든 object 는 additionalProperties:false + required 필요.
     *
     * @return array<string, mixed>
     */
    private function wbsSchema(): array
    {
        $subTask = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'sub_no' => ['type' => 'string'],
                'sub_name' => ['type' => 'string'],
                'trade' => ['type' => 'string'],
                'manhours' => ['type' => 'integer'],
                'days' => ['type' => 'integer'],
                'ehs' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['sub_no', 'sub_name', 'trade', 'manhours', 'days', 'ehs'],
        ];

        $task = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'task_no' => ['type' => 'string'],
                'task_name' => ['type' => 'string'],
                'sub_tasks' => ['type' => 'array', 'items' => $subTask],
            ],
            'required' => ['task_no', 'task_name', 'sub_tasks'],
        ];

        $stage = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'stage_no' => ['type' => 'string'],
                'stage_name' => ['type' => 'string'],
                'tasks' => ['type' => 'array', 'items' => $task],
            ],
            'required' => ['stage_no', 'stage_name', 'tasks'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'stages' => ['type' => 'array', 'items' => $stage],
            ],
            'required' => ['stages'],
        ];
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
