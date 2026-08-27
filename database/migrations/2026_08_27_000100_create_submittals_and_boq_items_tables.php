<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제출물 대장(submittals) + 물량/BOQ(boq_items).
 *
 * 둘 다 "시방·도면에서 뽑은 계약 요구"를 프로젝트 단위로 추적하는 대장이다.
 * 엑셀 대장(703K_제출물대장 등)을 ERP 화면으로 옮긴 것 — 원본 조항 근거(source)와
 * 수량 근거(qty_basis)를 행마다 남겨 검증 가능하게 유지한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submittals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('site_id')->nullable()->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->unsignedInteger('seq'); // 대장 내 순번 (프로젝트 안에서 유니크)
            $table->string('csi', 20); // 03 3000, F, P …
            $table->string('section', 80); // 공종명
            $table->string('category', 30); // Action 제출물 / Informational 제출물 / Closeout 제출물 / 품질보증(QA) / 시험·검사
            $table->text('title'); // 제출물·요구사항 (원문 조항 병기)
            $table->boolean('gate')->default(false); // 시방 명문 정지·실격 조항
            $table->string('status', 20)->default('미착수');
            $table->string('assignee', 60)->nullable();
            $table->date('planned_on')->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('approved_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'seq']);
            $table->index(['project_id', 'csi']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('boq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('site_id')->nullable()->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->unsignedInteger('seq');
            $table->string('discipline_code', 10); // 01~08
            $table->string('discipline', 30); // 토목, 구조, …
            $table->string('name_kr', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('spec')->nullable();
            $table->string('unit', 15);
            $table->decimal('qty', 14, 2)->default(0);
            $table->string('qty_basis', 20)->default('미확정'); // 문서확정/도면판독/개산추정/미확정
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('source', 200)->nullable(); // 산출 근거 시트·조항
            $table->text('note')->nullable();
            $table->boolean('flagged')->default(false); // 단가 편차 등 검토 필요
            $table->timestampsTz();

            $table->unique(['project_id', 'seq']);
            $table->index(['project_id', 'discipline_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('submittals');
    }
};
