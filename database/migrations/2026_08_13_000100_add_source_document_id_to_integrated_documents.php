<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 문서함에서 되돌아온 문서인지 표시한다.
 *
 * 두 문서함 사이에 다리가 양방향이 되면서, 같은 파일이 두 번 되돌아오는 것을 막을
 * 표시가 필요해졌다. 이 값이 있으면 "저쪽 몇 번 줄에서 왔다" 는 뜻이고, 다리는
 * 이미 온 것을 다시 만들지 않는다.
 *
 * 나중에 두 표를 하나로 합칠 때도 이 값이 짝을 알려 준다 — 그때 어느 줄과 어느 줄이
 * 같은 문서인지 사람이 눈으로 맞추게 되면 그 일은 끝나지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('source_document_id')->nullable()->after('duplicate_of_id');
            $table->index('source_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('integrated_documents', function (Blueprint $table) {
            $table->dropIndex(['source_document_id']);
            $table->dropColumn('source_document_id');
        });
    }
};
