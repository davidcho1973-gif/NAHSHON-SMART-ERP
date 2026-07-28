<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 사진 종류 판별 결과와 자동 반영 건수.
 *
 * 어떤 사진이 영수증으로, 어떤 사진이 시공 사진으로 읽혔는지 화면에 보여줘야
 * 잘못 분류됐을 때 사람이 바로 알아챌 수 있다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->json('photo_kinds')->nullable()->after('photo_paths');
            $table->unsignedSmallInteger('auto_applied')->default(0)->after('photo_kinds');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->dropColumn(['photo_kinds', 'auto_applied']);
        });
    }
};
