<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 하루에 대한 보고서를 한 줄로 — `field_daily_reports` 를 `daily_closing_reports` 에 합친다.
 *
 * ## 왜 이렇게 됐나
 *
 * 2026-08 에 현장앱을 흡수하면서 `field_daily_reports` 가 생겼다. 그런데 이 표의 고유키가
 * `(site_id, work_date)` 다 — **이미 있던 `daily_closing_reports` 의 고유키와 똑같다.**
 * 같은 것을 가리키는 표가 둘이 됐다는 뜻이고, 그때부터 "그날 그 현장의 보고서" 라는
 * 물음에 답이 두 개가 됐다.
 *
 * 인원만 넘어가고 있었다(`syncTradesToLaborReports`). 날씨·오늘 한 일·내일 할 일·진도율·
 * TBM·안전점검은 현장앱 안에만 남았다. 그래서:
 *
 *   - 현장소장이 현장앱에서 "오늘 한 일 / 내일 할 일" 을 쓴다.
 *   - 상황실에서 마감을 누르면 AI 가 **같은 것을 다시 쓴다** — 현장이 쓴 글을 못 봤으니까.
 *   - 두 글이 어긋나면 어느 쪽이 맞는지 가릴 방법이 없다.
 *
 * 두 번 쓰는 일이 생긴 것이다.
 *
 * ## 임시방편으로 안 고치는 이유
 *
 * 두 표를 베끼는 동기화 다리를 놓으면 화면상으로는 해결된다. 하지만 정본이 여전히
 * 둘이라 언젠가 갈라지고, 그때 맞추는 네 번째 층이 또 필요해진다. 원인은 "표가 둘"
 * 이므로 표를 하나로 만든다.
 *
 * ## 합친 뒤의 모습 — 한 줄에 세 명의 저자
 *
 *   현장이 쓴 것  : weather · trades · work_today · work_tomorrow · progress_rate · safety_*
 *   시스템이 센 것: metrics  (인원·진도·자재·지출 집계)
 *   AI 가 요약한 것: narrative
 *
 * `status` 는 마감(AI 작성) 상태이고 `field_status` 는 현장 제출 상태다. 뜻이 다르므로
 * 한 칸에 욱여넣지 않는다 — 그랬다면 현장이 제출한 순간 마감이 끝난 것처럼 보인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_closing_reports', function (Blueprint $table): void {
            // ── 현장이 직접 쓴 것 (구 field_daily_reports)
            $table->string('weather', 40)->nullable();
            $table->string('temperature', 40)->nullable();
            $table->json('trades')->nullable();
            $table->string('work_title', 500)->nullable();
            $table->text('work_today')->nullable();
            $table->text('work_tomorrow')->nullable();
            $table->unsignedTinyInteger('progress_rate')->default(0);
            $table->boolean('tbm_completed')->default(false);
            $table->json('safety_checks')->nullable();
            $table->text('safety_notes')->nullable();

            // 마감 상태(status)와 뜻이 다르다: draft / submitted.
            $table->string('field_status', 20)->nullable();
            $table->timestampTz('field_submitted_at')->nullable();
        });

        if (! Schema::hasTable('field_daily_reports')) {
            return;
        }

        // 기존 기록을 옮긴다. 같은 (현장, 날짜) 에 마감 보고서가 이미 있으면 그 줄을 채우고,
        // 없으면 새로 만든다 — 현장만 쓰고 마감을 안 누른 날도 보고서로 남아야 한다.
        foreach (DB::table('field_daily_reports')->orderBy('id')->cursor() as $row) {
            $date = substr((string) $row->work_date, 0, 10);

            $fields = [
                'weather' => $row->weather,
                'temperature' => $row->temperature,
                'trades' => $row->trades,
                'work_title' => $row->work_title,
                'work_today' => $row->work_today,
                'work_tomorrow' => $row->work_tomorrow,
                'progress_rate' => (int) $row->progress_rate,
                'tbm_completed' => (bool) $row->tbm_completed,
                'safety_checks' => $row->safety_checks,
                'safety_notes' => $row->safety_notes,
                'field_status' => $row->status ?: 'draft',
                'field_submitted_at' => $row->submitted_at,
            ];

            $existing = DB::table('daily_closing_reports')
                ->where('site_id', $row->site_id)
                ->whereDate('report_date', $date)
                ->first();

            if ($existing) {
                DB::table('daily_closing_reports')->where('id', $existing->id)->update($fields);

                continue;
            }

            DB::table('daily_closing_reports')->insert($fields + [
                'site_id' => $row->site_id,
                'report_date' => $date,
                // 아직 마감을 안 누른 날. 'writing' 이면 화면이 영원히 "작성 중" 으로 본다.
                'status' => 'open',
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('field_daily_reports');
    }

    public function down(): void
    {
        Schema::create('field_daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->string('weather', 40)->nullable();
            $table->string('temperature', 40)->nullable();
            $table->json('trades')->nullable();
            $table->string('work_title', 500)->nullable();
            $table->text('work_today')->nullable();
            $table->text('work_tomorrow')->nullable();
            $table->unsignedTinyInteger('progress_rate')->default(0);
            $table->boolean('tbm_completed')->default(false);
            $table->json('safety_checks')->nullable();
            $table->text('safety_notes')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'work_date']);
        });

        foreach (DB::table('daily_closing_reports')->whereNotNull('site_id')
            ->whereNotNull('field_status')->orderBy('id')->cursor() as $row) {
            DB::table('field_daily_reports')->insert([
                'site_id' => $row->site_id,
                'work_date' => substr((string) $row->report_date, 0, 10),
                'weather' => $row->weather,
                'temperature' => $row->temperature,
                'trades' => $row->trades,
                'work_title' => $row->work_title,
                'work_today' => $row->work_today,
                'work_tomorrow' => $row->work_tomorrow,
                'progress_rate' => (int) $row->progress_rate,
                'tbm_completed' => (bool) $row->tbm_completed,
                'safety_checks' => $row->safety_checks,
                'safety_notes' => $row->safety_notes,
                'status' => $row->field_status,
                'submitted_at' => $row->field_submitted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::table('daily_closing_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'weather', 'temperature', 'trades', 'work_title', 'work_today', 'work_tomorrow',
                'progress_rate', 'tbm_completed', 'safety_checks', 'safety_notes',
                'field_status', 'field_submitted_at',
            ]);
        });
    }
};
