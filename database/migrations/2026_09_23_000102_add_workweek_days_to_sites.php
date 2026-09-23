<?php

use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Wbs\CpmEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 주 작업일을 현장에 둔다 — 「703K 는 주 6일 작업으로」 (사장 지시 2026-09-23).
 *
 * ── 왜 현장 칸인가 ─────────────────────────────────────────────────────
 * 주 작업일은 지금까지 회사 전체 설정(ORG_WORKWEEK, 기본 7일) 하나였다. 나손 현장은
 * 여러 주에 흩어져 있고 원청마다 합의가 다르다 — 한 현장이 주 6일이라고 회사 전체를
 * 6일로 바꾸면 다른 현장의 공정표가 통째로 밀린다. 작업 시작·종료 시각을 현장에 둔 것과
 * 같은 이유로, 주 작업일도 현장에 둔다. 비어 있으면 회사 설정을 그대로 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // 5 · 6 · 7. null = 회사 설정(config org.workweek)을 따른다.
            $table->unsignedTinyInteger('workweek_days')->nullable()->after('break_after_minutes');
        });

        // 사장 지시: 703K 는 주 6일. 값이 이미 있으면 건드리지 않는다(다시 돌아도 안전).
        $site = Site::query()->whereRaw('upper(code) = ?', ['703K'])->whereNull('workweek_days')->first();
        if ($site === null) {
            return;
        }
        $site->forceFill(['workweek_days' => 6])->save();

        // 달력이 바뀌었으니 그 현장 공정표의 날짜·여유를 지금 다시 잰다. 실패해도
        // 배포는 막지 않는다 — 새벽 wbs:recompute-cpm 이 어차피 다시 돈다.
        $codes = WbsItem::query()->where('site_id', $site->id)->whereNotNull('project_code')->distinct()->pluck('project_code');
        foreach ($codes as $code) {
            try {
                app(CpmEngine::class)->recompute((string) $code);
            } catch (Throwable $e) {
                Log::warning("주 6일 전환 뒤 CPM 재계산 실패({$code}): ".$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('workweek_days');
        });
    }
};
