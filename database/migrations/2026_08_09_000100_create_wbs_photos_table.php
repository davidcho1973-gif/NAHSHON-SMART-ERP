<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공정별 현장 사진 — 날짜별로 쌓인다.
 *
 * 공정 하나는 며칠에 걸쳐 진행되고, 사진은 "그날 무엇이 어디까지 됐는지" 의 기록이다.
 * 그래서 사진 한 장이 아니라 (공정 × 날짜) 로 쌓여야 의미가 있다 — 8/3 의 배관과
 * 8/7 의 배관을 나란히 볼 수 있어야 진행이 보이고, 나중에 분쟁이 생겼을 때
 * "그날 그 상태였다" 를 증명하는 것도 이 날짜다.
 *
 * wbs_items FK 가 아니라 wbs_code 문자열로 잇는다(안전카드와 같은 방식). 공정표는
 * Rev.2 → Rev.3 로 계속 교체되는데, 교체는 트리를 전부 지웠다 다시 만든다. FK 였다면
 * 교체할 때마다 사진이 전부 사라진다. wbs_code(프로젝트-W-액티비티ID)는 교체 후에도
 * 유지되므로 살아남은 공정의 사진은 자동으로 그대로 붙어 있다.
 *
 * 원본은 저장하지 않는다. 요즘 폰 사진은 한 장에 3~8MB 라 현장 한 곳만으로도 수십 GB 가
 * 된다. 올라온 즉시 장변 1,600px JPEG 로 줄여 저장하고, 목록용 썸네일(400px)을 따로 굽는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wbs_photos', function (Blueprint $table) {
            $table->id();

            // 안전카드처럼 느슨한 코드 연결 — 공정표 교체를 견딘다.
            $table->string('wbs_code')->index();
            $table->string('project_code')->nullable()->index();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // 촬영일. 업로드한 날이 아니라 "그 사진이 찍힌 날" — 현장은 며칠 뒤에 올리기도 한다.
            $table->date('photo_date')->index();
            $table->text('caption')->nullable();

            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('mime', 60)->default('image/jpeg');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            // 얼마나 줄였는지 보여 주기 위해 원본 크기도 남긴다.
            $table->unsignedBigInteger('original_bytes')->default(0);
            $table->string('original_name')->nullable();

            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // 목록은 항상 "이 공정의 사진을 날짜순으로".
            $table->index(['wbs_code', 'photo_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wbs_photos');
    }
};
