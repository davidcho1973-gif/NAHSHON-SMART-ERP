<?php

namespace App\Providers;

use App\Jobs\ReadOpsRoomMessageJob;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\ProjectContractDocument;
use App\Observers\EmployeePayrollProfileObserver;
use App\Observers\LinkedDocumentFilingObserver;
use App\Observers\MobileExpenseReceiptObserver;
use App\Services\Documents\IntegratedToIntelligentBridge;
use App\Services\Ocr\ClaudeOcrEngine;
use App\Services\Ocr\GeminiOcrEngine;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 공통 OCR 엔진 선택: 기본은 Gemini. Claude 는 AI_OCR_ENGINE=claude 로 명시할 때만 사용
        // (ANTHROPIC 크레딧 부족/미설정 시 자동으로 Claude 로 가서 실패하던 문제 방지).
        $this->app->bind(OcrEngine::class, function ($app): OcrEngine {
            $engine = strtolower(trim((string) config('services.ai_ocr.engine', 'gemini')));

            return $engine === 'claude'
                ? $app->make(ClaudeOcrEngine::class)
                : $app->make(GeminiOcrEngine::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Provision a payroll wage profile whenever a new employee is created.
        Employee::observe(EmployeePayrollProfileObserver::class);

        // 재무관리 영수증 등록 → 문서함 "자재·구매" 폴더 자동 편철.
        MobileExpense::observe(MobileExpenseReceiptObserver::class);

        // 조달(발주)·계약 첨부서류 → 문서통합관리 자동 편철.
        ProcurementItem::saved(fn (ProcurementItem $i) => app(LinkedDocumentFilingObserver::class)->procurementSaved($i));

        // 문서관리에 문서가 들어오면(직접 업로드·자동 편철 모두) AI 문서함에도 자동 인덱싱.
        // 같은 파일을 두 화면에 두 번 올리던 것을 없앤다 — 기한·위험 액션 큐가 자동으로 잡힌다.
        IntegratedDocument::created(function (IntegratedDocument $d): void {
            try {
                app(IntegratedToIntelligentBridge::class)->index($d);
            } catch (\Throwable $e) {
                report($e); // 인덱싱 실패가 원본 편철을 막으면 안 된다.
            }
        });
        ProjectContractDocument::saved(fn (ProjectContractDocument $d) => app(LinkedDocumentFilingObserver::class)->contractDocumentSaved($d));

        // 현장 상황실에 글이 올라오면 AI 가 자동 판독한다(응답 후 처리 — 글쓰기를 붙잡지 않는다).
        CommunicationMessage::created(function (CommunicationMessage $m): void {
            if (($m->payload['bot'] ?? null) !== null) {
                return; // AI 자신의 답글은 다시 읽지 않는다.
            }
            $room = $m->relationLoaded('room') ? $m->room : CommunicationRoom::find($m->communication_room_id);
            if ($room?->type !== CommunicationRoom::TYPE_SITE_OPS) {
                return;
            }
            ReadOpsRoomMessageJob::dispatch($m->id)->afterResponse();
        });
    }
}
