<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자재 입고 — <b>공정표 없이</b>, 수량과 함께.
 *
 * ── 왜 새 표가 필요한가 ────────────────────────────────────────────────
 * 기존 조달 추적(`procurement_items`)은 <b>공정표(WBS) 한 줄에 하나</b>다. 그래서
 * 공정표에 「○○ 조달」 공정이 없으면 기록할 자리 자체가 없다. 그리고 그 표에는
 * <b>수량 칸이 없다</b> — 금액과 단계만 있어서 「100개 중 60개 왔다」 를 적을 수 없다.
 *
 * 현장에서 실제로 일어나는 일은 «트럭이 왔고, 무엇이 몇 개 왔다» 이다. 그건 공정표와
 * 무관하게 일어나고, 수량이 곧 내용이다. 그래서 그 사실을 그대로 담는 표를 둔다.
 *
 * 기존 조달 추적을 고쳐 쓰지 않는 이유: 그쪽은 «납기를 언제까지 맞춰야 하는가» 를
 * 공정에 붙여 보는 화면이다. 둘은 묻는 질문이 다르다. 한 표에 두 질문을 담으면
 * 둘 다 어정쩡해진다 — 공정 없는 입고에는 빈 칸이, 납기 추적에는 안 쓰는 수량이 남는다.
 *
 * ── 한 장(receipt) 과 여러 줄(line) ───────────────────────────────────
 * 트럭 한 대가 오면 납품서 한 장에 품목이 여러 줄이다. 그 모양 그대로 담는다.
 * 줄을 각각 따로 저장하면 «이 트럭이 언제 왔는가» 가 줄마다 복사되고, 한 줄만
 * 고쳤을 때 같은 배송의 날짜가 갈라진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_receipts', function (Blueprint $table) {
            $table->id();

            // 접근제어(AGENTS.md §6-3)가 그대로 걸리도록 scope 칸을 둔다.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->date('received_on');
            $table->string('vendor', 160)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('po_no', 80)->nullable();
            $table->string('delivery_no', 80)->nullable();
            $table->text('note')->nullable();

            // 확인 전에는 draft — 사진에서 AI 가 읽은 값이 사람 눈을 거치기 전 상태다.
            // 수량은 원가와 재고가 되는 숫자라, 읽은 그대로 장부가 되면 안 된다.
            $table->string('status', 16)->default('draft');

            $table->string('photo_disk', 32)->nullable();
            $table->string('photo_path', 512)->nullable();
            $table->string('photo_name', 255)->nullable();

            // AI 가 무엇을 읽었는지 원문 그대로 — 나중에 «왜 이 숫자냐» 에 답하는 근거.
            $table->json('analysis')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'received_on']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('material_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_receipt_id')->constrained()->cascadeOnDelete();

            // 품목 마스터 연결 — 이름만 두면 "1/2\" EMT" 와 "EMT 1/2" 가 다른 품목으로
            // 집계된다. 이름이 맞으면 붙이고, 없으면 붙이지 않은 채로 둔다(사람이 나중에).
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 255);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('seq')->default(0);
            $table->timestamps();

            $table->index('material_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_receipt_lines');
        Schema::dropIfExists('material_receipts');
    }
};
