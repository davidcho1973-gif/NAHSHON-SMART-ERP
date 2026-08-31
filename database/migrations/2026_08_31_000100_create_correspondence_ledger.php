<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 서신 원장 1단계 — <b>보낸 것부터 남긴다.</b>
 *
 * 지금 이 시스템은 메일을 보내고도 무엇을 보냈는지 모른다. app/Mail 의 어떤 Mailable 에도
 * `headers()` 가 없어서 Message-ID 가 발급되지 않고(확인함), 발송 기록은 제출물 이벤트와
 * 일일보고 발송 이력 두 군데에 흩어져 있으며 본문은 어디에도 남지 않는다.
 * "8월 30일에 뭐라고 보냈나" 에 답할 수 없다는 뜻이다.
 *
 * 건설에서 클레임은 서신 기록으로 이긴다. 담당자가 퇴사해도 서신은 남아야 한다.
 *
 * <b>받기보다 보내기가 먼저인 이유</b> — 수신은 도메인 MX·제공자 웹훅·상대방 습관에 기대지만,
 * 발신 기록은 우리 코드만으로 이번 주에 값이 나온다. 그리고 회신을 이어 붙이려면 애초에
 * 우리가 보낸 메일에 Message-ID 가 박혀 있어야 한다 — 그게 없으면 2단계가 성립하지 않는다.
 *
 * 2단계(수신)를 위한 자리는 지금 만들어 둔다. 다만 <b>회신 주소는 아직 헤더에 넣지 않는다</b> —
 * 받을 수 없는 주소를 Reply-To 로 내보내면 상대의 답장이 허공으로 간다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 실타래: 하나의 사안. 회신이 돌아올 열쇠를 쥐고, 어느 업무 대상에 걸렸는지 기억한다.
        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // 법인 잠금이 걸릴 자리. 서신이 법인 경계를 넘으면 안 된다.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // 제목에 박히는 참조번호. 상대가 이 번호로 되묻는다.
            $table->string('ref_code', 40)->unique();

            // 회신 주소에 실릴 열쇠. GuestLink 규약(Str::random(40) 평문 + 폐기 시각)을 그대로 쓴다 —
            // 새 방식을 발명하면 폐기·만료 규칙을 또 한 벌 만들어야 한다.
            $table->string('reply_token', 64)->unique();
            $table->timestamp('revoked_at')->nullable();

            // 어느 업무 대상의 서신인가 — 제출물·계약·기성·현장·거래처 무엇이든.
            $table->nullableMorphs('related');

            $table->string('subject');
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_email')->nullable();
            $table->string('counterparty_org')->nullable();

            $table->string('status', 20)->default('open');           // open|awaiting_reply|closed
            // 문서함과 같은 등급 체계를 쓴다. 서신이 급여·단가를 담으면 아무나 보면 안 된다.
            $table->string('confidentiality', 20)->default('internal');
            $table->date('response_due_on')->nullable();

            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'last_message_at']);
            $table->index(['status', 'last_message_at']);
        });

        // ── 봉투 한 통. 이 표가 전사 서신 원장의 정본이다.
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction', 10)->default('outgoing');     // outgoing|incoming|internal
            // mailto = 메일 서버가 없어 사람 메일앱으로 넘긴 것. 보낸 척하지 않기 위해 따로 센다.
            $table->string('channel', 10)->default('mail');           // mail|mailto|inbound
            $table->string('status', 12)->default('queued');          // queued|sent|failed|skipped|received|delivered|bounced

            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id')->nullable();
            // 우리가 발급하는 RFC Message-ID. 회신의 In-Reply-To 가 이 값을 되돌려 준다.
            $table->string('rfc_message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references_raw')->nullable();

            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to_addresses')->nullable();
            $table->json('cc_addresses')->nullable();

            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('snippet', 300)->nullable();
            $table->unsignedSmallInteger('attachment_count')->default(0);

            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_reason')->nullable();

            $table->timestamp('occurred_at');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mail_thread_id', 'occurred_at']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
            $table->index('rfc_message_id');
            // 2단계 수신에서 웹훅 재전송을 막을 근거. 지금은 비어 있다.
            $table->unique(['provider', 'provider_message_id']);
        });

        // ── 첨부 ↔ 문서함.
        //
        // 문서 쪽에 external_id='mail:{id}' 를 심는 방식을 쓰지 않는다. DocumentIntake 는 같은
        // sha256 이면 기존 문서를 그대로 돌려주므로(중복 방지), 그 위에 표시를 덮으면 업체가 같은
        // 도면을 두 번 보냈을 때 <b>먼저 온 메일의 첨부 목록이 조용히 비어 버린다.</b>
        Schema::create('mail_message_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligent_document_id')->constrained('intelligent_documents')->cascadeOnDelete();
            $table->string('kind', 20)->default('attachment');       // attachment|body|eml
            $table->timestamps();

            $table->unique(['mail_message_id', 'intelligent_document_id'], 'mail_msg_doc_unique');
        });

        // ── 기존 이력 두 벌을 원장에 잇는다. 없애지 않는다(화면이 쓰고 있다).
        if (Schema::hasTable('report_dispatches')) {
            Schema::table('report_dispatches', function (Blueprint $table): void {
                $table->foreignId('mail_message_id')->nullable()->after('intelligent_document_id')
                    ->constrained('mail_messages')->nullOnDelete();
            });
        }
        if (Schema::hasTable('submittal_events')) {
            Schema::table('submittal_events', function (Blueprint $table): void {
                $table->foreignId('mail_message_id')->nullable()
                    ->constrained('mail_messages')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('submittal_events')) {
            Schema::table('submittal_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('mail_message_id'));
        }
        if (Schema::hasTable('report_dispatches')) {
            Schema::table('report_dispatches', fn (Blueprint $t) => $t->dropConstrainedForeignId('mail_message_id'));
        }
        Schema::dropIfExists('mail_message_documents');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_threads');
    }
};
