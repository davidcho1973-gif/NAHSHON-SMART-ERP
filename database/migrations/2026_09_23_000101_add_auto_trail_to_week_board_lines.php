<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작업판 줄이 상황실 글로 <b>저절로</b> 바뀌었을 때 — 누가·무엇을 듣고 바꿨는지 남긴다.
 *
 * 사장 지시: 「상황실 글·사진으로 작업판 자동 갱신도 해줘」. 반장이 상황실에 «급탕 배관
 * 끝났습니다» 라고 올리면 작업판의 그 줄이 완료로 넘어간다. 그러면 화면을 보는 사람은
 * «이건 누가 눌렀지?» 를 묻게 된다 — 그 답이 이 네 칸이다. 사람이 버튼을 누르면 지워진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('week_board_lines', function (Blueprint $table) {
            // 「상황실 09/23 김반장」 처럼 사람이 읽는 출처.
            $table->string('auto_source', 120)->nullable()->after('done_at');
            // AI 가 근거로 삼은 문장 — 「급탕 배관 오늘 다 끝냈습니다」.
            $table->text('auto_quote')->nullable()->after('auto_source');
            $table->foreignId('auto_batch_id')->nullable()->after('auto_quote')
                ->constrained('ops_intake_batches')->nullOnDelete();
            $table->timestamp('auto_at')->nullable()->after('auto_batch_id');
        });

        Schema::table('ops_intake_batches', function (Blueprint $table) {
            // 이 글로 작업판 몇 줄이 움직였나 — 폰의 «올렸습니다» 줄과 상황실 답글이 읽는다.
            $table->unsignedSmallInteger('week_board_updated')->default(0)->after('evidence_filed');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table) {
            $table->dropColumn('week_board_updated');
        });
        Schema::table('week_board_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_batch_id');
            $table->dropColumn(['auto_source', 'auto_quote', 'auto_at']);
        });
    }
};
