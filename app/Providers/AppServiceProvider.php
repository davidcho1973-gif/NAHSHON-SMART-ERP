<?php

namespace App\Providers;

use App\Jobs\AnswerChatQuestionJob;
use App\Jobs\ReadOpsRoomMessageJob;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\ProjectContractDocument;
use App\Observers\EmployeeOffboardingObserver;
use App\Observers\EmployeePayrollProfileObserver;
use App\Observers\LinkedDocumentFilingObserver;
use App\Observers\MobileExpenseReceiptObserver;
use App\Services\Documents\IntegratedToIntelligentBridge;
use App\Services\Documents\IntelligentToIntegratedBridge;
use App\Services\Ocr\ClaudeOcrEngine;
use App\Services\Ocr\GeminiOcrEngine;
use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\OpenAiOcrEngine;
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

            return match ($engine) {
                'claude' => $app->make(ClaudeOcrEngine::class),
                'openai', 'gpt' => $app->make(OpenAiOcrEngine::class),
                default => $app->make(GeminiOcrEngine::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Provision a payroll wage profile whenever a new employee is created.
        Employee::observe(EmployeePayrollProfileObserver::class);

        // 퇴사·비활성 전환 시 계정·배지·기기·채팅방·푸시를 한 번에 닫는다(열쇠 회수).
        Employee::observe(EmployeeOffboardingObserver::class);

        // 신규 직원 → 회사방·팀방 자동 가입. 함수는 있었는데 부르는 곳이 0곳이라
        // 새 직원은 아무 방에도 없이 시작했다(연계 점검 ⑮).
        Employee::created(function (Employee $employee): void {
            try {
                $comm = app(\App\Services\Communication\CommunicationService::class);
                if ($employee->company) {
                    $comm->ensureRoomMember($comm->ensureCompanyRoom($employee->company), $employee);
                }
                if ($employee->team) {
                    $comm->ensureRoomMember($comm->ensureTeamRoom($employee->team), $employee);
                }
            } catch (\Throwable $e) {
                report($e); // 방 가입 실패가 직원 등록을 막으면 안 된다.
            }
        });

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

        // 반대 방향도 같다 — AI 문서함에 직접 올린 것은 문서관리에도 등록한다.
        //
        // 한쪽 방향만 있으면 쓰는 사람이 올릴 때마다 "어디에 올려야 하지" 를 판단해야
        // 하고, 틀리면 한쪽 화면에서 그 문서가 아예 안 보인다. 없는 것인지 다른 데
        // 있는 것인지 구별할 방법이 없다.
        IntelligentDocument::created(function (IntelligentDocument $d): void {
            try {
                app(IntelligentToIntegratedBridge::class)->file($d);
            } catch (\Throwable $e) {
                report($e); // 되돌리기 실패가 원본 등록을 막으면 안 된다.
            }
        });

        // AI 문서함의 분석이 끝나면 그 결과를 문서관리 쪽 줄에도 옮겨 적는다.
        // 안 옮기면 제목도 종류도 없는 줄이 남아, 결국 사람이 열어 보고 손으로 채운다.
        IntelligentDocument::updated(function (IntelligentDocument $d): void {
            // 분석이 끝난 상태는 둘이다 — 확신이 높으면 ready, 낮으면 review_required.
            // 둘 다 "읽기는 끝났다" 는 뜻이라 결과를 내려보낸다.
            if (! $d->wasChanged('ai_status')
                || ! in_array($d->ai_status, ['ready', 'review_required'], true)) {
                return;
            }
            try {
                app(IntelligentToIntegratedBridge::class)->syncAnalysis($d);
            } catch (\Throwable $e) {
                report($e);
            }
        });
        ProjectContractDocument::saved(fn (ProjectContractDocument $d) => app(LinkedDocumentFilingObserver::class)->contractDocumentSaved($d));

        // 방에 글이 올라오면 두 가지를 본다(둘 다 응답 후 처리 — 글쓰기를 붙잡지 않는다).
        //   1. @AI 로 부른 질문인가 → 어느 방에서든 답한다.
        //   2. 현장 상황실 글인가   → 공정 반영 제안을 만든다.
        CommunicationMessage::created(function (CommunicationMessage $m): void {
            if (($m->payload['bot'] ?? null) !== null) {
                return; // AI 자신의 답글은 다시 읽지 않는다.
            }

            // 부름은 방 종류를 가리지 않는다 — 공지방이든 1:1 이든 물으면 답한다.
            if (app(\App\Services\Communication\ChatAssistant::class)->mentioned($m->body)) {
                AnswerChatQuestionJob::dispatch($m->id)->afterResponse();
            }

            $room = $m->relationLoaded('room') ? $m->room : CommunicationRoom::find($m->communication_room_id);
            if ($room?->type !== CommunicationRoom::TYPE_SITE_OPS) {
                return;
            }
            ReadOpsRoomMessageJob::dispatch($m->id)->afterResponse();
        });
    }
}
