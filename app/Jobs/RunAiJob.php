<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\IntelligentDocument;
use App\Models\Submittal;
use App\Services\Admin\ProjectRegisterService;
use App\Services\Takeoff\DrawingTakeoffService;
use App\Services\Takeoff\SpecSubmittalService;
use App\Services\Takeoff\SubmittalRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * 오래 걸리는 AI 작업을 요청 밖에서 돌린다.
 *
 * 왜 필요한가 — 도면·시방 판독은 수십 초에서 몇 분이 걸리는데, 웹 요청은 그만큼
 * 기다려 주지 않는다. 게이트웨이가 먼저 끊고 화면에는 504 만 남는다. 그래서
 * 접수는 즉시 끝내고 실제 일은 여기서 한다.
 *
 * tries=1 — AI 호출은 값이 나가는 일이라 조용한 재시도로 두 번 긁지 않는다.
 * 실패는 실패로 남기고 사람이 다시 누르게 한다.
 */
class RunAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $jobId) {}

    public function handle(): void
    {
        $job = AiJob::find($this->jobId);
        if (! $job || $job->status !== 'queued') {
            return;
        }

        // 권한 검사가 auth()->user() 를 보므로, 접수한 사람으로 돌린다.
        if ($job->user_id) {
            $user = \App\Models\User::find($job->user_id);
            if ($user) {
                Auth::setUser($user);
            }
        }

        $job->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $result = $this->run($job);
            $job->forceFill([
                'status' => ($result['success'] ?? false) ? 'done' : 'failed',
                'result' => $result,
                'error' => ($result['success'] ?? false) ? null : (string) ($result['error'] ?? '실패'),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $job->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();
        }
    }

    /** @return array<string, mixed> */
    private function run(AiJob $job): array
    {
        $params = (array) ($job->params ?? []);

        return match ($job->kind) {
            'takeoff' => app(DrawingTakeoffService::class)
                ->extract(IntelligentDocument::findOrFail($job->subject_id), $params['discipline'] ?? null),

            'spec_submittals' => app(SpecSubmittalService::class)
                ->extract(IntelligentDocument::findOrFail($job->subject_id)),

            'vendor_request' => app(SubmittalRequestService::class)
                ->build(Submittal::findOrFail($job->subject_id), $params['vendor'] ?? null),

            'research' => app(ProjectRegisterService::class)
                ->researchSubmittal((int) $job->subject_id),

            default => ['success' => false, 'error' => "알 수 없는 작업 종류: {$job->kind}"],
        };
    }

    public function failed(Throwable $e): void
    {
        AiJob::where('id', $this->jobId)->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);
    }
}
