<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공정관리(WBS)에 CPM 계획 정보를 담는다 — 외부 공정표(엑셀/CPM)를 손실 없이 수용하기 위함.
 *
 * 기존 스키마는 작업명/공수/일정만 담을 수 있어 선행관계·여유·임계경로가 버려졌다.
 * 여기에 더해 "인원 몇 명 · 어떤 장비"(crew)를 구조화해 저장한다 — 안전계획의 입력값이자
 * 나중에 인원관리/급여 정산과 잇는 고리다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wbs_items')) {
            return;
        }

        Schema::table('wbs_items', function (Blueprint $table): void {
            // 외부 공정표의 액티비티 ID (예: A010) — 선행관계는 이 값으로 서로를 가리킨다.
            $table->string('activity_id', 30)->nullable()->after('node_no');
            // 선행작업 activity_id 배열 (예: ["A330","A340"]).
            $table->json('preds')->nullable()->after('planned_end');
            // 총 여유(일). 0 이면 임계경로 위.
            $table->smallInteger('float_days')->nullable()->after('preds');
            $table->boolean('is_critical')->default(false)->after('float_days');
            // CPM 이 계산한 최만시작/최만종료 — 지연 허용폭 판단용 (planned_start/end = ES/EF).
            $table->date('late_start')->nullable()->after('is_critical');
            $table->date('late_end')->nullable()->after('late_start');
            // 계획원가(배분원가). 실적원가는 급여/경비 모듈에서 온다.
            $table->decimal('planned_cost', 12, 2)->nullable()->after('late_end');

            // 투입조 — 원문 + 파싱 결과. 원문을 남기는 이유: 파서가 놓친 뉘앙스를 사람이 볼 수 있어야 한다.
            $table->string('crew_text', 120)->nullable()->after('company');
            $table->decimal('crew_size', 5, 1)->nullable()->after('crew_text'); // 0.5명(겸임) 표현 가능
            $table->json('crew_roles')->nullable()->after('crew_size');         // [{count, role, external}]
            $table->json('equipment')->nullable()->after('crew_roles');         // ["lifts"] 등

            $table->index('activity_id');
            $table->index(['project_code', 'is_critical']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wbs_items')) {
            return;
        }

        Schema::table('wbs_items', function (Blueprint $table): void {
            $table->dropIndex(['activity_id']);
            $table->dropIndex(['project_code', 'is_critical']);
            $table->dropColumn([
                'activity_id', 'preds', 'float_days', 'is_critical', 'late_start', 'late_end',
                'planned_cost', 'crew_text', 'crew_size', 'crew_roles', 'equipment',
            ]);
        });
    }
};
