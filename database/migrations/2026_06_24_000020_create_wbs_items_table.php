<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공정관리 (WBS) — Stage → Task → SubTask 3단계 계층을 단일 자기참조 테이블로 관리.
 *
 * 설계 의도(아키텍처):
 *  - 프론트(renderWbs)는 stages[].tasks[].sub_tasks[] 트리를 기대하므로 level + parent_id 로 표현.
 *  - 안전 작업카드(safety_work_items)와 "병합"하기 위해 subtask 에 safety_work_code 느슨한 링크를 둠.
 *    하드 FK 를 걸지 않아 WBS 단독으로도 동작/배포 가능하고, 같은 화면에서 진행률이 롤업된다.
 *  - scope 컬럼(company_id, site_id)으로 접근제어(§6-3) 적용 용이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wbs_items', function (Blueprint $table): void {
            $table->id();

            // 프로젝트 연결 — 실제 projects 행과 연결(선택) + 레거시 프론트가 넘기는 코드 키.
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('project_code', 80)->index();

            // 트리 구조
            $table->foreignId('parent_id')->nullable()->constrained('wbs_items')->cascadeOnDelete();
            $table->string('level', 12)->default('subtask'); // stage | task | subtask
            $table->string('wbs_code', 80)->unique();         // 프론트의 wbs_id
            $table->string('node_no', 40)->nullable();        // 1 / 1.2 / 1.2.3 표시번호
            $table->string('name', 255);

            // SubTask 속성
            $table->string('company', 40)->nullable();        // NAHSHON / AUTORICA / AI-KOREA / M-SOL
            $table->string('status', 20)->default('AI생성');  // AI생성 / 검수완료 / 진행중 / 완료 / 보류
            $table->string('ehs', 12)->nullable();            // high / medium / low
            $table->decimal('manhours', 10, 1)->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->unsignedTinyInteger('progress')->default(0); // 0~100 (subtask 기준)
            $table->integer('sort_order')->default(0);

            // 접근제어 scope
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // 안전 작업카드 병합 지점 (느슨한 링크 — FK 없음)
            $table->string('safety_work_code', 80)->nullable()->index();

            $table->string('source', 20)->default('manual');  // manual / ai / import
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['project_code', 'level']);
            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wbs_items');
    }
};
