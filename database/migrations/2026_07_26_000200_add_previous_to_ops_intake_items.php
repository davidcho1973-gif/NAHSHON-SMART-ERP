<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 반영 전 값 보관 — 잘못 반영된 제안을 되돌릴 수 있게 한다.
 * (AI 가 읽은 걸 자동 반영하는 이상, 되돌리기는 선택이 아니라 필수다.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->json('previous')->nullable()->after('proposed');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->dropColumn('previous');
        });
    }
};
