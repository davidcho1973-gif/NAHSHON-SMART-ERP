<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 시방서에서 뽑은 제출물이 대장에 바로 꽂히게 하기 위한 칸들 — 물량과 같은 규칙이다.
 *
 * 제출물에서 "애매함"은 물량보다 더 무겁다. 정지 조항(승인 전 발주·시공 금지)을
 * 잘못 판정하면 두 방향으로 다 사고가 난다: 게이트가 아닌 것을 게이트로 보면 공사가
 * 서고, 게이트인 것을 놓치면 승인 없이 시공했다가 재시공이 된다. 그래서 게이트
 * 판정이 애매한 항목은 반드시 사람 눈에 띄어야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submittals', function (Blueprint $table): void {
            $table->unsignedTinyInteger('confidence')->nullable()->after('gate');
            $table->boolean('needs_review')->default(false)->after('confidence');
            $table->string('review_reason', 255)->nullable()->after('needs_review');
            $table->foreignId('source_document_id')->nullable()->after('review_reason')
                ->constrained('intelligent_documents')->nullOnDelete();
            $table->string('extracted_by', 20)->nullable()->after('source_document_id');
            // 시방 원문 인용 — 대장 한 줄이 어느 문장에서 나왔는지. 감리 앞에서 근거가 된다.
            $table->text('source_excerpt')->nullable()->after('extracted_by');

            $table->index(['needs_review', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::table('submittals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropColumn(['confidence', 'needs_review', 'review_reason', 'extracted_by', 'source_excerpt']);
        });
    }
};
