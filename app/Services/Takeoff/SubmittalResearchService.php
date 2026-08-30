<?php

namespace App\Services\Takeoff;

use App\Models\IntelligentDocument;
use App\Models\Submittal;
use App\Services\Documents\DocumentIntake;
use App\Support\AiMeter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 제품 제출물의 자료를 <b>AI 웹 조사</b>로 찾아, 고른 것을 받아 문서함에 편철한다.
 *
 * 제품자료(Product Data)·시험성적서는 제조사가 홈페이지에 공개해 둔다 — 새로 만들
 * 필요가 없고 <b>찾으면 된다</b>. AI 에게 시방 조항(요구 규격·ASTM 번호)을 주고
 * 웹을 검색시켜 후보를 받아 온다. 웹 검색이 되는 엔진(Claude → Gemini → OpenAI)
 * 중 키가 설정된 것을 순서대로 쓴다.
 *
 * 지키는 것 둘:
 *  1. AI 가 찾은 것은 <b>후보</b>다. 편철은 사람이 보고 고른 뒤에만 한다 —
 *     틀린 모델의 스팩을 제출하면 반려가 아니라 신뢰를 잃는다.
 *  2. 서버는 <b>조사 결과에 있던 주소만</b> 내려받는다. 화면이 보낸 임의 주소를
 *     받으면 서버가 내부망을 대신 두드리는 구멍(SSRF)이 된다.
 */
class SubmittalResearchService
{
    /** 조사 결과를 잠깐 붙들어 두는 시간 — 사람이 보고 고르는 동안만 있으면 된다. */
    private const CACHE_HOURS = 2;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{success: bool, error?: string, engine?: string, candidates?: list<array<string, string>>}
     */
    public function research(Submittal $submittal): array
    {
        $engine = $this->pickEngine();
        if ($engine === null) {
            return ['success' => false, 'error' => 'AI API 키가 설정되어 있지 않습니다. 관리 대시보드에서 키를 넣어 주세요.'];
        }

        $startedAt = microtime(true);
        try {
            $candidates = match ($engine) {
                'claude' => $this->viaClaude($submittal),
                'gemini' => $this->viaGemini($submittal),
                default => $this->viaOpenAi($submittal),
            };
        } catch (Throwable $e) {
            AiMeter::record($engine, 'submittal_research', null,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                ok: false, error: $e->getMessage(), subjectType: 'submittal', subjectId: $submittal->id);

            return ['success' => false, 'error' => 'AI 조사 실패: '.$e->getMessage()];
        }

        // 화면이 보내올 것은 번호뿐 — 주소는 서버가 여기 붙들어 둔 것만 쓴다.
        Cache::put($this->cacheKey($submittal), $candidates, now()->addHours(self::CACHE_HOURS));

        return ['success' => true, 'engine' => $engine, 'candidates' => $candidates];
    }

    /**
     * 조사 후보 하나를 내려받아 문서함에 편철하고 제출물에 연결한다.
     *
     * @return array{success: bool, error?: string, documentId?: int, title?: string, message?: string}
     */
    public function fileCandidate(Submittal $submittal, int $index, ?int $userId = null): array
    {
        $candidates = Cache::get($this->cacheKey($submittal));
        if (! is_array($candidates) || ! isset($candidates[$index])) {
            return ['success' => false, 'error' => '조사 결과가 만료되었습니다. AI 조사를 다시 실행해 주세요.'];
        }

        $candidate = $candidates[$index];
        $url = (string) ($candidate['url'] ?? '');
        if (! $this->safeUrl($url)) {
            return ['success' => false, 'error' => '이 주소는 서버가 내려받을 수 없는 형식입니다. 「페이지 열기」로 직접 받아 문서함에 올려 주세요.'];
        }

        try {
            $response = $this->http->timeout(90)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; DocumentFetch)'])
                ->get($url);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => '다운로드 실패: '.$e->getMessage().' — 「페이지 열기」로 직접 받아 올려 주세요.'];
        }

        if ($response->failed()) {
            return ['success' => false, 'error' => '다운로드 실패(HTTP '.$response->status().'). 「페이지 열기」로 직접 받아 올려 주세요.'];
        }

        $body = $response->body();
        $maxBytes = (int) config('document-intelligence.max_upload_kb', 51200) * 1024;
        if (strlen($body) > $maxBytes) {
            return ['success' => false, 'error' => '파일이 너무 큽니다('.round(strlen($body) / 1048576).'MB). 직접 받아 나눠 올려 주세요.'];
        }

        // 스팩 자료는 PDF 다. HTML 이 왔다는 것은 다운로드 페이지로 연결된 것 —
        // 그 페이지를 편철하면 자료가 아니라 광고를 편철하는 셈이다.
        if (! str_starts_with($body, '%PDF')) {
            return ['success' => false, 'error' => '이 주소는 PDF 가 아니라 웹페이지입니다. 「페이지 열기」에서 PDF 를 직접 받아 문서함에 올려 주세요.'];
        }

        $name = $this->fileName($submittal, $candidate);
        $tmp = tempnam(sys_get_temp_dir(), 'subres');
        file_put_contents($tmp, $body);

        try {
            $result = app(DocumentIntake::class)->ingest(
                new UploadedFile($tmp, $name, 'application/pdf', null, true),
                ['company_id' => $submittal->company_id, 'site_id' => $submittal->site_id, 'project_id' => $submittal->project_id],
                ['uploaded_by' => $userId, 'source' => 'ai_research'],
            );
        } finally {
            @unlink($tmp);
        }

        $document = $result['document'] ?? null;
        if (! $document instanceof IntelligentDocument) {
            return ['success' => false, 'error' => $result['reason'] ?: '문서함 편철에 실패했습니다.'];
        }

        $this->link($submittal, $document, $userId);

        // 자료가 도착했으니 대장도 움직인다 — 미착수로 놔두면 화면이 거짓말을 한다.
        if ($submittal->status === '미착수') {
            $submittal->forceFill(['status' => '작성중'])->save();
        }

        return [
            'success' => true,
            'documentId' => $document->id,
            'title' => $document->title ?: $name,
            'message' => "'{$name}' 을(를) 문서함에 편철하고 이 제출물에 연결했습니다.",
        ];
    }

    /** 이미 문서함에 있는 문서를 제출물에 연결한다 — 업체가 보내온 자료를 손으로 이을 때. */
    public function link(Submittal $submittal, IntelligentDocument $document, ?int $userId = null): void
    {
        $submittal->documents()->syncWithoutDetaching([
            $document->id => ['kind' => 'received', 'created_by' => $userId],
        ]);
    }

    /* ─── AI 엔진별 웹 조사 ─────────────────────────────────────────────── */

    private function pickEngine(): ?string
    {
        if ((string) config('services.anthropic.api_key') !== '') {
            return 'claude';
        }
        if ((string) config('services.gemini.api_key') !== '') {
            return 'gemini';
        }
        if ((string) config('services.openai.api_key') !== '') {
            return 'openai';
        }

        return null;
    }

    /** @return list<array<string, string>> */
    private function viaClaude(Submittal $submittal): array
    {
        $endpoint = rtrim((string) config('services.anthropic.endpoint', 'https://api.anthropic.com'), '/').'/v1/messages';
        $model = (string) config('services.anthropic.model', 'claude-opus-4-8');
        $startedAt = microtime(true);

        $response = $this->http->timeout(150)
            ->withHeaders([
                'x-api-key' => (string) config('services.anthropic.api_key'),
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
            ->post($endpoint, [
                'model' => $model,
                'max_tokens' => 4000,
                'tools' => [['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5]],
                'messages' => [['role' => 'user', 'content' => $this->prompt($submittal)]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic HTTP '.$response->status());
        }

        $json = $response->json();
        AiMeter::record('claude', 'submittal_research', (string) ($json['model'] ?? $model),
            is_array($json['usage'] ?? null) ? $json['usage'] : [],
            (int) round((microtime(true) - $startedAt) * 1000),
            subjectType: 'submittal', subjectId: $submittal->id);

        $text = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $this->parseCandidates($text);
    }

    /** @return list<array<string, string>> */
    private function viaGemini(Submittal $submittal): array
    {
        $model = (string) config('services.gemini.model', 'gemini-2.5-pro');
        $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
            ."/v1beta/models/{$model}:generateContent";
        $startedAt = microtime(true);

        $response = $this->http->timeout(150)
            ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
            ->post($endpoint, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $this->prompt($submittal)]]]],
                'tools' => [['google_search' => (object) []]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini HTTP '.$response->status());
        }

        $json = $response->json();
        AiMeter::record('gemini', 'submittal_research', $model,
            is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [],
            (int) round((microtime(true) - $startedAt) * 1000),
            subjectType: 'submittal', subjectId: $submittal->id);

        $text = '';
        foreach ((array) data_get($json, 'candidates.0.content.parts', []) as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return $this->parseCandidates($text);
    }

    /** @return list<array<string, string>> */
    private function viaOpenAi(Submittal $submittal): array
    {
        $endpoint = rtrim((string) config('services.openai.endpoint', 'https://api.openai.com'), '/').'/v1/responses';
        $model = (string) config('services.openai.model', 'gpt-5');
        $startedAt = microtime(true);

        $response = $this->http->timeout(150)
            ->withToken((string) config('services.openai.api_key'))
            ->post($endpoint, [
                'model' => $model,
                'tools' => [['type' => 'web_search']],
                'input' => $this->prompt($submittal),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI HTTP '.$response->status());
        }

        $json = $response->json();
        AiMeter::record('openai', 'submittal_research', (string) ($json['model'] ?? $model),
            is_array($json['usage'] ?? null) ? $json['usage'] : [],
            (int) round((microtime(true) - $startedAt) * 1000),
            subjectType: 'submittal', subjectId: $submittal->id);

        $text = '';
        foreach ((array) ($json['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $piece) {
                $text .= (string) ($piece['text'] ?? '');
            }
        }

        return $this->parseCandidates($text);
    }

    /* ─── 공통 ──────────────────────────────────────────────────────────── */

    private function prompt(Submittal $submittal): string
    {
        return implode("\n", array_filter([
            '당신은 미국 건설현장의 제출물(Submittal) 담당자입니다. 아래 시방 조항이 요구하는',
            '제품 자료를 웹에서 검색해, 제출에 쓸 수 있는 <b>실제 제조사 공식 자료</b>를 찾으세요.',
            '',
            '## 시방 조항',
            'CSI: '.trim($submittal->csi.' '.$submittal->section),
            '요구사항: '.$submittal->title,
            $submittal->source_excerpt ? '원문: '.$submittal->source_excerpt : null,
            '',
            '## 지켜야 할 것',
            '1. 검색 결과에서 실제로 확인한 주소만 답하세요. 지어낸 주소는 절대 금지입니다.',
            '2. 제조사 공식 사이트의 제품자료(Product Data Sheet)·시험성적(Test Report)·',
            '   SDS 를 우선하고, PDF 직링크가 있으면 그것을 url 로 쓰세요.',
            '3. 조항의 규격(ASTM 번호·Type·등급)과 맞는 제품만 고르세요. 규격이 다른',
            '   제품은 후보에 넣지 말고, 확실하지 않으면 why 에 그렇게 적으세요.',
            '4. 후보는 최대 5개. 서로 다른 제조사면 더 좋습니다(대안 비교).',
            '5. 마지막 줄에 아래 JSON 만 출력하세요(코드펜스 없이):',
            '{"candidates":[{"maker":"제조사","product":"제품명","url":"https://...","file":"pdf|page","why":"이 제품이 조항 규격과 맞는 이유(한국어 한 문장)"}]}',
        ]));
    }

    /**
     * AI 답변에서 후보 JSON 을 꺼낸다 — 웹 검색을 거친 답은 설명 글에 JSON 이 섞여
     * 오므로, 마지막 JSON 덩어리를 찾아 관대하게 읽는다.
     *
     * @return list<array<string, string>>
     */
    private function parseCandidates(string $text): array
    {
        $start = strrpos($text, '{"candidates"');
        if ($start === false) {
            $start = strpos($text, '{');
        }
        $decoded = null;
        if ($start !== false) {
            $slice = substr($text, $start);
            $end = strrpos($slice, '}');
            if ($end !== false) {
                $decoded = json_decode(substr($slice, 0, $end + 1), true);
            }
        }

        $out = [];
        foreach ((array) data_get($decoded, 'candidates', []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $url = trim((string) ($c['url'] ?? ''));
            if ($url === '' || ! preg_match('~^https?://~i', $url)) {
                continue;
            }
            $out[] = [
                'maker' => Str::limit(trim((string) ($c['maker'] ?? '')), 80),
                'product' => Str::limit(trim((string) ($c['product'] ?? '')), 160),
                'url' => Str::limit($url, 1000, ''),
                'file' => strtolower(trim((string) ($c['file'] ?? ''))) === 'pdf' ? 'pdf' : 'page',
                'why' => Str::limit(trim((string) ($c['why'] ?? '')), 300),
            ];
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /** 서버가 대신 받아도 안전한 주소인가 — 공인 https 만, 내부망 이름·주소는 거른다. */
    private function safeUrl(string $url): bool
    {
        if (! preg_match('~^https://~i', $url)) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /** @param array<string, string> $candidate */
    private function fileName(Submittal $submittal, array $candidate): string
    {
        $base = trim(($submittal->csi ?: 'SUBMITTAL').' '.($candidate['maker'] ?? '').' '.($candidate['product'] ?? ''));
        $base = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $base) ?: 'product-data';

        return Str::limit(trim(preg_replace('/\s+/', ' ', $base)), 120, '').'.pdf';
    }

    private function cacheKey(Submittal $submittal): string
    {
        return 'submittal-research:'.$submittal->id;
    }
}
