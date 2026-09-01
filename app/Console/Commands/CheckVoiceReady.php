<?php

namespace App\Console\Commands;

use App\Support\GeminiModelResolver;
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

    public function handle(GeminiModelResolver $resolver): int
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

        config(['services.gemini.model' => $wanted]);
        $candidates = $resolver->candidates();

        if ($candidates[0] === $wanted) {
            $this->line(sprintf('  ✅ 음성용 모델 <options=bold>%s</> 을(를) 씁니다 (빠른 쪽).', $wanted));
        } else {
            $this->line(sprintf('  ⚠️  바라던 음성용 모델(%s)이 없어 <options=bold>%s</> 로 갑니다.', $wanted, $candidates[0]));
            $this->line('     느릴 수 있습니다. 필요하면 GEMINI_VOICE_MODEL 을 위 목록의 flash 모델로 바꾸세요.');
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
            $res = Http::timeout(20)
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
