<?php

namespace App\Services\Ops;

use App\Services\Ocr\GeminiOcrEngine;
use Illuminate\Support\Facades\Log;

/**
 * 말한 것을 <b>보고 문장</b>으로 바꾼다 — 반장이 말만 하면 보고서가 되게.
 *
 * ── 두 벌을 돌려준다 ────────────────────────────────────────────────────
 * 사람이 현장에서 말하는 방식은 보고서가 아니다.
 *
 *   "어… 오늘 그 3층에 천장 배관 있잖아요, 그거 스무 개 중에 한 열두 개 정도 했고요,
 *    아 그리고 그레이바에서 자재 화요일에 온다고 연락 왔어요. 어… 그리고 안전고리
 *    안 한 사람 하나 있어서 지적했습니다."
 *
 * 이걸 그대로 두면 판독이 흔들리고, 사람이 읽기도 어렵다. 그래서 <b>들은 그대로</b>
 * 한 벌과 <b>정리한 보고 문장</b> 한 벌을 함께 만든다.
 *
 *   heard  "어… 오늘 그 3층에 천장 배관 있잖아요…"      (말한 그대로. 근거로 남는다)
 *   text   "3층 천장 배관 20개 중 12개 완료.
 *           그레이바 자재 화요일 도착 예정.
 *           안전고리 미착용 1건 지적."                    (보고 문장. 이걸 보낸다)
 *
 * ── 정리는 하되, 없는 말은 만들지 않는다 ────────────────────────────────
 * 이 경계가 이 클래스의 전부다. 군더더기를 걷어내고 주제별로 줄을 나누는 것까지가
 * 정리이고, 숫자를 반올림하거나 «아마 60% 쯤» 을 «60%» 로 확정하는 것은 지어내기다.
 * 사진만 보고 보고를 지어내던 잘못을 다른 문으로 되풀이하면 안 된다.
 *
 * ── 그리고 반드시 사람에게 되보여 준다 ──────────────────────────────────
 * 현장은 시끄럽다. 잘못 들린 한 단어가 그대로 공정표 숫자가 되면 안 되므로, 정리한
 * 글은 화면에 띄워 반장이 고칠 수 있게 한 뒤에 보낸다. 이 클래스는 글자만 돌려주고,
 * 보내는 것은 사람이 정한다.
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

        $heard = trim((string) ($result['data']['heard'] ?? ''));
        $text = trim((string) ($result['data']['report'] ?? ''));

        // 정리에 실패했으면 들은 것이라도 준다 — 반장이 말한 사실이 사라지는 것이
        // 제일 나쁘다. 어느 쪽도 없으면 그때만 «못 알아들었다» 고 한다.
        $text = $text !== '' ? $text : $heard;

        if ($text === '') {
            return ['success' => false, 'error' => '소리가 잘 안 들렸습니다. 조용한 곳에서 다시 말씀해 주세요.'];
        }

        return ['success' => true, 'text' => $text, 'heard' => $heard];
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
        당신은 미국 내 한국 기업 건설현장의 공사과장입니다. 반장이 휴대폰에 대고 말한
        녹음을 듣고, 두 가지를 만드세요. JSON 만 반환합니다.

        ## 1) heard — 들은 그대로
        - 말한 문장을 **그대로** 옮깁니다. 요약·해석 금지.
        - 말한 언어 그대로(한국어면 한국어, 스페인어면 스페인어). 번역하지 마세요.
        - 안 들린 부분은 (안 들림) 이라고 적으세요.
        - 말이 없거나 소음뿐이면 빈 문자열로 두세요.

        ## 2) report — 보고 문장
        위 내용을 **현장 일일보고에 그대로 들어갈 문장**으로 다듬으세요.

        - 군더더기를 걷어냅니다: "어…", "그러니까", "있잖아요", "네네", 같은 말 되풀이.
        - **한 줄에 한 가지**씩, 줄바꿈으로 나눕니다. 순서는 아래를 따르세요.
            ① 작업 진행   ② 자재·조달   ③ 인원   ④ 이슈·안전   ⑤ 내일 계획   ⑥ 그 밖의 요청
        - 짧은 평서문으로. 문장 끝은 "완료", "예정", "지적" 처럼 명사로 끊어도 좋습니다.
        - 현장 용어·업체명은 들린 그대로 둡니다("그레이바" 를 "그레이 바" 로 고치지 마세요).
        - 말한 언어 그대로 씁니다. 번역하지 마세요.

        ### 다듬기의 경계 — 이것을 넘으면 안 됩니다
        - **없는 사실을 만들지 마세요.** 정리는 말을 옮겨 담는 일이지 채우는 일이 아닙니다.
        - **숫자를 손대지 마세요.** 반올림·환산·추정 금지. "스무 개 중에 열두 개" 는
          "20개 중 12개" 로 적되, 거기서 "60%" 를 계산해 붙이지 마세요.
        - **애매한 것은 애매한 채로.** "한 열두 개쯤" 은 "약 12개" 로 두세요. "12개" 로
          확정하지 마세요.
        - **판단을 붙이지 마세요.** "진행이 더딥니다", "문제없어 보입니다" 같은 평가 금지.
        - 말하지 않은 날짜·장소·업체를 추측해 넣지 마세요. 안 말했으면 안 적습니다.
        - 앞뒤가 안 맞거나 무슨 뜻인지 모르겠으면, 그 대목은 들은 대로 남기고 끝에
          (확인 필요) 를 붙이세요.

        ### 예
        들은 것: "어… 오늘 그 3층에 천장 배관 있잖아요, 그거 스무 개 중에 한 열두 개
        정도 했고요, 아 그리고 그레이바에서 자재 화요일에 온다고 연락 왔어요.
        어… 그리고 안전고리 안 한 사람 하나 있어서 지적했습니다."

        report:
        3층 천장 배관 20개 중 약 12개 완료
        그레이바 자재 화요일 도착 예정
        안전고리 미착용 1건 지적

        ## 반환 항목
        - heard  : 들은 그대로
        - report : 보고 문장 (줄바꿈으로 나눔)
        P;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'heard' => ['type' => 'string'],
                'report' => ['type' => 'string'],
            ],
            'required' => ['heard', 'report'],
        ];
    }
}
