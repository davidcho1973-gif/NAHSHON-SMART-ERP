<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 원문 기록 수정 이력.
 *
 * 원문은 "왜 이렇게 반영됐지?"를 되짚는 근거다. 고칠 수 있게 하되, 누가 언제 고쳤고
 * 고치기 전 내용이 무엇이었는지 남겨야 근거로서의 값이 유지된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->longText('original_text')->nullable()->after('raw_text');
            $table->foreignId('edited_by_id')->nullable()->after('created_by_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('edited_by_id');
            $table->dropColumn(['original_text', 'edited_at']);
        });
    }
};
