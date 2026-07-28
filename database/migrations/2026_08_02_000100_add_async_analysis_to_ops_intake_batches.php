<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 상황실 판독을 요청 응답 후로 미룬다(504 해소).
 *
 * 사진 여러 장을 비전 AI 로 읽으면 수십 초~수 분이 걸린다. 이걸 HTTP 요청 안에서 동기로
 * 돌리면 게이트웨이가 먼저 끊어 504 가 난다. 그래서 업로드/판독 요청은 즉시 돌려주고
 * 진행 상태를 여기에 적어 프론트가 폴링하게 한다.
 *
 * 사진 원본은 DB 가 아니라 디스크에 두고 경로만 보관한다 — 큰 base64 를 DB·메모리에
 * 얹지 않기 위해서다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->string('status', 12)->default('done')->after('noise_count')->index();
            $table->text('error')->nullable()->after('status');
            $table->timestamp('analyzed_at')->nullable()->after('error');
            $table->string('photo_disk', 40)->nullable()->after('analyzed_at');
            $table->json('photo_paths')->nullable()->after('photo_disk');
        });

        // 기존 기록은 이미 판독이 끝난 것들이다.
        DB::table('ops_intake_batches')->update(['status' => 'done']);
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->dropColumn(['status', 'error', 'analyzed_at', 'photo_disk', 'photo_paths']);
        });
    }
};
