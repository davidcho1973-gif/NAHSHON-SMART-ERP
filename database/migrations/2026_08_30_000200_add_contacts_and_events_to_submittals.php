<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제출물의 소통 상대와 소통 기록.
 *
 * 제출물 하나에는 세 사람이 있다: 자료를 주는 업체(vendor), 우리, 자료를 최종
 * 받는 원청·감리(recipient). 누구에게 요청했고 무엇을 받았고 언제 전달했는지가
 * 조항 밖(개인 메일함)에만 있으면, 감리가 물을 때 대장이 답하지 못한다 —
 * 그래서 연락처는 행에, 소통은 events 에 남긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submittals', function (Blueprint $table): void {
            $table->string('vendor_name', 120)->nullable();
            $table->string('vendor_email', 160)->nullable();
            $table->string('vendor_phone', 40)->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email', 160)->nullable();
        });

        Schema::create('submittal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submittal_id')->constrained()->cascadeOnDelete();
            // request_sent(업체에 요청) / materials_linked(자료 연결) /
            // transmitted(원청 전달) / approval_linked(승인본 연결) / note
            $table->string('kind', 30);
            // email(서버가 발송) / mailto(사용자 메일앱에서 작성) / manual(화면 조작)
            $table->string('channel', 20)->default('manual');
            $table->string('to_name', 120)->nullable();
            $table->string('to_email', 160)->nullable();
            $table->string('subject', 250)->nullable();
            $table->foreignId('intelligent_document_id')->nullable()
                ->constrained('intelligent_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['submittal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submittal_events');
        Schema::table('submittals', function (Blueprint $table): void {
            $table->dropColumn(['vendor_name', 'vendor_email', 'vendor_phone', 'recipient_name', 'recipient_email']);
        });
    }
};
