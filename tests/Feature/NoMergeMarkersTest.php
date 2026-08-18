<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 병합 충돌 표시가 커밋되지 않았는가.
 *
 * PHP 파일에 남으면 문법 오류라 바로 드러난다. 문제는 그렇지 않은 파일들이다 —
 * 마크다운·JSON·설정 파일에 남은 `<<<<<<< HEAD` 은 아무것도 깨뜨리지 않고,
 * 그래서 아무도 모른 채 몇 달을 간다. 실제로 WORK_LOG.md 에 두 군데가 석 달 가까이
 * 남아 있었다.
 *
 * 표시가 남았다는 것은 병합을 반쯤 하다 만 것이므로, 그 파일의 내용도 반쪽일 수 있다.
 * 표시 자체보다 그쪽이 더 위험하다.
 */
class NoMergeMarkersTest extends TestCase
{
    /**
     * 줄 맨 앞에 이 모양으로 나오면 병합 표시다. 본문에서 인용할 수 있으므로
     * 줄 시작에 붙은 것만 본다.
     *
     * @var array<int, string>
     */
    private const MARKERS = ['<<<<<<< ', '>>>>>>> ', '||||||| '];

    public function test_no_file_still_carries_a_merge_conflict_marker(): void
    {
        $hits = [];

        foreach ($this->trackedTextFiles() as $path) {
            $body = (string) file_get_contents(base_path($path));

            foreach (explode("\n", $body) as $no => $line) {
                foreach (self::MARKERS as $marker) {
                    if (str_starts_with($line, $marker)) {
                        $hits[] = $path.':'.($no + 1).'  '.mb_substr($line, 0, 40);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $hits,
            "병합 충돌 표시가 남아 있습니다. 그 파일의 내용도 반쪽일 수 있습니다:\n  "
            .implode("\n  ", $hits));
    }

    /**
     * git 이 알고 있는 파일만 본다 — vendor·node_modules·빌드 산출물을 뒤지면
     * 느려지고, 우리가 고칠 수 없는 남의 코드까지 걸린다.
     *
     * @return array<int, string>
     */
    private function trackedTextFiles(): array
    {
        exec('git -C '.escapeshellarg(base_path()).' ls-files 2>/dev/null', $files, $status);

        if ($status !== 0 || $files === []) {
            $this->markTestSkipped('git 저장소가 아니라 추적 파일 목록을 얻을 수 없습니다.');
        }

        $text = ['php', 'js', 'md', 'json', 'yml', 'yaml', 'css', 'blade', 'xml', 'sh', 'txt'];

        return array_values(array_filter($files, function (string $path) use ($text): bool {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            return in_array($ext, $text, true) && is_file(base_path($path));
        }));
    }
}
