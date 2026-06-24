<?php

namespace App\Services\Safety;

use App\Models\SafetyWorkItem;
use App\Models\SafetyWorkSignature;
use App\Models\Site;
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
