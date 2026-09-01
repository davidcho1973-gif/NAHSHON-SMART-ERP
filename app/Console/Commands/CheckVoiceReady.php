<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 음성 보고가 실제로 켜져 있는가 — 키를 화면에 내보이지 않고 확인한다.
 *
 * "키는 이미 있다" 와 "음성이 실제로 된다" 는 다른 말이다. 키가 있어도 그 키로
 * 쓸 수 있는 모델이 없거나(구글이 모델명을 자주 갈아치운다), 이름이 잘못 잡혀
 * 있으면 반장 폰에서는 그냥 «옮기지 못했습니다» 만 뜬다. 그때 어디가 막혔는지
 * 알 방법이 없으면 사람이 폰을 탓하게 된다.
 *
 * 키 값 자체는 절대 찍지 않는다 — 길이만 보여 준다.
 */
class CheckVoiceReady extends Command
{
    protected $signature = 'ops:voice-check';

    protected $description = '음성 보고(말로 하는 보고)가 쓸 수 있는 상태인지 확인한다';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>음성 보고 점검</>');
        $this->newLine();

        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            $this->line('  ❌ AI 키가 없습니다.');
            $this->line('     대시보드 환경변수에 <options=bold>GEMINI_API_KEY</> 를 넣어 주세요.');
            $this->line('     (키 값은 여기에도, 소스에도 남기지 마세요.)');
            $this->newLine();
            $this->line('     지금 상태: 반장 화면에 「음성 인식이 아직 켜져 있지 않습니다. 글로 적어 주세요」 가 뜹니다.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line(sprintf('  ✅ AI 키 있음 (%d자)', strlen($key)));

        // 이 키로 실제 쓸 수 있는 모델을 <b>직접</b> 물어본다.
        //
        // 모델 결정기(GeminiModelResolver)를 거치지 않는 이유가 있다. 그쪽은 목록
        // 조회가 실패해도 조용히 «알려진 이름» 정적 목록으로 넘어간다 — 판독을 계속
        // 굴리려는 설계라 그 자체는 옳지만, 점검하는 자리에서 그걸 쓰면 키가 틀렸을
        // 때도 «✅ 쓸 수 있는 모델» 이라고 답하게 된다. 점검이 거짓말을 하면 점검이
        // 아니다. 그리고 그쪽은 한 시간 캐시라, 방금 고친 키가 반영되지도 않는다.
        $wanted = (string) config('services.gemini.voice_model', 'gemini-2.5-flash');

        [$ok, $models, $why] = $this->askGoogle($key);

        if (! $ok) {
            $this->line('  ❌ 이 키로 구글에 물어보지 못했습니다 — '.$why);
            $this->line('     키가 틀렸거나 만료됐거나, Generative Language API 권한이 없습니다.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($models === []) {
            $this->line('  ❌ 이 키로 쓸 수 있는 모델이 하나도 없습니다.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line(sprintf('  ✅ 이 키로 쓸 수 있는 모델 %d개 확인', count($models)));

        // 모델 판정도 방금 받아 온 목록으로 직접 한다.
        //
        // 모델 결정기를 부르면 <b>같은 조회를 한 번 더</b> 하게 된다(새 컨테이너에는
        // 캐시가 비어 있다). 점검 하나에 바깥 호출이 둘이면 그만큼 멈춰 설 자리가
        // 늘어난다 — 점검은 빨리 끝나고 답을 주는 것이 일이다.
        if (in_array($wanted, $models, true)) {
            $this->line(sprintf('  ✅ 음성용 모델 <options=bold>%s</> 을(를) 씁니다 (빠른 쪽).', $wanted));
        } else {
            $flash = array_values(array_filter($models, fn (string $m): bool => str_contains($m, 'flash')));
            $this->line(sprintf('  ⚠️  바라던 음성용 모델(%s)이 이 키에는 없습니다.', $wanted));
            $this->line($flash !== []
                ? '     대신 쓸 만한 빠른 모델: '.implode(', ', array_slice($flash, 0, 3))
                    .' — GEMINI_VOICE_MODEL 을 이 중 하나로 바꾸세요.'
                : '     빠른(flash) 모델이 없어 느릴 수 있습니다. 그래도 음성은 됩니다.');
        }

        $this->newLine();
        $this->line('  <fg=green;options=bold>음성 보고를 쓸 수 있습니다.</> 폰에서 「🎤 눌러서 말하기」를 눌러 보세요.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * 구글에 «이 키로 쓸 수 있는 모델» 을 직접 묻는다.
     *
     * @return array{0: bool, 1: array<int, string>, 2: string} 성공 여부 · 모델 목록 · 실패 까닭
     */
    private function askGoogle(string $key): array
    {
        $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com'), '/')
            .'/v1beta/models';

        try {
            // 전체 제한과 <b>연결</b> 제한을 따로 건다. 연결 제한이 없으면 바깥으로
            // 나가는 길이 막힌 망에서 TCP 가 붙을 때까지 그냥 매달려 있는다 —
            // 화면에는 «진행 중» 만 계속 뜬다.
            $res = Http::connectTimeout(8)->timeout(20)
                ->withHeaders(['x-goog-api-key' => $key])
                ->get($endpoint, ['pageSize' => 1000]);
        } catch (\Throwable $e) {
            return [false, [], '연결 실패: '.$e->getMessage()];
        }

        if ($res->failed()) {
            // 본문에 키가 실려 돌아오는 일은 없지만, 그래도 상태 코드만 보여 준다.
            return [false, [], 'HTTP '.$res->status()];
        }

        $models = [];
        foreach ((array) $res->json('models', []) as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (is_array($methods) && in_array('generateContent', $methods, true)) {
                $models[] = str_replace('models/', '', (string) ($m['name'] ?? ''));
            }
        }

        return [true, array_values(array_filter($models)), ''];
    }
}
