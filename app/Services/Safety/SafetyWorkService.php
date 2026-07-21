<?php

namespace App\Services\Safety;

use App\Models\SafetyWorkIssue;
use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server-side store for the AI 작업안전관리 flow (replaces browser localStorage).
 * Keeps the legal records — TBM signatures and close reports — auditable and shared.
 */
class SafetyWorkService
{
    /**
     * Scoped list of work cards in the SPA's client shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(string $siteId = 'ALL'): array
    {
        $query = SafetyWorkItem::query()->with(['signatures', 'issues'])->orderBy('id');

        if ($siteId !== 'ALL') {
            $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
        }

        return $query->get()->map->toClientArray()->values()->all();
    }

    /**
     * 안전관리 작업 카드 전체 초기화 — 새 프로젝트 시작 시 화면을 깨끗이 비운다.
     *
     * 서명(TBM)·이슈까지 함께 삭제한다(WbsClear 와 동일한 삭제 집합·순서). site 스코프가
     * 있으면 그 현장 카드만, 'ALL' 이면 전체. 되돌릴 수 없으므로 프론트에서 확인 후 호출한다.
     *
     * @return array{success: bool, deleted: array{cards: int, signatures: int, issues: int}}
     */
    public function clearAll(string $siteId = 'ALL'): array
    {
        return DB::transaction(function () use ($siteId): array {
            $query = SafetyWorkItem::query();
            if ($siteId !== 'ALL') {
                $query->where('site_id', Site::query()->where('code', $siteId)->value('id'));
            }
            $ids = $query->pluck('id');

            $signatures = SafetyWorkSignature::query()->whereIn('safety_work_item_id', $ids)->delete();
            $issues = SafetyWorkIssue::query()->whereIn('safety_work_item_id', $ids)->delete();
            $cards = SafetyWorkItem::query()->whereIn('id', $ids)->delete();

            return ['success' => true, 'deleted' => ['cards' => (int) $cards, 'signatures' => (int) $signatures, 'issues' => (int) $issues]];
        });
    }

    /**
     * Upsert each client work card (by work_code) plus its signatures and issues.
     *
     * @param  array<int, mixed>  $items
     */
    public function save(array $items, string $siteId = 'ALL', ?int $userId = null): int
    {
        $resolvedSiteId = $siteId !== 'ALL'
            ? Site::query()->where('code', $siteId)->value('id')
            : null;

        $saved = 0;

        DB::transaction(function () use ($items, $resolvedSiteId, $userId, &$saved): void {
            foreach ($items as $data) {
                if (! is_array($data) || blank($data['id'] ?? null)) {
                    continue;
                }

                $item = SafetyWorkItem::query()->firstOrNew(['work_code' => $data['id']]);
                $item->fill(SafetyWorkItem::columnsFromClient($data));

                if ($resolvedSiteId !== null) {
                    $item->site_id = $resolvedSiteId;
                }
                if (! $item->exists) {
                    $item->created_by_id = $userId;
                }

                $item->save();

                $this->syncSignatures($item, is_array($data['signatures'] ?? null) ? $data['signatures'] : []);
                $this->syncIssues($item, is_array($data['issues'] ?? null) ? $data['issues'] : []);

                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Persist the (edited) work card, generate an AI safety plan for it, store it, and
     * return the refreshed client item plus the raw plan.
     *
     * @param  array<string, mixed>  $data
     * @return array{item: array<string, mixed>, plan: array<string, mixed>}
     */
    public function generatePlan(array $data, string $siteId = 'ALL', ?int $userId = null): array
    {
        $this->save([$data], $siteId, $userId);
        $item = SafetyWorkItem::query()->where('work_code', $data['id'])->firstOrFail();

        // WBS 에서 넘어온 공종·안전위험도·투입조·장비를 AI 에 함께 넘겨 추천을 공종에 맞게 정확히.
        $seed = is_array($item->plan_payload) ? $item->plan_payload : [];
        $plan = app(GeminiSafetyAnalyzer::class)->generatePlan([
            'title' => $item->title,
            'workText' => $item->work_text,
            'project' => $item->project,
            'location' => $item->location,
            'crew' => $item->crew,
            'qty' => $item->planned_qty,
            'unit' => $item->unit,
            'trade' => (string) ($seed['trade'] ?? ''),
            'ehs' => (string) ($seed['ehs'] ?? ''),
            'crew_text' => (string) ($seed['crew_text'] ?? ''),
            'equipment' => is_array($seed['equipment'] ?? null) ? implode(', ', $seed['equipment']) : (string) ($seed['equipment'] ?? ''),
        ]);

        $payload = is_array($item->plan_payload) ? $item->plan_payload : [];
        $payload['plan'] = $plan;
        $payload['plan_generated_at'] = Carbon::now()->toIso8601String();

        $item->plan_payload = $payload;
        if (in_array($item->plan_status, ['미생성', '초안', '수정필요'], true)) {
            $item->plan_status = '검토중';
        }
        $item->save();

        return ['item' => $item->fresh(['signatures', 'issues'])->toClientArray(), 'plan' => $plan];
    }

    /**
     * Persist the (edited) close report, get an AI progress recommendation, store it,
     * and return the refreshed client item plus the recommendation.
     *
     * @param  array<string, mixed>  $data
     * @return array{item: array<string, mixed>, recommendation: array<string, mixed>}
     */
    public function recommendProgress(array $data, string $siteId = 'ALL', ?int $userId = null): array
    {
        $this->save([$data], $siteId, $userId);
        $item = SafetyWorkItem::query()->where('work_code', $data['id'])->firstOrFail();

        $recommendation = app(GeminiSafetyAnalyzer::class)->recommendProgress([
            'title' => $item->title,
            'closeText' => $item->close_text,
            'doneQty' => $item->done_qty,
            'totalQty' => $item->total_qty,
            'unit' => $item->unit,
        ]);

        $payload = is_array($item->plan_payload) ? $item->plan_payload : [];
        $payload['progress'] = $recommendation;
        $payload['progress_generated_at'] = Carbon::now()->toIso8601String();

        $item->plan_payload = $payload;
        $item->progress = (int) ($recommendation['recommended_progress'] ?? $item->progress);
        $item->progress_status = '추천완료';
        $item->save();

        return ['item' => $item->fresh(['signatures', 'issues'])->toClientArray(), 'recommendation' => $recommendation];
    }

    /**
     * @param  array<int, mixed>  $signatures
     */
    private function syncSignatures(SafetyWorkItem $item, array $signatures): void
    {
        foreach (array_values($signatures) as $index => $sig) {
            if (! is_array($sig)) {
                continue;
            }

            $model = $item->signatures()->where('sort_order', $index)->first()
                ?? new SafetyWorkSignature(['sort_order' => $index]);

            $model->name = (string) ($sig['name'] ?? '');
            $model->role = $sig['role'] ?? null;
            $model->signed = (bool) ($sig['signed'] ?? false);

            // Server is the source of truth for the legal sign-off timestamp.
            if ($model->signed && $model->signed_at === null) {
                $model->signed_at = now();
            }
            if (! $model->signed) {
                $model->signed_at = null;
            }

            $item->signatures()->save($model);
        }

        $item->signatures()->where('sort_order', '>=', count($signatures))->delete();
    }

    /**
     * @param  array<int, mixed>  $issues
     */
    private function syncIssues(SafetyWorkItem $item, array $issues): void
    {
        foreach (array_values($issues) as $index => $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $item->issues()->updateOrCreate(
                ['sort_order' => $index],
                [
                    'type' => $issue['type'] ?? '미조치',
                    'body' => $issue['text'] ?? null,
                    'owner' => $issue['owner'] ?? null,
                    'status' => $issue['status'] ?? '조치중',
                ],
            );
        }

        $item->issues()->where('sort_order', '>=', count($issues))->delete();
    }
}
