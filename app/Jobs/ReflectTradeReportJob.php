<?php

namespace App\Jobs;

use App\Models\DailyTradeReport;
use App\Services\Ops\TradeReportReflector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 보고 제출 → ERP 반영을 "요청 응답 후"에 돌린다(->afterResponse()).
 *
 * 왜 비동기인가: 공정표 한 줄을 고치면 CPM 이 후속 공정을 전부 다시 계산한다. 제안이
 * 서너 건이면 그만큼 반복되고, 큰 공정표에서는 수십 초가 될 수 있다. 이걸 제출 요청
 * 안에서 돌리면 게이트웨이가 먼저 끊어 반장 화면에는 «제출 실패» 가 뜬다 — 실제로는
 * 제출도 반영도 됐는데. 응답을 먼저 보내면 반장은 곧바로 «제출 완료» 를 보고, 반영은
 * 걸리는 만큼 뒤에서 돈다.
 *
 * (별도 큐 워커가 필요 없다 — 같은 프로세스에서 응답 전송 뒤에 실행된다.)
 */
class ReflectTradeReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * CPM 재계산이 여러 번 도는 것을 감안한 여유.
     *
     * <b>주의:</b> 이 값은 큐 워커만 강제한다. 여기처럼 응답 뒤 같은 프로세스에서
     * 도는 경로(SyncQueue)에서는 아무도 읽지 않는다 — 실제 상한은 PHP-FPM 설정이다.
     * 프로세스가 끊기면 failed() 도 안 돌기 때문에, 못 끝낸 반영은 스케줄러의
     * 쓸이(ops:remind-trade-report)가 다시 건다.
     */
    public int $timeout = 900;

    /**
     * 재시도 없음.
     *
     * 반영은 멱등하다(이미 반영된 항목은 건너뛴다). 그래도 재시도를 켜지 않는 이유는,
     * 실패의 대부분이 재시도로 낫지 않는 종류(게이트에 걸림, 대상 없음)이고 그런 것은
     * «확인 대기» 로 남아 사람이 보는 편이 맞기 때문이다.
     */
    public int $tries = 1;

    /**
     * @param  int|null  $submittedAt  이 잡이 실려 나온 제출의 시각(유닉스 초).
     *                                 되돌린 뒤 다시 제출하면 잡이 둘 겹치는데,
     *                                 그때 낡은 잡이 새 제출분을 건드리면 안 된다.
     */
    public function __construct(public int $reportId, public ?int $submittedAt = null) {}

    public function handle(TradeReportReflector $reflector): void
    {
        $report = DailyTradeReport::find($this->reportId);
        if (! $report || ! $report->isSubmitted()) {
            return;   // 도는 사이에 소장이 되돌렸다 — 되돌린 보고를 반영하면 안 된다.
        }

        if ($this->submittedAt !== null && $report->submitted_at?->timestamp !== $this->submittedAt) {
            return;   // 내가 실려온 그 제출이 아니다(그 사이에 다시 제출됐다).
        }

        $reflector->reflect($report);
    }

    public function failed(\Throwable $e): void
    {
        // 반영이 실패해도 제출은 이미 끝났다. 다만 아무 흔적 없이 사라지면 반장은
        // 반영된 줄 알고 넘어간다 — 보고 한 장에 그 사실을 남긴다.
        Log::warning('보고 반영 실패(report '.$this->reportId.'): '.$e->getMessage());

        DailyTradeReport::where('id', $this->reportId)->update([
            'reflected_at' => now(),
            'reflection_note' => mb_substr('자동 반영에 실패했습니다 — 상황실에서 확인해 주세요. ('.$e->getMessage().')', 0, 300),
        ]);
    }
}
