<?php

namespace App\Services\Ops;

use App\Models\BoqItem;
use App\Models\OpsActionItem;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\WbsItem;
use Illuminate\Database\Eloquent\Model;

final class MeetingContext
{
    public function get(int $siteId): array
    {
        $targets = [];
        $truncated = false;
        foreach (['P' => ProcurementItem::class, 'W' => WbsItem::class, 'B' => BoqItem::class, 'S' => Submittal::class] as $type => $class) {
            $q = $class::where('site_id', $siteId)->orderBy('id');
            if ($type === 'P') {
                $q->with(['item', 'wbsItem']);
            }
            if ($type === 'W') {
                $q->where('level', 'subtask');
            }
            $rows = $q->limit(1001)->get();
            $truncated = $truncated || $rows->count() > 1000;
            foreach ($rows->take(1000) as $row) {
                $targets[$type.':'.$row->id] = $this->describe($type, $row);
            }
        }

        return ['targets' => $targets, 'truncated' => $truncated,
            'open_tasks' => OpsActionItem::where('site_id', $siteId)->where('status', 'open')->orderByDesc('id')->limit(100)->get(['id', 'title', 'assignee', 'due_on', 'is_blocker'])->toArray(),
            'site' => Site::findOrFail($siteId)->only(['id', 'name', 'code', 'timezone'])];
    }

    public function model(string $ref, int $siteId): ?Model
    {
        if (! preg_match('/^([PWBS]):([1-9][0-9]*)$/', $ref, $m)) {
            return null;
        }
        $class = ['P' => ProcurementItem::class, 'W' => WbsItem::class, 'B' => BoqItem::class, 'S' => Submittal::class][$m[1]];

        return $class::where('site_id', $siteId)->lockForUpdate()->find((int) $m[2]);
    }

    public function describe(string $type, Model $r): array
    {
        $data = match ($type) {
            'P' => ['name' => trim(($r->item?->name ?? '').' '.($r->wbsItem?->name ?? '').' '.$r->wbs_code),
                'code' => $r->po_no, 'status' => $r->status, 'eta' => $r->eta?->toDateString(), 'ordered_on' => $r->ordered_on?->toDateString(),
                'vendor' => $r->vendor, 'note' => $r->note, 'wbs_id' => $r->wbs_item_id,
                'need_by' => $r->wbsItem?->planned_end?->toDateString(), 'document_name' => $r->document_name],
            'W' => $r->only(['name', 'wbs_code', 'status', 'progress', 'crew_size', 'company', 'trade', 'hold_point', 'hold_released'])
                + ['planned_start' => $r->planned_start?->toDateString(), 'planned_end' => $r->planned_end?->toDateString()],
            'B' => ['name' => trim($r->name_kr.' '.$r->name_en), 'spec' => $r->spec, 'qty' => $r->qty, 'unit' => $r->unit,
                'source' => $r->source, 'wbs_activity_id' => $r->wbs_activity_id, 'note' => $r->note],
            'S' => ['name' => $r->title, 'code' => $r->seq, 'status' => $r->status, 'planned_on' => $r->planned_on?->toDateString(), 'assignee' => $r->assignee, 'gate' => $r->gate],
        };

        return $data + ['ref' => $type.':'.$r->id, 'version' => $r->updated_at?->format('Y-m-d H:i:s.u')];
    }

    public function vocabulary(array $context, string $participants): array
    {
        return collect(preg_split('/[,\n]/u', $participants) ?: [])->merge(
            collect($context['targets'])->flatMap(fn ($r) => [$r['name'] ?? '', $r['code'] ?? '', $r['vendor'] ?? ''])
        )->map(fn ($s) => trim((string) preg_replace('/[<>{}\[\]\\\\]/u', '', (string) $s)))
            ->filter(fn ($s) => $s !== '' && mb_strlen($s) < 50 && count(preg_split('/\s+/u', $s)) <= 5)
            ->unique()->take(100)->values()->all();
    }
}
