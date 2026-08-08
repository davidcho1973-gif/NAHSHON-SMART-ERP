<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 이 원문에서 문서함으로 편철된 증빙 사진 수(영수증·납품서 등). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->unsignedSmallInteger('evidence_filed')->default(0)->after('auto_applied');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->dropColumn('evidence_filed');
        });
    }
};
