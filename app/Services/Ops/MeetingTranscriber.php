<?php

namespace App\Services\Ops;

use App\Models\OpsMeeting;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MeetingTranscriber
{
    public function readiness(): array
    {
        return ['gemini' => filled(config('services.gemini.api_key')), 'scribe' => filled(config('meetings.elevenlabs_key'))];
    }

    public function transcribe(OpsMeeting $meeting, array $terms): array
    {
        $out = $meeting->transcripts ?? [];
        $todo = array_values(array_filter(['gemini', 'scribe'], fn ($p) => ($out[$p]['status'] ?? '') !== 'done'));
        if ($todo === []) {
            return $out;
        }
        $bytes = Storage::disk($meeting->disk)->get($meeting->audio_path);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $meeting->audio_hash) {
            throw new RuntimeException('보관된 녹음의 무결성을 확인할 수 없습니다.');
        }
        $ready = $this->readiness();
        $file = null;
        foreach ($todo as $p) {
            if (! $ready[$p]) {
                $out[$p] = ['status' => 'failed', 'error' => $p.' API 키가 설정되지 않았습니다.'];
            }
        }
        if (in_array('gemini', $todo, true) && $ready['gemini']) {
            try {
                $file = $this->upload($bytes, $meeting->audio_mime);
            } catch (Throwable $e) {
                $out['gemini'] = ['status' => 'failed', 'error' => $this->safeError($e)];
            }
        }
        $started = microtime(true);
        try {
            // The requests start together; neither transcript is sent to the other provider.
            $responses = Http::pool(function (Pool $pool) use ($todo, $ready, $file, $meeting, $bytes, $terms) {
                $requests = [];
                if ($file) {
                    $requests[] = $pool->as('gemini')->connectTimeout(20)->timeout(600)
                        ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
                        ->post($this->base().'/v1beta/interactions', [
                            'model' => config('meetings.gemini_model'),
                            'input' => [['type' => 'audio', 'uri' => $file['uri'], 'mime_type' => $meeting->audio_mime]],
                            'generation_config' => ['transcription_config' => ['custom_vocabulary' => $terms, 'mode' => ['type' => 'verbatim']]],
                        ]);
                }
                if (in_array('scribe', $todo, true) && $ready['scribe']) {
                    $r = $pool->as('scribe')->connectTimeout(20)->timeout(600)
                        ->withHeaders(['xi-api-key' => config('meetings.elevenlabs_key')])
                        ->attach('file', $bytes, 'meeting.'.$this->extension($meeting->audio_mime));
                    $form = [['name' => 'model_id', 'contents' => config('meetings.scribe_model')],
                        ['name' => 'diarize', 'contents' => 'true'], ['name' => 'timestamps_granularity', 'contents' => 'word'],
                        ['name' => 'tag_audio_events', 'contents' => 'false']];
                    foreach ($terms as $term) {
                        $form[] = ['name' => 'keyterms', 'contents' => $term];
                    }
                    $requests[] = $r->post('https://api.elevenlabs.io/v1/speech-to-text', $form);
                }

                return $requests;
            });
            foreach ($responses as $provider => $response) {
                try {
                    if (! $response instanceof Response) {
                        throw new RuntimeException($provider.' 연결이 끊겼습니다. 재시도해 주세요.');
                    }
                    $this->check($response, $provider);
                    $out[$provider] = $this->normalize($provider, $response->json());
                    $out[$provider]['elapsed_ms'] = (int) ((microtime(true) - $started) * 1000);
                } catch (Throwable $e) {
                    $out[$provider] = ['status' => 'failed', 'error' => $this->safeError($e)];
                }
                // A later provider/analysis failure must never discard a successful paid result.
                $meeting->update(['transcripts' => $out]);
            }
        } finally {
            if ($file) {
                try {
                    Http::timeout(15)->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])->delete($this->base().'/v1beta/'.$file['name']);
                } catch (Throwable) { /* Provider expires uploaded files; never discard transcription on cleanup error. */
                }
            }
        }

        return $out;
    }

    public function normalize(string $provider, array $raw): array
    {
        if ($provider === 'gemini') {
            $texts = [];
            foreach ($raw['steps'] ?? [] as $step) {
                if (($step['type'] ?? '') !== 'model_output') {
                    continue;
                }
                foreach ($step['content'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'text') {
                        $texts[] = $c['text'] ?? '';
                    }
                }
            }
            $text = trim($raw['output_text'] ?? implode("\n", $texts));
            $segments = [];
        } else {
            $text = trim((string) ($raw['text'] ?? ''));
            $segments = [];
            foreach ($raw['words'] ?? [] as $word) {
                if (($word['type'] ?? '') !== 'word' || ! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null)) {
                    continue;
                }
                $start = (float) $word['start'];
                $end = (float) $word['end'];
                if ($start < 0 || $end < $start || $end > config('meetings.max_seconds')) {
                    throw new RuntimeException('녹음은 30분 이내로 올려 주세요.');
                }
                $segments[] = ['text' => (string) $word['text'], 'start' => $start, 'end' => $end, 'speaker' => (string) ($word['speaker_id'] ?? '')];
            }
            if ($segments === []) {
                throw new RuntimeException('Scribe 응답에 발언 시간 근거가 없습니다.');
            }
        }
        if ($text === '' || mb_strlen($text) > 150000) {
            throw new RuntimeException('전사 결과가 비어 있거나 처리 한도를 넘었습니다.');
        }

        return ['status' => 'done', 'model' => config('meetings.'.($provider === 'gemini' ? 'gemini_model' : 'scribe_model')),
            'text' => $text, 'segments' => $segments, 'usage' => $raw['usage'] ?? [], 'recorded_at' => now()->toIso8601String()];
    }

    private function upload(string $bytes, string $mime): array
    {
        $key = config('services.gemini.api_key');
        $start = Http::timeout(30)->withHeaders(['x-goog-api-key' => $key, 'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start', 'X-Goog-Upload-Header-Content-Length' => (string) strlen($bytes),
            'X-Goog-Upload-Header-Content-Type' => $mime])->post($this->base().'/upload/v1beta/files', ['file' => ['display_name' => 'ERP meeting']]);
        $this->check($start, 'Gemini upload');
        $url = $start->header('x-goog-upload-url');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'generativelanguage.googleapis.com') {
            throw new RuntimeException('Gemini 업로드 주소를 검증할 수 없습니다.');
        }
        $put = Http::timeout(120)->withHeaders(['X-Goog-Upload-Offset' => '0', 'X-Goog-Upload-Command' => 'upload, finalize'])
            ->withBody($bytes, $mime)->post($url);
        $this->check($put, 'Gemini upload');
        $file = $put->json('file');
        if (! is_array($file) || ! preg_match('~^files/[a-zA-Z0-9_-]+$~', $file['name'] ?? '') || empty($file['uri'])) {
            throw new RuntimeException('Gemini 파일 응답 형식을 확인해 주세요.');
        }
        for ($i = 0; $i < 20 && ($file['state'] ?? '') === 'PROCESSING'; $i++) {
            usleep(1500000);
            $r = Http::timeout(20)->withHeaders(['x-goog-api-key' => $key])->get($this->base().'/v1beta/'.$file['name']);
            $this->check($r, 'Gemini file status');
            $file = $r->json();
        }
        if (($file['state'] ?? '') !== 'ACTIVE') {
            throw new RuntimeException('Gemini 녹음 파일 준비가 완료되지 않았습니다.');
        }

        return $file;
    }

    private function check(Response $r, string $provider): void
    {
        // Never expose provider request bodies, keys or meeting content in public errors/logs.
        if (! $r->successful()) {
            throw new RuntimeException($provider.' HTTP '.$r->status().' — 키·모델 접근권한·사용 한도를 확인해 주세요.');
        }
    }

    private function safeError(Throwable $e): string
    {
        return get_class($e) === RuntimeException::class ? $e->getMessage() : '음성인식 연결 또는 응답 처리에 실패했습니다. 성공한 결과는 보존됩니다.';
    }

    private function base(): string
    {
        return rtrim(config('services.gemini.endpoint'), '/');
    }

    private function extension(string $mime): string
    {
        return ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/m4a' => 'm4a'][$mime] ?? 'bin';
    }
}
