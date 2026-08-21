<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 채팅 메시지에 붙는 파일 — 그리고 그 파일이 문서함의 어느 문서가 됐는지.
 *
 * 현장 사람들은 글보다 사진으로 말한다. 영수증도, 자재 라벨도, 도면 사진도
 * 카톡에 찍어 보내는 식으로 오간다. 그런데 그 사진이 메신저 안에서만 살면
 * 같은 내용을 누군가 문서함에 다시 올리고, 재무에 또 입력해야 한다.
 *
 * 그래서 방에 올라온 파일은 <b>문서함으로도 함께 들어간다</b> — 분석·분류·모듈
 * 배달(재무·장비)은 이미 문서함 쪽에 다 지어져 있으므로, 여기서는 "이 메시지의
 * 첨부가 저 문서다" 라는 연결만 기록한다.
 *
 * 파일 정보를 문서 쪽에서 읽지 않고 여기에도 적어 두는 이유: 문서가 지워지거나
 * 분석이 실패해도 <b>대화에 붙은 파일은 그대로 보여야</b> 하기 때문이다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_message_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intelligent_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            // image 는 대화창에 바로 펼쳐 보이고, document 는 카드로 보여준다.
            $table->string('kind', 20)->default('document');
            $table->timestamps();

            $table->index('intelligent_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_message_files');
    }
};
