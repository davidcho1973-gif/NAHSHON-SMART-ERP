<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 기성 청구(pay application)·수금(receipt) 원장을 신설한다.
 *
 * ## 왜 만드나
 *
 * 재무 대시보드의 '기성 수금액(고객사 지급)' 카드는 직원 경비를 보여주다 제거됐다
 * (교정 커밋 64e2162). 근본 원인은 수금 원장 부재 — 수주 계약(project_contracts,
 * direction='receivable')에는 발주 쪽 procurement_items 같은 트랜잭션 자식이 없어서
 * 청구·수금·미수금을 시스템이 알 수 없었다. 발주 누계 패턴의 수주 측 대칭으로
 * 기성 회차 → 수금 → 미수금(AR)·유보금 잔액의 원장을 만든다.
 *
 * ## 왜 restrictOnDelete 인가 (관례 이탈 명기)
 *
 * 소유 자식 테이블의 관례는 cascadeOnDelete 다(project_contract_documents 등).
 * 그러나 기성 회차와 수금은 GC 에 제출·수취한 재무 1차 기록이라, 계약을 지우면서
 * 원장이 소리 없이 함께 사라지면 분쟁·감사 때 증빙이 소실된다. 그래서 계약 FK 는
 * restrictOnDelete — DB 가 삭제 자체를 거부한다. ContractAdminService::delete 의
 * 문서 가드와 동일한 "기성 회차가 N건 있습니다" 서비스 가드를 병행하되,
 * 서비스를 우회한 삭제까지 DB 가 백스톱으로 막는 이중화다.
 *
 * ## 구조 요점
 *
 * - 수금은 계약 직속(project_contract_id 필수) + 회차는 nullable — 입금이 먼저
 *   오고 회차 매칭은 나중인 현실을 수용한다(매칭 대기).
 * - 파생 금액 컬럼(D·G·held·line6·line7·due)은 전부 서버 계산값 저장 —
 *   산식 정본은 BillingCalculator, 여기는 저장소일 뿐이다.
 * - 복수 테이블 1파일은 2026_07_20_000230 선례.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_applications', function (Blueprint $table): void {
            $table->id();
            // 재무 1차 기록 보호 — 회차가 남아 있는 계약은 DB 가 삭제를 거부한다 (docblock 참조)
            $table->foreignId('project_contract_id')->constrained('project_contracts')->restrictOnDelete();
            // 스코프 컬럼 — 생성 시 계약에서 복사한다 (applyScope 필터 대상)
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('internal_reference', 80)->unique();   // PA-2026-00001 자동 채번
            $table->smallInteger('application_no');               // 계약 내 자동 max+1
            $table->string('type', 20)->default('progress');      // progress|retainage_release|final
            $table->string('status', 20)->default('draft')->index(); // draft|submitted|approved|paid

            $table->date('period_start')->nullable();
            $table->date('period_end');                           // 청구 대상 기간 말일 — 필수
            $table->date('submitted_on')->nullable();
            $table->date('approved_on')->nullable();
            // 지급 기일 — payment_terms 의 "Net N" 파싱 성공 시 제출일+N일, 실패 시 +45일. 수동 편집 허용
            $table->date('due_on')->nullable();

            $table->decimal('this_period_amount', 15, 2)->default(0);      // E: 금회 시공분 (보관 자재의 당월 시공 전환분 포함)
            // F 는 스톡: 매회 "현재 보관분"을 재기재한다. 시공되면 E 로 옮기고 F 에서 뺀다 — 누계 가산 아님
            $table->decimal('stored_materials_amount', 15, 2)->default(0);
            $table->decimal('previous_billed_amount', 15, 2)->default(0);  // D = 전회 (D+E). 서버 계산, 사용자 입력 무시
            $table->decimal('cumulative_amount', 15, 2)->default(0);       // G = D+E+F (청구 누계). 서버 계산
            $table->decimal('retainage_percent', 5, 2)->nullable();        // 계약 유보율 스냅샷 — 율 인하 시 이 회차부터 새 율
            // 금회 유보 해제액 (≥0). 제출 시 상한 검증: ≤ 직전 승인 회차의 retainage_held
            $table->decimal('retainage_released', 15, 2)->default(0);
            // 누계 유보 잔액 = round(G × r/100, 2) − Σ해제누계. 누계에 1회 반올림 — 회차별 반올림 합산 금지
            $table->decimal('retainage_held', 15, 2)->default(0);
            $table->decimal('earned_less_retainage', 15, 2)->default(0);   // line6 = G − retainage_held. 서버 계산
            $table->decimal('previous_certificates', 15, 2)->default(0);   // line7 = 전회 회차의 line6 스냅샷. 서버 계산
            $table->decimal('amount_due', 15, 2)->default(0);              // line8 = line6 − line7 (금회 순청구액). 서버 계산
            // GC 승인 순청구액 — 삭감 시 청구 원본(amount_due)과 분리 기록. null 이면 청구액 그대로 승인
            $table->decimal('approved_amount', 15, 2)->nullable();

            // 회차별 lien waiver 발행일 (AZ A.R.S. §33-1008) — 기록만, 제출 게이트 없음
            $table->date('conditional_waiver_on')->nullable();
            $table->date('unconditional_waiver_on')->nullable();

            $table->timestampTz('paid_at')->nullable();           // 수금 완료 확정 시각 (timestampTz — payroll_runs 관례)
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('intelligent_document_id')->nullable()->constrained('intelligent_documents')->nullOnDelete();
            $table->string('source_ref', 120)->unique()->nullable(); // Phase 3 커넥터 멱등 키 (document:{id})
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();                  // 확장 슬롯 (수동 종결 사유 {closedManually, reason} 등)
            $table->timestamps();

            $table->unique(['project_contract_id', 'application_no'], 'pay_app_contract_no_uq');
            $table->index(['company_id', 'site_id', 'status'], 'pay_app_scope_status_idx');
        });

        Schema::create('billing_receipts', function (Blueprint $table): void {
            $table->id();
            // 계약 직속 — 회차 미배정 입금의 귀속처. 수금이 남은 계약도 삭제 거부 (docblock 참조)
            $table->foreignId('project_contract_id')->constrained('project_contracts')->restrictOnDelete();
            // null = 매칭 대기. 배정·재배정 가능 — 입금이 먼저 오고 매칭은 나중인 현실 수용
            $table->foreignId('pay_application_id')->nullable()->constrained('pay_applications')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->date('received_on');                          // 입금일
            $table->decimal('amount', 15, 2);                     // 입금액 (> 0)
            $table->string('method', 20)->default('check');       // check|ach|wire|other
            $table->string('reference', 120)->nullable();         // Check # / ACH trace #
            $table->decimal('deduction_amount', 15, 2)->default(0); // 이 입금과 함께 GC 가 상계한 금액
            $table->string('deduction_reason', 20)->nullable();   // backcharge|discount|adjustment|other
            // 3상태: null=미판단 / true=인정 / false=불인정(분쟁). true 만 회차 잔액에서 차감된다
            $table->boolean('deduction_accepted')->nullable()->default(null);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('intelligent_document_id')->nullable()->constrained('intelligent_documents')->nullOnDelete();
            $table->string('source_ref', 120)->unique()->nullable(); // Phase 3 커넥터 멱등 키
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index(['project_contract_id', 'pay_application_id'], 'billing_receipt_contract_app_idx');
            $table->index(['company_id', 'site_id', 'received_on'], 'billing_receipt_scope_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_receipts');
        Schema::dropIfExists('pay_applications');
    }
};
