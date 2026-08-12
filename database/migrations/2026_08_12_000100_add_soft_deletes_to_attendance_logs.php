<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 출퇴근 기록 삭제 — 지우되 없애지는 않는다.
 *
 * 이 표는 급여의 근거다. 진짜로 지워 버리면 "그날 그 사람이 왔었다" 는 사실 자체가
 * 사라진다. 나중에 임금 다툼이 생겼을 때 우리 쪽에는 아무 근거가 없고, 누가 언제
 * 지웠는지도 남지 않는다.
 *
 * 그래서 삭제는 표시만 한다. 화면과 급여 계산에서는 즉시 빠지고(모델의 전역 스코프가
 * 걸러 낸다), 표에는 남는다. 잘못 지웠으면 되살릴 수 있다.
 *
 * attendance_logs 를 원시 쿼리로 읽는 곳이 한 군데도 없어서(전부 모델을 거친다)
 * 이 전환은 화면·급여·집계 어디에도 예외를 만들지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
