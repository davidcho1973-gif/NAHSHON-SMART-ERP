<?php

namespace App\Services\Ops;

use App\Services\Ocr\GeminiOcrEngine;
use Illuminate\Support\Facades\Log;

/**
 * 말한 것을 글자로 옮긴다 — 반장이 장갑 낀 손으로 타자를 치지 않아도 되게.
 *
 * ── 왜 «옮기기» 만 하는가 ────────────────────────────────────────────────
 * 여기서는 <b>받아 적기만</b> 한다. 요약도, 정리도, 해석도 하지 않는다. 판독은
 * 그다음 단계(OpsIntakeAnalyzer)가 글자를 보고 하는 일이고, 그 두 가지를 한 번에
 * 시키면 무엇이 반장이 한 말이고 무엇이 AI 가 붙인 말인지 가릴 수 없게 된다.
 *
 * ── 왜 사람에게 되보여 주는가 ────────────────────────────────────────────
 * 현장은 시끄럽다. 잘못 들린 한 단어가 그대로 공정표 숫자가 되면 안 되므로, 옮긴
 * 글은 반드시 화면에 띄워 반장이 고칠 수 있게 한 뒤에 보낸다. 이 클래스는 글자만
 * 돌려주고, 보내는 것은 사람이 정한다.
 */
class VoiceNoteTranscriber
{
    /** 한 번에 받는 녹음 길이 상한(초). 현장 보고 한 건은 이 안에 끝난다. */
    public const MAX_SECONDS = 180;

    /** 폰마다 내놓는 형식이 다르다 — 아이폰은 mp4, 안드로이드는 webm 이 보통이다. */
    public const ALLOWED_MIMES = [
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg',
        'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/flac',
    ];

    /**
     * 녹음 한 건을 글자로.
     *
     * @param  string  $bytes  녹음 원본
     * @return array{success: bool, text?: string, error?: string}
     */
    public function transcribe(string $bytes, string $mime): array
    {
        if ($bytes === '') {
            return ['success' => false, 'error' => '녹음이 비어 있습니다. 다시 한번 말씀해 주세요.'];
        }

        // 음성은 제미나이로 보낸다 — 세 엔진 가운데 소리를 그대로 받는 것이 이것이다.
        // 기본 엔진 설정을 따라가면 클로드·OpenAI 로 갔다가 조용히 실패한다.
        if ((string) config('services.gemini.api_key') === '') {
            return ['success' => false, 'error' => '음성 인식이 아직 켜져 있지 않습니다. 글로 적어 주세요.'];
        }

        try {
            $result = app(GeminiOcrEngine::class)->analyze(
                [['data' => base64_encode($bytes), 'mime_type' => $mime]],
                $this->prompt(),
                $this->schema(),
            );
        } catch (\Throwable $e) {
            Log::warning('현장 음성 받아쓰기 실패: '.$e->getMessage());

            return ['success' => false, 'error' => '말씀을 옮기지 못했습니다. 다시 녹음하거나 글로 적어 주세요.'];
        }

        $text = trim((string) ($result['data']['text'] ?? ''));

        if ($text === '') {
            return ['success' => false, 'error' => '소리가 잘 안 들렸습니다. 조용한 곳에서 다시 말씀해 주세요.'];
        }

        return ['success' => true, 'text' => $text];
    }

    /**
     * 녹음 파일이 받아들일 만한가.
     *
     * @return string|null 문제가 있으면 사람이 읽는 까닭
     */
    public function reject(string $mime, int $bytes): ?string
    {
        // 폰이 'audio/webm;codecs=opus' 처럼 코덱까지 붙여 보낸다.
        $base = strtolower(trim(explode(';', $mime)[0]));

        if (! in_array($base, self::ALLOWED_MIMES, true)) {
            return '녹음 파일만 올릴 수 있습니다.';
        }

        if ($bytes > 25 * 1024 * 1024) {
            return '녹음이 너무 깁니다. 한 번에 3분 안쪽으로 말씀해 주세요.';
        }

        return null;
    }

    private function prompt(): string
    {
        return <<<'P'
        당신은 미국 내 건설현장 무전 내용을 받아 적는 사람입니다.
        아래 녹음을 **들리는 그대로** 글자로 옮기세요. JSON 만 반환합니다.

        ## 반드시 지킬 것
        1. **요약하지 마세요.** 말한 문장을 그대로 옮깁니다.
        2. **없는 말을 넣지 마세요.** 안 들린 부분은 비워 두거나 (안 들림) 이라고 적으세요.
        3. **해석하지 마세요.** "그러니까 진행률이 60%네요" 같은 판단을 붙이지 마세요.
        4. 말한 언어 그대로 옮기세요(한국어면 한국어, 스페인어면 스페인어, 영어면 영어).
           번역하지 마세요.
        5. 현장 용어는 들리는 대로 적으세요. 아는 단어로 고치지 마세요
           (예: "그레이바" 를 "그레이 바" 로 바꾸지 마세요).
        6. 숫자는 들린 그대로 숫자로 적으세요("스무 개 중에 열두 개" → "20개 중에 12개").
        7. 말이 없거나 소음뿐이면 text 를 빈 문자열로 두세요. 지어내지 마세요.

        ## 반환 항목
        - text : 받아 적은 글자
        P;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ];
    }
}
