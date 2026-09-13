<?php

namespace Tests\Feature;

use App\Support\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 큐와 메일이 배포할 때마다 숫자로 확인되는지 지킨다.
 *
 * 2026-09-06 나손: 문서 AI 분석 작업 85건이 몇 날 며칠 쌓여 있었는데 아무도 몰랐다.
 * 큐 일꾼은 코드가 아니라 배포 환경의 프로세스라, 안 만들면 문서가 「읽는 중」에서
 * 영원히 멈추면서도 화면·스케줄러·저장소는 전부 초록이었다.
 *
 * 메일은 더 고약하다 — 라라벨 기본 메일러가 log 라서, 설정이 틀려도 발송이
 * <b>예외 없이 성공</b>한다. 화면에는 "발송했습니다" 가 뜨고 로그 파일에만 쌓인다.
 * 진단은 이미 /build-version 에 있었는데 배포 로그에 찍히지 않아 아무도 안 봤다.
 */
class QueueAndMailVisibleAtDeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_queue_does_not_claim_a_worker_is_running(): void
    {
        // 비어 있을 때는 «일꾼이 있다» 와 «일꾼은 없는데 할 일도 없다» 를 구별할 수 없다.
        // 모르는 것을 안다고 말하면 진단이 거짓말이 된다.
        $snapshot = QueueHealth::snapshot();

        $this->assertTrue($snapshot['known']);
        $this->assertSame(0, $snapshot['pending']);
        $this->assertStringContainsString('알 수 없', $snapshot['message']);
    }

    public function test_work_waiting_past_the_threshold_is_called_out(): void
    {
        $this->queueJob('documents', minutesAgo: QueueHealth::STALE_MINUTES + 5);

        $snapshot = QueueHealth::snapshot();

        $this->assertFalse($snapshot['working'], '오래 기다린 작업이 있으면 일꾼이 없다고 봐야 한다.');
        $this->assertSame(1, $snapshot['pending']);
        $this->assertGreaterThanOrEqual(QueueHealth::STALE_MINUTES, $snapshot['oldest_pending_minutes']);
        $this->assertStringContainsString('queue:work', $snapshot['message']);
    }

    public function test_work_that_just_arrived_is_not_an_alarm(): void
    {
        // 방금 들어온 작업까지 경고하면 매 배포가 빨개지고, 사람은 곧 경고를 무시한다.
        $this->queueJob('documents', minutesAgo: 1);

        $snapshot = QueueHealth::snapshot();

        $this->assertTrue($snapshot['working']);
        $this->assertStringContainsString('처리 중', $snapshot['message']);
    }

    public function test_it_says_which_queue_the_work_is_piling_up_in(): void
    {
        // 「documents 만 쌓였다」와 「전부 쌓였다」는 고칠 자리가 다르다.
        $this->queueJob('documents', minutesAgo: 60);
        $this->queueJob('documents', minutesAgo: 30);
        $this->queueJob('default', minutesAgo: 20);

        $snapshot = QueueHealth::snapshot();

        $this->assertSame(['default' => 1, 'documents' => 2], collect($snapshot['by_queue'])->sortKeys()->all());
    }

    public function test_the_deploy_check_reads_the_queue_and_mail(): void
    {
        $body = $this->get('/build-version')->assertOk()->json();

        $this->assertArrayHasKey('queue', $body);
        foreach (['known', 'working', 'pending', 'failed', 'oldest_pending_minutes', 'message'] as $key) {
            $this->assertArrayHasKey($key, $body['queue']);
        }

        $this->assertArrayHasKey('mail', $body);
        foreach (['ready', 'scheme_ok', 'mailer', 'scheme', 'daily_report_recipients'] as $key) {
            $this->assertArrayHasKey($key, $body['mail']);
        }
    }

    public function test_the_script_reads_them_by_path_not_by_name(): void
    {
        // 이름만으로 찾으면 JSON 앞쪽의 같은 이름을 집는다 — "pending" 은 마이그레이션
        // 블록에도 있어서, 그대로 두면 밀린 작업 85건 대신 마이그레이션 수를 읽는다.
        $script = (string) file_get_contents(base_path('scripts/deploy/check-scheduler.sh'));

        $this->assertStringContainsString('json queue.pending', $script);
        $this->assertStringContainsString('json queue.working', $script);
        $this->assertStringContainsString('json mail.ready', $script);
        $this->assertStringContainsString('json mail.scheme_ok', $script);

        $this->assertStringNotContainsString('"pending" *: *\\([0-9]*\\)', $script, '이름만 보고 집으면 앞쪽 블록을 읽는다.');
    }

    public function test_the_deploy_log_line_carries_both(): void
    {
        // 요약은 접혀 있을 수 있다. 한 줄 로그에 남아야 나중에 되짚을 수 있다.
        $script = (string) file_get_contents(base_path('scripts/deploy/check-scheduler.sh'));

        foreach (['queue_working=', 'queue_pending=', 'queue_oldest_min=', 'queue_failed=', 'mail_ready=', 'mail_recipients='] as $field) {
            $this->assertStringContainsString($field, $script);
        }
    }

    public function test_the_script_warns_loudly_enough_to_act_on(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy/check-scheduler.sh'));

        // 경고는 «무엇이 멈췄고 무엇을 눌러야 하는가» 까지 말해야 한다.
        $this->assertStringContainsString('큐 일꾼이 멈춤', $script);
        $this->assertStringContainsString('Scale to Zero', $script);
        $this->assertStringContainsString('MAIL_SCHEME', $script);
        // 설정은 맞는데 받는 사람이 0명이면 발송은 «성공» 하고 아무 데도 안 간다.
        $this->assertStringContainsString('받는 사람이 없음', $script);
    }

    public function test_a_failed_read_does_not_turn_the_deploy_red(): void
    {
        // 이 스크립트의 첫 줄에 적힌 약속은 «배포를 실패시키지 않는다» 이다.
        // 그런데 set -e 아래에서 값 읽기가 실패하면 그 약속을 진단 도구가 스스로 깬다 —
        // 경고만 하기로 한 단계가 배포를 빨갛게 만들고, 사람은 곧 그 빨간 X 를 무시한다.
        $script = (string) file_get_contents(base_path('scripts/deploy/check-scheduler.sh'));

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertMatchesRegularExpression(
            '/\}\s*"\$1"\s*2>\/dev\/null\s*\|\|\s*true|2>\/dev\/null \|\| true/',
            $script,
            '값을 못 읽어도 «값 없음» 으로 끝나야 한다.',
        );
    }

    private function queueJob(string $queue, int $minutesAgo): void
    {
        $at = time() - ($minutesAgo * 60);

        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $at,
            'created_at' => $at,
        ]);
    }
}
