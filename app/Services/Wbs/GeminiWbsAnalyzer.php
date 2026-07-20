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
        private readonly ScheduleImporter $scheduleImporter,
    ) {
    }

    /**
     * 프로젝트의 작업범위 → AI WBS 생성 → 영속화. 프론트(runWbsAiAnalysis)가 기대하는 결과 형태 반환.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array{data: string, media_type?: string}|null  $pdf  base64 PDF (선택) — 있으면 매뉴얼 본문을 근거로 분석
     */
    public function processManual(string $projectCode, string $siteId = 'ALL', ?array $pdf = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return ['success' => false, 'processed' => 0, 'results' => [], 'error' => 'GEMINI_API_KEY 가 설정되지 않았습니다.'];
        }

        $context = $this->buildContext($projectCode);
        // 충실한 추출 우선: 문서가 CPM/공정표면 모든 행을 activities 로, 산문형이면 stages 로 반환.
        $structure = $this->generate(CpmExtraction::prompt($context), CpmExtraction::schema(false), $pdf);

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
                    'engine' => 'gemini',
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
                'engine' => 'gemini',
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
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $schema
     * @param  array{data: string, media_type?: string}|null  $pdf
     * @return array<string, mixed>
     */
    private function generate(string $prompt, array $schema, ?array $pdf = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        // 매뉴얼 파일이 있으면 본문(inline_data)을 프롬프트 앞에 붙인다. Gemini 는 PDF/이미지 inline 지원.
        $parts = [];
        if ($pdf !== null && ($pdf['data'] ?? '') !== '') {
            $parts[] = ['inline_data' => [
                'mime_type' => (string) ($pdf['media_type'] ?? 'application/pdf'),
                'data' => (string) $pdf['data'],
            ]];
        }
        $parts[] = ['text' => $prompt];

        $lastException = null;

        foreach ($this->models() as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 60))
                    ->withHeaders(['x-goog-api-key' => $apiKey, 'Content-Type' => 'application/json'])
                    ->post($endpoint, [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $schema,
                            // CPM 전량 추출이면 출력이 크다 — 기본(8k)에서 잘리지 않게 상향.
                            'maxOutputTokens' => 32000,
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
     * 시도할 Gemini 모델 목록 — 실제 사용 가능한 모델을 조회해 고른다(하드코딩 404 방지).
     *
     * @return array<int, string>
     */
    private function models(): array
    {
        return app(\App\Support\GeminiModelResolver::class)->candidates();
    }

    private function stripJsonFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);

        return trim((string) $text);
    }
}
