<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일일 보고를 ERP 안에서 쓰고, 정해진 사람에게 자동으로 보낸다.
 *
 * 세 가지를 더한다.
 *
 * 1. <b>아침 작업계획서</b>(`plan`) — 지금까지 하루의 기록은 저녁(마감)밖에 없었다.
 *    원청에 내는 것은 아침 계획서와 저녁 마감보고서 둘인데, 아침 것은 위험 3가지
 *    채팅 메시지·안전 작업카드·전날 "내일 할 일" 로 흩어져 있어 한 장으로 낼 수가
 *    없었다. 새 표를 만들지 않는 이유는 하나다 — "그날 그 현장의 보고서는 한 줄"
 *    이라는 원칙을 2026-08-18 에 어렵게 되찾았고, 여기서 다시 쪼개면 같은 날
 *    같은 현장에 답이 둘이 된다. 그래서 같은 줄에 칸으로 넣는다.
 *
 * 2. <b>수신처</b>(`report_recipients`) — 원청 공사팀, 감리, 본사는 받는 사람도
 *    받는 문서도 다르다. 보낼 때마다 주소를 손으로 치면 언젠가 빠뜨린다.
 *    현장별로 저장해 두고 체크만 한다.
 *
 * 3. <b>발송 이력</b>(`report_dispatches`) — "보냈다" 는 말만으로는 나중에 아무도
 *    증명하지 못한다. 누구에게 · 언제 · 무엇을 · 성공했는지를 남긴다.
 *    메일 설정 전이라 메일앱으로 넘긴 경우(`mailto`)도 사실 그대로 기록한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_closing_reports', function (Blueprint $table): void {
            // 아침 계획서 본문. 칸을 12개 더 만들지 않고 json 하나로 두는 이유 —
            // 계획서 항목(작업위치·투입장비·허가·위험요인)은 현장마다 다르고
            // 앞으로도 늘어난다. 집계·검색 대상이 아니라 그대로 내보내는 문서다.
            $table->json('plan')->nullable()->after('narrative');
            $table->string('plan_status', 20)->default('draft')->after('plan');  // draft | submitted
            $table->timestamp('plan_submitted_at')->nullable()->after('plan_status');
            $table->foreignId('plan_by_id')->nullable()->after('plan_submitted_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('report_recipients', function (Blueprint $table): void {
            $table->id();
            // 현장이 비면 "모든 현장" 수신처(예: 본사 공사부장).
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('org')->nullable();                      // 회사·부서 표기
            $table->string('role', 20)->default('owner');           // owner | gc | partner | internal
            $table->json('receives')->nullable();                   // ['plan','closing']
            $table->boolean('is_cc')->default(false);               // 참조로 받는가
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'active']);
        });

        Schema::create('report_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_closing_report_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                             // plan | closing
            $table->string('channel', 10)->default('mail');         // mail | mailto
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject', 250)->nullable();
            $table->string('status', 10)->default('sent');          // sent | failed | skipped
            $table->text('error')->nullable();
            // 보낸 보고서를 문서함에 편철한 결과.
            $table->foreignId('intelligent_document_id')->nullable()
                ->constrained('intelligent_documents')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['daily_closing_report_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dispatches');
        Schema::dropIfExists('report_recipients');

        Schema::table('daily_closing_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_by_id');
            $table->dropColumn(['plan', 'plan_status', 'plan_submitted_at']);
        });
    }
};
