<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 훅이 없는 배포도 «지금 어느 커밋을 돌고 있는가» 가 매번 적히는가.
 *
 * ── 왜 이 시험이 있나 ──────────────────────────────────────────────────
 * 배포 훅이 걸린 환경은 verify-build.sh 가 «내가 시킨 배포가 올라왔는가» 를 확인하고,
 * 안 올라오면 잡이 빨개진다. 그런데 훅이 없는 환경은 대시보드의 «Push to deploy» 로만
 * 나가고, 그 경로는 CI 에 아무 기록을 남기지 않는다.
 *
 * 그래서 「거기 배포됐어?」 에 아무도 답할 수 없었다. 사람이 대시보드를 열어 보는 것
 * 말고는 길이 없었고, 실제로 한 환경이 이틀 동안 옛 커밋에 멈춰 있는 동안 그 위로
 * 커밋 열네 개가 쌓였는데 아무도 몰랐다(2026-09-05 기록). 화면이 열리기 때문에
 * 아무도 눈치채지 못하는, 가장 오래 가는 종류의 사고다.
 *
 * report-build.sh 가 그 자리를 메운다. 이 시험이 지키는 것은 두 가지다 —
 * <b>적히는가</b>, 그리고 <b>그것 때문에 잡이 빨개지지 않는가</b>.
 */
class DeployReportTest extends TestCase
{
    private const SCRIPT = 'scripts/deploy/report-build.sh';

    /**
     * @param  array<string, string>  $env
     * @return array{code: int, out: string, summary: string}
     */
    private function runScript(array $env): array
    {
        $summaryFile = tempnam(sys_get_temp_dir(), 'summary-');

        $process = new Process(
            ['bash', base_path(self::SCRIPT)],
            base_path(),
            array_merge([
                'GITHUB_SHA' => 'deadbeefcafe0000000000000000000000000000',
                'GITHUB_STEP_SUMMARY' => $summaryFile,
                // 시험이 30초씩 자게 두지 않는다 — 기다림은 이 스크립트의 논리가 아니다.
                'TRIES' => '1',
                'INTERVAL' => '0',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ], $env),
        );
        $process->setTimeout(60);
        $process->run();

        $summary = (string) file_get_contents($summaryFile);
        @unlink($summaryFile);

        return ['code' => $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput(), 'summary' => $summary];
    }

    /** 서버가 돌려줄 build-version 을 흉내 낸 자리 — curl 이 file:// 로 읽는다. */
    private function serving(string $json): string
    {
        $dir = sys_get_temp_dir().'/build-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/build-version', $json);

        return 'file://'.$dir;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(base_path(self::SCRIPT))) {
            $this->fail(self::SCRIPT.' 가 없습니다 — 훅 없는 배포를 확인할 길이 사라집니다.');
        }
    }

    public function test_it_says_so_when_the_server_already_runs_this_commit(): void
    {
        $result = $this->runScript([
            'BASE' => $this->serving('{"commit_short":"deadbee","env":"production"}'),
            'ENV_LABEL' => '시험환경',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('최신입니다', $result['summary']);
        $this->assertStringContainsString('deadbee', $result['summary']);
    }

    public function test_an_old_commit_is_named_next_to_the_new_one_and_the_job_stays_green(): void
    {
        // 이 경우가 이 스크립트의 존재 이유다. 서버는 멀쩡히 응답하는데 옛 커밋이다.
        $result = $this->runScript([
            'BASE' => $this->serving('{"commit_short":"0ldc0de","env":"production"}'),
            'ENV_LABEL' => '시험환경',
        ]);

        // 내가 일으키지 않은 배포가 늦다고 빨간 X 를 놓으면, 사람은 곧 그 X 를 무시하는
        // 법을 배운다 — 그러면 진짜 실패도 함께 묻힌다.
        $this->assertSame(0, $result['code'], '훅 없는 환경이 늦다고 잡을 실패시키면 안 됩니다');

        // 두 커밋이 나란히 찍혀야 «무엇이 안 갔는지» 를 사람이 바로 안다.
        $this->assertStringContainsString('0ldc0de', $result['summary'], '서버가 돌고 있는 커밋');
        $this->assertStringContainsString('deadbee', $result['summary'], '방금 올린 커밋');
        $this->assertStringContainsString('Deploy', $result['summary'], '무엇을 눌러야 하는지');
        $this->assertStringContainsString('::warning', $result['out'], '요약만이 아니라 경고로도 뜬다');
    }

    public function test_a_dead_address_is_reported_as_no_answer_not_as_an_old_commit(): void
    {
        // 「응답 없음」과 「옛 커밋」은 고치는 방법이 다르다. 뭉뚱그리면 엉뚱한 데를 본다.
        $result = $this->runScript([
            'BASE' => 'http://127.0.0.1:1',
            'ENV_LABEL' => '시험환경',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('응답 없음', $result['summary']);
        $this->assertStringNotContainsString('옛 커밋', $result['summary']);
    }

    public function test_no_address_skips_quietly_instead_of_failing(): void
    {
        $result = $this->runScript(['BASE' => '', 'ENV_LABEL' => '시험환경']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('건너뜀', $result['out']);
    }

    public function test_the_script_never_fails_the_build(): void
    {
        // 위의 시험들은 세 갈래를 하나씩 확인한다. 이건 «어느 갈래로도 실패하지 않는다» 를
        // 파일 자체에 대고 지킨다 — 나중에 누가 exit 1 을 하나 심으면 여기서 걸린다.
        $body = (string) file_get_contents(base_path(self::SCRIPT));

        $this->assertStringNotContainsString('exit 1', $body);
        $this->assertStringContainsString('GITHUB_STEP_SUMMARY', $body, '요약에 적어야 사람이 본다');
    }

    public function test_the_push_workflow_actually_calls_it(): void
    {
        // 스크립트가 있어도 아무도 부르지 않으면 없는 것과 같다. 예전에 배포가 엿새
        // 멈춰 있었던 것도 «준비만 되어 있고 켜져 있지 않아서» 였다.
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

        $this->assertStringContainsString(self::SCRIPT, $workflow, '푸시 워크플로가 이 스크립트를 부르지 않습니다');
        $this->assertStringContainsString('ENV_LABEL', $workflow, '어느 환경인지 이름이 붙어야 요약을 읽을 수 있습니다');
        // main 과 staging 둘 다에서 돈다 — 그 환경이 어느 쪽을 보는지 아직 확인되지 않았다.
        $this->assertStringContainsString("refs/heads/main' || github.ref == 'refs/heads/staging", $workflow);
    }
}
