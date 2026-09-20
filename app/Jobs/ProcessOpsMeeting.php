<?php

namespace App\Jobs;

use App\Models\OpsMeeting;
use App\Services\Ops\MeetingAccess;
use App\Services\Ops\MeetingAnalyzer;
use App\Services\Ops\MeetingContext;
use App\Services\Ops\MeetingTranscriber;
use App\Services\Ops\MeetingWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

class ProcessOpsMeeting implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [60, 180];
    }

    public function __construct(public int $meetingId)
    {
        $this->onConnection('meeting-analysis')->onQueue('meetings');
    }

    public function handle(MeetingTranscriber $transcriber, MeetingAnalyzer $analyzer, MeetingContext $context, MeetingWorkflow $workflow): void
    {
        $m = OpsMeeting::findOrFail($this->meetingId);
        if (in_array($m->status, ['review', 'completed', 'uploading'], true)) {
            return;
        }
        if (! OpsMeeting::whereKey($m->id)->where(function ($q) {
            $q->whereIn('status', ['queued', 'failed'])->orWhere(fn ($stale) => $stale->whereIn('status', ['transcribing', 'analyzing'])->where('started_at', '<', now()->subMinutes(26)));
        })->update(['status' => 'transcribing', 'started_at' => now(), 'error' => null, 'attempts' => $m->attempts + 1])) {
            return;
        }
        try {
            MeetingAccess::check($m->creator, $m->site_id);
            if ($m->attempts >= 5) {
                throw new RuntimeException('재시도 한도에 도달했습니다. API 설정과 녹음 파일을 점검해 주세요.');
            }
            Auth::setUser($m->creator);
            if ($m->ops_intake_batch_id) {
                $workflow->restamp($m);

                return;
            }
            $ctx = $context->get($m->site_id);
            $out = $transcriber->transcribe($m, $context->vocabulary($ctx, (string) $m->participants));
            $m->update(['transcripts' => $out]);
            if (collect(['gemini', 'scribe'])->contains(fn ($p) => ($out[$p]['status'] ?? '') !== 'done')) {
                throw new RuntimeException('두 음성인식이 모두 완료되지 않았습니다. 성공한 결과는 보존되며 실패한 쪽만 재시도합니다.');
            }
            $m->update(['status' => 'analyzing']);
            $analysis = $analyzer->analyze($m, $ctx);
            // Permission may have changed while waiting for the remote APIs.
            MeetingAccess::check($m->creator->fresh(), $m->site_id);
            $workflow->persist($m, $analysis, $ctx);
            try {
                $workflow->notify($m);
            } catch (Throwable $notifyError) {
                report($notifyError);
            }
        } catch (Throwable $e) {
            $m->update(['status' => 'failed', 'error' => mb_substr(get_class($e) === RuntimeException::class ? $e->getMessage() : '회의 분석·반영에 실패했습니다. 기록을 보존했습니다. 관리자에게 문의해 주세요.', 0, 1000)]);
            throw $e;
        } finally {
            Auth::forgetGuards();
        }
    }

    public function failed(?Throwable $e): void
    {
        OpsMeeting::whereKey($this->meetingId)->whereNotIn('status', ['review', 'completed'])->update([
            'status' => 'failed', 'error' => '처리를 완료하지 못했습니다. 키·모델 권한·작업 프로세스를 확인 후 재시도해 주세요. 성공한 전사 결과는 보존됩니다.',
        ]);
        if ($m = OpsMeeting::find($this->meetingId)) {
            try {
                app(MeetingWorkflow::class)->notify($m);
            } catch (Throwable $notifyError) {
                report($notifyError);
            }
        }
    }
}
