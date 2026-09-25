<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 도면의 축척 — 도면 위에 그은 선의 길이·영역의 면적을 실제 수량으로 바꾸는 값.
 *
 * 축척은 그 종이(그 파일의 그 쪽)의 사실이라 drawing_sheets 에 둔다. 개정판은 축척이 바뀔 수 있으니
 * 번호가 아니라 장에 붙인다. 값은 «PDF 1포인트가 실제 몇 피트» — 미터 단위 줄은 화면에서 바꿔 쓴다.
 * scale_label 은 어디서 왔는지(«1/4" = 1'-0"» 를 읽음 · «12'-0" 치수로 맞춤»)를 사람이 알아보게 남긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drawing_sheets', function (Blueprint $table): void {
            $table->decimal('feet_per_point', 16, 10)->nullable()->after('height_pt');
            $table->string('scale_label', 120)->nullable()->after('feet_per_point');
        });
    }

    public function down(): void
    {
        Schema::table('drawing_sheets', function (Blueprint $table): void {
            $table->dropColumn(['feet_per_point', 'scale_label']);
        });
    }
};
