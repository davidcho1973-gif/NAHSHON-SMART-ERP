<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상황실에 넣은 "원문 한 뭉치" — 붙여넣은 카톡 대화/메모 전체를 그대로 보관한다.
 *
 * 판독 결과(OpsIntakeItem)는 원문의 일부만 인용하므로, 나중에 "왜 이렇게 반영됐지?"를
 * 되짚으려면 그날 대화 전체가 남아 있어야 한다. 그 근거 원본을 여기에 남긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_intake_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('paste');   // paste / room
            $table->foreignId('communication_message_id')->nullable()
                ->constrained('communication_messages')->nullOnDelete();

            $table->longText('raw_text')->nullable();          // 붙여넣은 원문 전체
            $table->unsignedSmallInteger('image_count')->default(0);

            // 판독 결과 집계(목록에서 바로 보이게)
            $table->unsignedSmallInteger('parsed_count')->default(0);
            $table->unsignedSmallInteger('actionable_count')->default(0);
            $table->unsignedSmallInteger('noise_count')->default(0);

            $table->timestamps();
            $table->index(['site_id', 'created_at']);
        });

        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->foreignId('ops_intake_batch_id')->nullable()->after('site_id')
                ->constrained('ops_intake_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ops_intake_batch_id');
        });
        Schema::dropIfExists('ops_intake_batches');
    }
};
