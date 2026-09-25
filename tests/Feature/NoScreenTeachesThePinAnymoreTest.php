<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 없앤 기능을 화면이 계속 가르치면, 사람은 화면 말을 믿고 없는 단추를 찾는다.
 *
 * ── 겪은 일 (2026-09-24) ──────────────────────────────────────────────
 * PIN 을 걷어낸 날(2026-09-23) 코드는 다 지웠는데 <b>말</b>은 여섯 군데 남았다.
 * 벽에 붙이는 QR 포스터는 3개 언어로 "개인 PIN 을 설정하세요" 라고 적혀 있었고,
 * 작업자 등록 안내·반장 화면·관리자 도움말도 그대로였다. 코드를 지울 때 문구는
 * 검색에 걸리지 않는다 — 문구는 쓰는 곳이 없어도 조용히 남는다.
 *
 * 종이는 한 번 붙으면 몇 달을 간다. 그래서 «가르치는 말» 만 골라 막는다.
 * 「PIN 이 없습니다」 처럼 <b>없다고 알리는</b> 말은 사실이므로 그대로 둔다.
 */
class NoScreenTeachesThePinAnymoreTest extends TestCase
{
    /** 사람에게 시키는 말만 고른다. 단어 'PIN' 자체는 금지어가 아니다. */
    private const TEACHING_PHRASES = [
        'PIN 설정', 'PIN 초대', 'PIN을 설정', 'PIN 을 설정', 'PIN 만들기', 'PIN 재설정',
        'set a PIN', 'Create a PIN', 'create a PIN', 'enter your PIN',
        'cree su PIN', 'Cree un PIN', 'configure su PIN',
    ];

    /** @return array<string, array{string}> */
    public static function screenFolders(): array
    {
        return [
            'views' => ['resources/views'],
            'scripts' => ['public/js'],
            'poster copy' => ['app/Support/WorkerLang.php'],
        ];
    }

    #[DataProvider('screenFolders')]
    public function test_no_screen_asks_anyone_to_set_a_pin(string $path): void
    {
        $full = base_path($path);
        $files = is_file($full) ? [$full] : iterator_to_array(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)),
        );

        $offences = [];

        foreach ($files as $file) {
            $file = (string) $file;

            if (is_dir($file) || ! preg_match('/\.(php|js)$/', $file)) {
                continue;
            }

            $text = (string) file_get_contents($file);

            foreach (self::TEACHING_PHRASES as $phrase) {
                if (str_contains($text, $phrase)) {
                    $offences[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' → "'.$phrase.'"';
                }
            }
        }

        $this->assertSame([], $offences, "PIN 은 2026-09-23 에 없어졌습니다. 화면이 아직 시키고 있습니다:\n".implode("\n", $offences));
    }
}
