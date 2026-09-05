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
    }

    /**
     * 배포 대상마다 훅과 주소가 짝을 이루는가.
     *
     * 짝이 어긋나면 «배포 성공» 이라고 뜨는데 확인은 엉뚱한 서버를 본다 — 배포가 실제로
     * 됐는지 아무도 모르는 상태가 된다. 이 저장소는 시크릿 이름이 환경 이름과 어긋나
     * 있어서(문서의 표를 볼 것) 더 쉽게 어긋난다.
     *
     * 이름을 여기 적지 않고 <b>짝이 맞는가</b>만 본다 — 시험 파일에 고객사 이름을 적으면
     * 그것대로 다른 시험(OrgIdentityTest)에 걸린다.
     */
    public function test_every_deploy_target_has_its_own_hook_and_its_own_address(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

        preg_match_all('/LARAVEL_CLOUD_DEPLOY_HOOK_([A-Z]+)/', $workflow, $hooks);
        preg_match_all('/vars\.([A-Z]+)_URL/', $workflow, $urls);

        $hookNames = array_values(array_unique($hooks[1]));
        $urlNames = array_values(array_unique($urls[1]));
        sort($hookNames);
        sort($urlNames);

        $this->assertCount(3, $hookNames, '배포 대상은 셋입니다');
        $this->assertSame($hookNames, $urlNames, '훅과 주소의 짝이 어긋나 있습니다 — 배포와 확인이 다른 서버를 봅니다');
    }

    public function test_the_target_we_work_on_deploys_from_one_branch_only(): void
    {
        // David 지시(2026-09-05): 매일 쓰는 그 환경이 배포 1순위다. 훅이 들어오면서
        // «적기만 하던» 잡이 «배포하고 확인하는» 잡이 됐다.
        //
        // 그 잡은 한 브랜치에서만 돌아야 한다 — 배포 절차가 staging 과 main 에 같은 커밋을
        // 올리므로, 양쪽에서 돌리면 한 커밋을 두 번 배포하고 서버를 공연히 두 번 재시작한다.
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

        // 훅이 없을 때만 쓰는 «적기» 갈래를 가진 잡 = 이번에 옮겨 온 그 잡.
        $pos = strpos($workflow, self::SCRIPT);
        $this->assertNotFalse($pos, '적기 갈래가 사라졌습니다');

        $job = substr($workflow, 0, $pos);
        $job = substr($job, (int) strrpos($job, "\n  deploy-"));

        $this->assertStringContainsString("github.ref == 'refs/heads/main' &&", $job, '한 브랜치에서만 돌아야 합니다');
        $this->assertStringNotContainsString("refs/heads/staging", $job, '두 브랜치에서 돌면 같은 커밋을 두 번 배포합니다');
        $this->assertStringContainsString('trigger-hook.sh', $job, '이제는 배포까지 겁니다');
        $this->assertStringContainsString('verify-build.sh', $job, '서버가 실제로 바뀌었는지 확인해야 합니다');
    }

    /**
     * @param  array<string, string>  $env
     * @return array{code: int, out: string}
     */
    private function diagnose(array $env): array
    {
        $process = new Process(
            ['bash', base_path('scripts/deploy/diagnose-hook.sh')],
            base_path(),
            $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $process->setTimeout(30);
        $process->run();

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
    }

    public function test_a_short_token_is_proven_by_comparison_not_guessed(): void
    {
        // 「토큰이 짧으면 잘린 것」 이라고만 적어 두면 얼마가 짧은지 아무도 모른다.
        // 2026-09-05 에 실제로 그랬다 — 훅이 튕기는데 그 길이가 원래 그런 것인지
        // 잘린 것인지 가릴 근거가 없었다. 잘 되는 훅을 옆에 놓으면 비교가 된다.
        $base = 'https://cloud.laravel.com/deploy/123e4567-e89b-12d3-a456-426614174000/';

        $short = $this->diagnose([
            'HOOK' => $base.str_repeat('a', 16),
            'REFERENCE_HOOK' => $base.str_repeat('b', 32),
            'REFERENCE_LABEL' => '잘 되는 훅',
        ]);

        $this->assertSame(0, $short['code']);
        $this->assertStringContainsString('이 환경 16자', $short['out']);
        $this->assertStringContainsString('잘 되는 훅 32자', $short['out']);
        $this->assertStringContainsString('더 짧습니다', $short['out']);
        $this->assertStringContainsString('복사 버튼', $short['out'], '무엇을 해야 하는지 말해야 합니다');
    }

    public function test_a_full_length_token_points_somewhere_else(): void
    {
        // 길이가 같은데도 튕기면 잘린 것이 아니다. 그때 «복사 버튼을 쓰세요» 라고 하면
        // 사람이 같은 일을 반복하고 원인은 그대로 남는다.
        $base = 'https://cloud.laravel.com/deploy/123e4567-e89b-12d3-a456-426614174000/';

        $same = $this->diagnose([
            'HOOK' => $base.str_repeat('a', 32),
            'REFERENCE_HOOK' => $base.str_repeat('b', 32),
        ]);

        $this->assertStringContainsString('잘려서 생긴 문제가 아닙니다', $same['out']);
        $this->assertStringContainsString('재발급', $same['out']);
    }

    public function test_the_diagnosis_never_prints_either_hook_value(): void
    {
        // 이 진단은 시크릿 둘을 손에 쥔다. 하나라도 로그에 찍히면 공개 저장소의
        // 실행 기록에 배포 열쇠가 남는다 — 진단이 사고가 된다.
        $mine = 'MINESECRETVALUE0000000000000001';
        $theirs = 'THEIRSSECRETVALUE00000000000002';
        $base = 'https://cloud.laravel.com/deploy/123e4567-e89b-12d3-a456-426614174000/';

        $out = $this->diagnose(['HOOK' => $base.$mine, 'REFERENCE_HOOK' => $base.$theirs])['out'];

        $this->assertStringNotContainsString($mine, $out);
        $this->assertStringNotContainsString($theirs, $out);
        // 그래도 쓸모는 있어야 한다 — 길이는 나온다.
        $this->assertStringContainsString('토큰 길이:', $out);
    }

    public function test_a_missing_hook_secret_is_told_apart_from_a_failing_one(): void
    {
        // 시크릿 이름을 잘못 넣으면 값이 조용히 비어 온다. «훅이 실패했다» 와 구별되지
        // 않으면, 이름 오타를 찾는 데 한나절이 든다(2026-08-08 에 실제로 그랬다).
        $body = (string) file_get_contents(base_path('scripts/deploy/trigger-hook.sh'));

        $this->assertStringContainsString('has_hook=false', $body);
        $this->assertStringContainsString('has_hook=true', $body);
        $this->assertStringContainsString('HOOK_NAME', $body, '어느 이름을 찾고 있었는지 말해 줘야 오타를 찾습니다');

        // 그리고 그 구별이 실제로 갈래를 가르는 데 쓰여야 한다 — 훅이 없으면 적기만,
        // 있으면 다른 둘과 같은 잣대로 판정한다.
        $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
        $this->assertStringContainsString("steps.hook.outputs.has_hook == 'true'", $workflow);
        $this->assertStringContainsString("steps.hook.outputs.has_hook != 'true'", $workflow);
    }
}
