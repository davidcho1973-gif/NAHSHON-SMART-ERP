<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공정별 도면 — 도면 파일의 «장» 과, 계약의 «공정» 과, 둘을 잇는 선택.
 *
 * ── 왜 세 표인가 ──────────────────────────────────────────────────────
 * 도면 파일은 이미 문서함(intelligent_documents)에 있다. 사장이 2026-09-05 에 도면 8개를
 * 거기 올렸다. 여기서 도면을 또 받는 표를 만들면 같은 도면이 두 곳에 산다. 그래서 파일은
 * 문서함에 두고, 이 표는 그 파일의 «몇 쪽이 어느 도면 번호인가» 만 적는다(drawing_sheets).
 *
 * 공정 목록(work_sections)은 원청과 맺은 계약서 continuation sheet 의 섹션이다 —
 * 「3) DRYWALL」「M1-2. Duct Work」「01. POWER DISTRIBUTION WORK」. 사장 말로 기성관리가
 * 공정관리이므로, 공정의 이름은 계약서의 이름이다. 나중에 계약 행(수량·단가)이 올라오면
 * 이 섹션 아래에 붙는다.
 *
 * 공정↔도면 선택(work_section_sheets)은 <b>도면 번호</b>로 잇는다. 쪽 번호나 파일로 이으면
 * 개정판이 올라올 때마다 선택이 끊긴다. 「드라이월은 703K-A01-01」 은 개정판이 와도 참이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intelligent_document_id')->constrained('intelligent_documents')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('page_no');

            // 도면 번호·제목 — 표제란에서 읽는다. 사람이 고치면 manual 이 켜지고 다시 읽어도 덮지 않는다.
            $table->string('sheet_no', 40)->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->string('discipline', 40)->nullable();
            $table->boolean('manual')->default(false);

            // 글자를 어디서 얻었나: pdf(도면에 글자가 들어 있음) · ocr(사진이라 AI 가 읽음) · none.
            $table->string('text_source', 8)->default('none');
            $table->longText('text')->nullable();

            // pending(아직) · reading(읽는 중) · done · failed
            $table->string('status', 12)->default('pending')->index();
            $table->string('error', 500)->nullable();
            $table->string('ai_model', 60)->nullable();

            $table->string('thumb_disk', 40)->nullable();
            $table->string('thumb_path', 500)->nullable();
            $table->decimal('width_pt', 8, 1)->nullable();
            $table->decimal('height_pt', 8, 1)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['intelligent_document_id', 'page_no']);
            $table->index(['site_id', 'sheet_no']);
        });

        Schema::create('work_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            // 건축공사 · 설비공사 · 전기공사 — 계약서의 큰 묶음.
            $table->string('division', 60);
            // 현장 안에서 바뀌지 않는 부호(A3, M1-2, E01). 계약서의 순번은 줄을 끼우면 바뀌므로 쓰지 않는다.
            $table->string('code', 20);
            $table->string('name', 160);
            $table->decimal('contract_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // 어디서 왔나 — 「계약서 continuation sheet 2026-09」 처럼.
            $table->string('source', 120)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'code']);
        });

        Schema::create('work_section_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_section_id')->constrained()->cascadeOnDelete();
            $table->string('sheet_no', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_section_id', 'sheet_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_section_sheets');
        Schema::dropIfExists('work_sections');
        Schema::dropIfExists('drawing_sheets');
    }
};
