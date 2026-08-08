<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작업허가서(PTW, Permit To Work) — 화기·고소·밀폐·전기 LOTO 등 고위험 작업의 발행·승인·서명 문서.
 *
 * 안전계획의 permits(텍스트 목록)를 실제 발행·서명 대상 레코드로 승격한다.
 * 안전 작업카드(safety_work_items)에 연결되며, 발행 → 승인 → 서명완료 워크플로를 가진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_permits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safety_work_item_id')->nullable()->constrained('safety_work_items')->nullOnDelete();
            $table->string('wbs_code')->nullable()->index();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->string('permit_no')->unique();
            $table->string('type');                       // 화기작업 / 고소작업 / 밀폐공간 / 전기 LOTO / 일반
            $table->string('title');
            $table->json('precautions')->nullable();       // 안전 조치·조건 목록

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            // 발행 → 승인 → 서명완료 (+ 만료/취소)
            $table->string('status')->default('발행');

            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('signed_by')->nullable();       // 작업 책임자 서명(이름)
            $table->timestamp('signed_at')->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['safety_work_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_permits');
    }
};
