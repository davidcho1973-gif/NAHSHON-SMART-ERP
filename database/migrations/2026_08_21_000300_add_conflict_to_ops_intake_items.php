<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "확정된 것과 다르게 말했다" 를 담아 두는 자리.
 *
 * 도면에는 4인치인데 대화에서는 6인치로 시공한다고 한다. 진행률이 80% 로 적혀 있는데
 * 30% 라고 한다. 이런 어긋남은 <b>새 사실</b>일 수도 있고 <b>착오</b>일 수도 있다 —
 * 어느 쪽인지는 사람만 안다.
 *
 * 그래서 AI 는 값을 조용히 바꾸지 않는다. 어긋났다는 사실과 근거(무엇과, 무엇이,
 * 어떻게 다른지)를 여기 적고 방에 물어본다. 답이 곧 결정이 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->json('conflict')->nullable()->after('question');
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->dropColumn('conflict');
        });
    }
};
