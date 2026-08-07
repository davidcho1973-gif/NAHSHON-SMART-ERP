<?php

namespace App\Jobs;

use App\Models\WbsManual;
use App\Support\SmartCompanyData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 공정관리 AI 매뉴얼 분석을 "요청 응답 후"에 처리한다.
 *
 * 왜 비동기인가: CPM 전량 추출(80개 액티비티 × 다필드)은 AI 생성 출력이 커서 수십 초~수 분이
 * 걸린다. 이걸 HTTP 요청 안에서 동기로 돌리면 게이트웨이 타임아웃(504)에 걸린다(David 리포트).
 * 그래서 컨트롤러는 `->afterResponse()` 로 이 잡을 걸고 즉시 202 를 돌려주고, 실제 분석은
 * 응답 전송 후 같은 프로세스에서 진행한다(별도 큐 워커 불필요) — 프론트는 매뉴얼 상태를 폴링한다.
 *
 * 게이트웨이 제한이 사라졌으므로 AI 타임아웃을 넉넉히 올려 한 번의 생성이 잘리지 않게 한다.
 */
class AnalyzeWbsManualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 잡 자체의 최대 실행 시간(초) — AI 생성이 길어도 죽지 않게. */
    public int $timeout = 900;

    /** 재시도 없음 — 분석은 비멱등(중복 트리 생성 방지)이라 실패는 실패로 기록. */
    public int $tries = 1;

    public function __construct(
        public int $manualId,
        public string $projectCode,
        public string $siteScope,
    ) {}

    public function handle(): void
    {
        $manual = WbsManual::find($this->manualId);
        if (! $manual) {
            return;
        }

        // 게이트웨이 제한이 없는 백그라운드 경로 — AI 호출 타임아웃을 넉넉히(한 번에 다 뽑게).
        config([
            'services.gemini.timeout' => max(180, (int) config('services.gemini.timeout')),
            'services.anthropic.timeout' => max(240, (int) config('services.anthropic.timeout')),
        ]);

        try {
            $disk = $manual->disk ?: 'public';
            if (! Storage::disk($disk)->exists($manual->path)) {
                $manual->update(['status' => 'failed', 'error' => '업로드된 파일을 찾을 수 없습니다.']);

                return;
            }

            $bytes = (string) Storage::disk($disk)->get($manual->path);
            $pdf = [
                'data' => base64_encode($bytes),
                'media_type' => $manual->mime_type ?: 'application/pdf',
            ];

            $result = SmartCompanyData::analyzeWbsManual($this->projectCode, $this->siteScope, $pdf);

            // 아무것도 못 뽑았는데 success 만 true 로 오는 경우가 있다. 그대로 두면 공정 0개짜리
            // 매뉴얼이 "분석 완료" 로 보여서, 담당자가 WBS 가 만들어진 줄 알고 넘어간다.
            if (
                ! ($result['success'] ?? false)
                || (int) ($result['processed'] ?? 0) < 1
                || ! is_array($result['results'] ?? null)
                || $result['results'] === []
            ) {
                $manual->update(['status' => 'failed', 'error' => (string) ($result['error'] ?? 'AI 분석 실패')]);

                return;
            }

            $row = $result['results'][0] ?? [];
            $manual->update([
                'status' => 'completed',
                'engine' => (string) ($row['engine'] ?? null),
                'stages' => (int) ($row['stages'] ?? 0),
                'tasks' => (int) ($row['tasks'] ?? 0),
                'subtasks' => (int) ($row['subTasks'] ?? 0),
                'error' => null,
                'analyzed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $manual->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        WbsManual::where('id', $this->manualId)->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
