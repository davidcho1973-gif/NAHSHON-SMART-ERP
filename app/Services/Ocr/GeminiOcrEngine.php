<?php

namespace App\Services\Ocr;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemini(Google) 비전 분석 엔진. 기존 Gemini*Analyzer 들이 쓰던 요청 형태를 그대로 재현한다
 * (contents.parts = inline_data[] + text, generationConfig.responseSchema, 모델 폴백 체인).
 */
class GeminiOcrEngine implements OcrEngine
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function name(): string
    {
        return 'gemini';
    }

    /**
     * 큰 파일은 요청에 싣지 않고 먼저 올려 두므로(Files API) 요청 크기에 묶이지 않는다.
     *
     * 그래도 무한은 아니다 — Files API 자체는 파일 하나 2GB 까지지만, PDF 판독은 50MB·1000페이지가
     * 별도 한도다. 우리가 다루는 것은 사실상 전부 PDF 라 낮은 쪽을 기본값으로 둔다.
     * 2GB 를 적어 두면 «올라는 가는데 분석에서 튕기는» 더 나쁜 실패가 된다.
     */
    public function maxAttachmentBytes(): int
    {
        return (int) config('services.gemini.max_attachment_bytes', 50 * 1024 * 1024);
    }

    /** 이 크기를 넘으면 요청에 싣지 않고 Files API 로 먼저 올린다. */
    private function inlineMaxBytes(): int
    {
        return (int) config('services.gemini.inline_max_bytes', 15 * 1024 * 1024);
    }

    /**
     * @param  array<int, array{data: string, mime_type: string}>  $images
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string}
     */
    public function analyze(array $images, string $prompt, array $schema): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        // 작은 것은 요청에 그대로 싣고(inline_data), 큰 것은 먼저 올려 두고 주소만 넘긴다(file_data).
        // 두 방식을 섞어 쓸 수 있어서, 도면 한 장이 커도 나머지는 그대로 간다.
        $parts = [];
        $uploaded = [];   // 다 쓰고 지울 파일 이름들
        foreach ($images as $img) {
            $b64 = (string) ($img['data'] ?? '');
            if ($b64 === '') {
                continue;
            }
            $mime = (string) ($img['mime_type'] ?? 'image/jpeg');
            // base64 는 원본의 약 4/3 이다 — 디코딩 없이 원본 크기를 가늠한다.
            $rawSize = (int) (strlen($b64) * 3 / 4);

            if ($rawSize <= $this->inlineMaxBytes()) {
                $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];

                continue;
            }

            $file = $this->uploadFile(base64_decode($b64, true) ?: '', $mime, $apiKey);
            $parts[] = ['file_data' => ['mime_type' => $mime, 'file_uri' => $file['uri']]];
            $uploaded[] = $file['name'];
        }
        $parts[] = ['text' => $prompt];

        try {
            return $this->generate($parts, $schema, $apiKey);
        } finally {
            // 48시간이면 알아서 지워지지만, 프로젝트 전체 20GB 한도를 도면이 잡아먹지 않게 바로 치운다.
            // 지우기가 실패해도 분석 결과를 버리면 안 되므로 조용히 삼킨다.
            foreach ($uploaded as $name) {
                try {
                    $this->deleteFile($name, $apiKey);
                } catch (\Throwable $e) {
                    Log::warning("Gemini Files API 삭제 실패({$name}): ".$e->getMessage());
                }
            }
        }
    }

    /**
     * 모델 폴백 체인을 돌며 실제 분석을 부른다.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, model: string}
     */
    private function generate(array $parts, array $schema, string $apiKey): array
    {
        $lastException = null;

        foreach ($this->models() as $model) {
            try {
                $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
                    . "/v1beta/models/{$model}:generateContent";

                $startedAt = microtime(true);
                $response = $this->http
                    ->timeout((int) config('services.gemini.timeout', 30))
                    ->withHeaders(['x-goog-api-key' => $apiKey, 'Content-Type' => 'application/json'])
                    ->post($endpoint, [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $schema,
                        ],
                    ]);

                $ms = (int) round((microtime(true) - $startedAt) * 1000);

                if ($response->failed()) {
                    \App\Support\AiMeter::record('gemini', 'ocr', $model, durationMs: $ms, ok: false, error: 'HTTP '.$response->status());

                    throw new RuntimeException('Gemini API returned status ' . $response->status() . ': ' . $response->body());
                }

                // 모델 폴백이 있어 여러 번 부를 수 있다 — 실제로 돈이 나간 호출마다 적는다.
                \App\Support\AiMeter::record('gemini', 'ocr', $model, is_array($response->json('usageMetadata')) ? $response->json('usageMetadata') : [], $ms);

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('Gemini returned no analysis text.');
                }

                $decoded = json_decode($this->stripJsonFence($text), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Gemini analysis was not valid JSON.');
                }

                return ['data' => $decoded, 'model' => $model];
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Gemini OCR model {$model} failed, falling back. Error: " . $e->getMessage());
            }
        }

        throw new RuntimeException('All Gemini models failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'));
    }

    /**
     * 큰 파일을 Files API 로 올린다 — 두 걸음이다.
     *
     *  1) 메타데이터만 보내 «올릴 자리» 를 받는다. 자리 주소는 응답 «헤더» 의 x-goog-upload-url 에 온다.
     *  2) 그 주소로 원본 바이트를 그대로 보낸다. 이때는 열쇠도 Content-Type 도 붙이지 않는다 —
     *     1회용 인증 주소이고 MIME 은 1단계에서 이미 말했다.
     *
     * 올린 직후에는 아직 못 쓴다. 상태가 PROCESSING 이면 «추론에 쓸 수 없음» 이라고 규격이 못 박고 있어,
     * ACTIVE 가 될 때까지 기다린다.
     *
     * @return array{uri: string, name: string}
     */
    private function uploadFile(string $bytes, string $mime, string $apiKey): array
    {
        if ($bytes === '') {
            throw new RuntimeException('Gemini Files API: 올릴 내용이 비어 있습니다.');
        }
        $size = strlen($bytes);   // 바이트 길이여야 한다. mb_strlen 을 쓰면 값이 틀어진다.
        $base = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/');

        $start = $this->http
            ->timeout((int) config('services.gemini.upload_timeout', 120))
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => $mime,
            ])
            ->post($base.'/upload/v1beta/files', ['file' => ['display_name' => 'doc-'.substr(sha1($bytes), 0, 12)]]);

        if ($start->failed()) {
            throw new RuntimeException('Gemini Files API 업로드 시작 실패 '.$start->status().': '.$start->body());
        }

        // 헤더 이름은 소문자로 오지만 라라벨은 대소문자를 가리지 않는다.
        $uploadUrl = (string) $start->header('x-goog-upload-url');
        if ($uploadUrl === '') {
            throw new RuntimeException('Gemini Files API: 업로드 주소(x-goog-upload-url)가 응답 헤더에 없습니다.');
        }

        $put = $this->http
            ->timeout((int) config('services.gemini.upload_timeout', 120))
            ->withHeaders([
                'Content-Length' => (string) $size,
                'X-Goog-Upload-Offset' => '0',
                // 쉼표와 빈칸까지 포함해 «헤더 값 하나» 다. 두 헤더로 쪼개면 안 된다.
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])
            ->withBody($bytes, $mime)
            ->post($uploadUrl);

        if ($put->failed()) {
            throw new RuntimeException('Gemini Files API 업로드 실패 '.$put->status().': '.$put->body());
        }

        $json = $put->json();
        // finalize 응답은 {"file": {...}} 로 한 겹 감싸 온다. files.get 은 File 이 최상위다 — 둘 다 받는다.
        $file = is_array($json['file'] ?? null) ? $json['file'] : (is_array($json) ? $json : []);
        $uri = (string) ($file['uri'] ?? '');
        $name = (string) ($file['name'] ?? '');   // 이미 "files/…" 접두어를 포함한다.
        if ($uri === '' || $name === '') {
            throw new RuntimeException('Gemini Files API: 응답에 uri/name 이 없습니다: '.$put->body());
        }

        $this->waitUntilActive($name, $base, $apiKey, (string) ($file['state'] ?? ''));

        return ['uri' => $uri, 'name' => $name];
    }

    /** PROCESSING 인 동안 기다린다. ACTIVE 가 되어야 분석에 쓸 수 있다. */
    private function waitUntilActive(string $name, string $base, string $apiKey, string $state): void
    {
        $tries = (int) config('services.gemini.upload_poll_tries', 20);
        for ($i = 0; $i < $tries; $i++) {
            if ($state === 'ACTIVE') {
                return;
            }
            if ($state === 'FAILED') {
                throw new RuntimeException("Gemini Files API: 파일 처리에 실패했습니다({$name}).");
            }
            usleep(1_500_000);
            // name 이 이미 "files/" 를 품고 있으므로 files/ 를 또 붙이면 404 다.
            $get = $this->http->timeout(20)->withHeaders(['x-goog-api-key' => $apiKey])->get($base.'/v1beta/'.$name);
            if ($get->failed()) {
                throw new RuntimeException('Gemini Files API 상태 조회 실패 '.$get->status().': '.$get->body());
            }
            $j = $get->json();
            $state = (string) (($j['file']['state'] ?? null) ?? ($j['state'] ?? ''));
        }

        throw new RuntimeException("Gemini Files API: 파일이 제한 시간 안에 준비되지 않았습니다({$name}, 상태 {$state}).");
    }

    private function deleteFile(string $name, string $apiKey): void
    {
        $base = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/');
        $this->http->timeout(20)->withHeaders(['x-goog-api-key' => $apiKey])->delete($base.'/v1beta/'.$name);
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
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }

        return trim($text);
    }
}
