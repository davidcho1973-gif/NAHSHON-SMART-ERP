<?php

namespace App\Services\Wbs;

use App\Models\Project;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemini-backed "AI 메뉴얼 분석" for 공정관리(WBS).
 *
 * 프로젝트의 공종/작업범위(scope_of_work)를 근거로 Gemini 가 Stage → Task → SubTask
 * 3단계 WBS 를 생성하고, 협력사/공수/일수/EHS 위험도까지 분류해 DB(wbs_items)에 영속화한다.
 *
 * (설계 메모) 원 GAS 버전은 Google Drive 의 WBS_MANUAL 폴더 PDF 를 스캔했다. 서버 측 Drive
 * 연동이 붙기 전까지는 projects 테이블의 작업범위 텍스트를 grounding 컨텍스트로 사용한다.
 * Drive/PDF 연동이 추가되면 buildContext() 에 매뉴얼 본문을 합치기만 하면 된다.
 */
class GeminiWbsAnalyzer
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly WbsService $wbs,
    ) {
    }

    /**
     * 프로젝트의 작업범위 → AI WBS 생성 → 영속화. 프론트(runWbsAiAnalysis)가 기대하는 결과 형태 반환.
     *
     * @return array<string, mixed>
     */
    public function processManual(string $projectCode, string $siteId = 'ALL'): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return ['success' => false, 'processed' => 0, 'results' => [], 'error' => 'GEMINI_API_KEY 가 설정되지 않았습니다.'];
        }

        $context = $this->buildContext($projectCode);
        $structure = $this->generate($this->wbsPrompt($context), $this->wbsSchema());

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
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function generate(string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $lastException = null;

        foreach ($this->models() as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 60))
                    ->withHeaders(['x-goog-api-key' => $apiKey, 'Content-Type' => 'application/json'])
                    ->post($endpoint, [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $schema,
                        ],
                    ]);

                if ($response->failed()) {
                    throw new RuntimeException('Gemini API returned status ' . $response->status() . ': ' . $response->body());
                }

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('Gemini returned no text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini response was not valid JSON.');
                }

                return $decoded;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini WBS model {$model} failed, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'));
    }

    /**
     * @return array<int, string>
     */
    private function models(): array
    {
        $models = [
            'gemini-3.5-pro', 'gemini-2.5-pro', 'gemini-2.0-pro', 'gemini-1.5-pro',
            'gemini-3.5-flash', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash',
        ];

        $configured = (string) config('services.gemini.model');
        if ($configured !== '') {
            $models = array_values(array_filter($models, fn ($m) => $m !== $configured));
            array_unshift($models, $configured);
        }

        return $models;
    }

    /**
     * @param  array{label: string, project: string, type: string, scope: string}  $c
     */
    private function wbsPrompt(array $c): string
    {
        return <<<PROMPT
당신은 미국 내 한국 대기업 플랜트/공장(LG배터리·SK반도체·현대차 등) 설치공사의 공정관리 전문가입니다.
아래 프로젝트의 작업범위를 분석하여 **WBS(작업분해구조)**를 Stage → Task → SubTask 3단계로 분해하세요.
현실적인 설치 시퀀스(반입→설치→배관/배선→시운전)를 따르고, JSON만 반환합니다.

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
  - company: 담당 협력사. NAHSHON / AUTORICA / AI-KOREA / M-SOL 중 하나로 배정.
  - manhours: 예상 공수(정수, man-hour)
  - days: 예상 소요일(정수)
  - ehs: 안전 위험도. high(고소·중량물·전기·밀폐) / medium / low 중 하나.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function wbsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'stage_no' => ['type' => 'string'],
                            'stage_name' => ['type' => 'string'],
                            'tasks' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'task_no' => ['type' => 'string'],
                                        'task_name' => ['type' => 'string'],
                                        'sub_tasks' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'sub_no' => ['type' => 'string'],
                                                    'sub_name' => ['type' => 'string'],
                                                    'company' => ['type' => 'string'],
                                                    'manhours' => ['type' => 'integer'],
                                                    'days' => ['type' => 'integer'],
                                                    'ehs' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
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
