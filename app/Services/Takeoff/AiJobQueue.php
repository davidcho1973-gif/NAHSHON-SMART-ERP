<?php

namespace App\Services\Takeoff;

use App\Jobs\RunAiJob;
use App\Models\AiJob;
use App\Support\CurrentCompany;

/**
 * AI 작업 접수 창구 — 받아서 번호표를 주고 즉시 돌려보낸다.
 *
 * 화면은 이 번호표(jobId)로 진행 상태를 물어본다. 오래 걸리는 일을 요청 안에서
 * 붙잡고 있으면 게이트웨이가 끊어 504 가 되고, 사용자는 무엇이 잘못됐는지 알 수
 * 없다 — 실제로 그 사고가 났다.
 */
class AiJobQueue
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function push(string $kind, string $subjectType, int $subjectId, string $label, array $params = [], int $reuseMinutes = 30): array
    {
        // 같은 대상에 같은 작업이 이미 돌고 있으면 그 번호표를 준다 — 두 번 누른다고
        // AI 를 두 번 부르면 값이 두 배로 나간다.
        $running = AiJob::query()
            ->where('kind', $kind)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($running) {
            return ['success' => true, 'jobId' => $running->id, 'status' => $running->status,
                'label' => $running->label, 'reused' => true];
        }

        // 조금 전에 끝난 같은 작업이 있으면 그 결과를 그대로 준다.
        //
        // 결과 창을 닫으면 화면에서는 사라지지만 답은 서버에 남아 있다. 다시 눌렀을 때
        // AI 를 또 부르면 같은 답을 사고 오는 셈이고(값이 두 배), 사용자는 아까 본 것을
        // 다시 기다린다. 그래서 최근 것은 꺼내 준다.
        if ($reuseMinutes > 0) {
            $recent = AiJob::query()
                ->where('kind', $kind)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('status', 'done')
                ->where('finished_at', '>=', now()->subMinutes($reuseMinutes))
                ->latest('id')
                ->first();

            if ($recent) {
                return $recent->toStatusArray() + ['reused' => true];
            }
        }

        $job = AiJob::create([
            'user_id' => auth()->id(),
            'company_id' => CurrentCompany::id(),
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'params' => $params ?: null,
            'label' => mb_substr($label, 0, 200),
            'status' => 'queued',
        ]);

        // afterResponse — 사용자에게 번호표를 먼저 건네고, 그 뒤에 일을 시작한다.
        RunAiJob::dispatch($job->id)->afterResponse();

        return ['success' => true, 'jobId' => $job->id, 'status' => 'queued', 'label' => $job->label];
    }

    /** 번호표로 진행 상태를 묻는다. 남의 작업은 보여 주지 않는다. */
    public static function status(int $jobId): array
    {
        $job = AiJob::find($jobId);
        if (! $job) {
            return ['success' => false, 'error' => '작업을 찾을 수 없습니다.'];
        }

        $user = auth()->user();
        $mine = $job->user_id === null || $job->user_id === $user?->id;
        $admin = in_array($user?->access_role, ['super_admin', 'admin'], true);
        if (! $mine && ! $admin) {
            return ['success' => false, 'error' => '이 작업을 볼 권한이 없습니다.'];
        }

        return $job->toStatusArray();
    }
}
