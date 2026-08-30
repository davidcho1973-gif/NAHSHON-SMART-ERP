<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 도면에서 뽑은 물량이 대장에 바로 꽂히게 하기 위한 칸들.
 *
 * 승인 대기줄을 두지 않는다 — 437행을 하나씩 승인하는 화면은 아무도 쓰지 않는다.
 * 대신 <b>확신이 서는 것은 그냥 들어가고, 애매한 것만 표시</b>한다. 표시된 줄만
 * 사람이 보면 되고, 그 판단 근거(왜 애매한가)를 함께 남겨 다시 물어볼 필요가 없게 한다.
 *
 *  confidence      : 0~100. AI 가 스스로 매긴 확신도.
 *  needs_review    : 사람이 봐야 하는가. 확신도가 낮거나 모델끼리 갈렸을 때 켜진다.
 *  review_reason   : 왜 봐야 하는가 — "치수를 못 읽음", "Gemini 98 / GPT 112" 처럼.
 *  source_document_id : 어느 도면에서 나왔는가. 지금 source 는 'SITE-C535' 같은
 *                       글자뿐이라 눌러서 근거로 갈 수 없었다.
 *  extracted_by    : 어느 엔진이 뽑았는가(gemini/claude/openai/사람).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('confidence')->nullable()->after('qty_basis');
            $table->boolean('needs_review')->default(false)->after('confidence');
            $table->string('review_reason', 255)->nullable()->after('needs_review');
            $table->foreignId('source_document_id')->nullable()->after('source')
                ->constrained('intelligent_documents')->nullOnDelete();
            $table->string('extracted_by', 20)->nullable()->after('source_document_id');

            $table->index(['needs_review', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropColumn(['confidence', 'needs_review', 'review_reason', 'extracted_by']);
        });
    }
};
