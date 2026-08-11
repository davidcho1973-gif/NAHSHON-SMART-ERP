<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 고객사 관리자가 직접 고치는 설정.
 *
 * config/org.php 와 나누는 기준은 "누가 언제 고치나"다. 설정 파일은 배포할 때
 * 우리가 정하고 거의 안 바뀐다. 이 표는 고객사 관리자가 화면에서 고친다 —
 * 회사 이름을 바꾸려고 우리한테 연락하게 만들면 그게 곧 우리 일이 된다.
 *
 * 값이 없으면 config/org.php 가 답한다. 그래서 이 표는 비어 있어도 정상이고,
 * 새 배포는 아무것도 넣지 않아도 선다.
 *
 * 표를 키·값 한 쌍으로 둔 이유 — 설정 항목이 늘 때마다 마이그레이션을 쓰게 되면
 * 항목 하나 추가에 배포가 필요해진다. 항목은 App\Support\Org 에 목록으로 있고,
 * 그 목록이 유일한 정의다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_settings');
    }
};
