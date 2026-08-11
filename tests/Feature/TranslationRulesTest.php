<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 번역 규칙은 브라우저 코드에 있다. 그래서 node 로 돌린다.
 *
 * 여기 PHP 테스트를 두는 이유는 하나다 — 별도 명령으로만 도는 검사는 아무도 돌리지
 * 않는다. php artisan test 안에 있어야 배포 전에 걸린다.
 *
 * 무엇을 지키는가: 한국어에는 낱말 사이에 띄어쓰기가 없어서, 사전에 짧은 말을 하나
 * 넣으면 그 말이 들어간 모든 긴 낱말이 함께 부서진다. "출퇴근 기록" 이 "출Check Out
 * 기록" 이 되는 식으로 화면 50군데가 그랬다.
 */
class TranslationRulesTest extends TestCase
{
    public function test_the_translation_rules_hold(): void
    {
        $script = base_path('tests/js/translate.test.cjs');
        $this->assertFileExists($script);

        $process = proc_open(
            ['node', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            $this->markTestSkipped('node 를 실행할 수 없습니다.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        array_map('fclose', $pipes);
        $code = proc_close($process);

        $this->assertSame(0, $code, "번역 규칙이 깨졌습니다.\n".$err.$out);
    }
}
