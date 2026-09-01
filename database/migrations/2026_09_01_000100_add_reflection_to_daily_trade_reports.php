<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 제출 = 반영. 반장이 「오늘 보고 제출」을 누르면 그 보고에 담긴 내용이 ERP 로 넘어간다.
 *
 * 지금까지는 반장이 사진과 글을 올리면 AI 가 판독해 «제안» 까지만 만들어 두고, 그것을
 * 공정표·조달·검사에 옮기는 일은 사람이 PC 상황실에서 버튼을 눌러야 했다. 그래서
 * 현장에서 올라온 사실이 ERP 화면에 뜨기까지 <b>한 사람의 손</b>을 더 거쳤고, 그 손이
 * 바쁜 날에는 그날의 진척이 공정표에 없는 채로 마감이 돌았다.
 *
 * 이제 제출이 그 손을 대신한다. 다만 <b>전부</b>를 대신하지는 않는다 — 돈(지출)과
 * 준수 기록(제출·승인)은 여전히 사람이 누른다. 되돌려도 나간 돈은 돌아오지 않고,
 * 승인은 승인본 문서가 있어야 성립하기 때문이다.
 *
 * 여기 붙는 칸들은 그 결과를 남기는 자리다. 몇 건이 넘어갔고 몇 건이 사람 확인으로
 * 남았는지 보고 한 장마다 적힌다 — 적어 두지 않으면 "내가 올린 게 반영은 됐나" 를
 * 반장이 알 방법이 없고, 모르면 다시 올린다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_trade_reports', function (Blueprint $table): void {
            /** 이 보고에서 ERP 로 넘어간 건수(자동·수동 합계). */
            $table->unsignedSmallInteger('applied_count')->default(0);
            /** 사람 확인으로 남긴 건수 — 이 숫자가 0 이 아니면 누군가 봐야 한다. */
            $table->unsignedSmallInteger('held_count')->default(0);
            /** 반영을 돌린 시각. null 이면 아직 안 돌았다(제출 직후 잠깐). */
            $table->timestamp('reflected_at')->nullable();
            /** 사람이 읽는 한 줄 — "공정 2건 반영 · 1건 확인 필요". */
            $table->string('reflection_note', 300)->nullable();
        });

        Schema::table('ops_intake_items', function (Blueprint $table): void {
            /**
             * 누가 눌렀는가가 아니라 <b>어떤 경로로</b> 반영됐는가.
             *
             * applied_by_id 만으로는 "김반장이 공정표를 70% 로 바꿨다" 와 "김반장이 보고를
             * 제출했고 그래서 70% 가 됐다" 를 구별할 수 없다. 나중에 숫자를 되짚을 때
             * 이 구별이 곧 답이다.
             *
             * manual(상황실에서 사람이 누름) / report(보고 제출로 자동) / auto(판독 직후 자동)
             */
            $table->string('applied_via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops_intake_items', function (Blueprint $table): void {
            $table->dropColumn('applied_via');
        });

        Schema::table('daily_trade_reports', function (Blueprint $table): void {
            $table->dropColumn(['applied_count', 'held_count', 'reflected_at', 'reflection_note']);
        });
    }
};
