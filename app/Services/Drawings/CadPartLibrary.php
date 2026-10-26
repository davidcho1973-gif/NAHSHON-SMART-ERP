<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\CadLibraryPart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CadPartLibrary
{
    public function access(CadDrawing $drawing, bool $write = false): void
    {
        app(CadDrawingService::class)->access(auth()->user(), $drawing, $write);
    }

    public function payload(CadLibraryPart $part): array
    {
        return $part->only(['id', 'name', 'category', 'specification', 'code', 'geometry']);
    }

    public function catalog(CadDrawing $drawing, string $search = '', ?string $after = null): array
    {
        $this->access($drawing);
        $query = CadLibraryPart::where('company_id', $drawing->company_id)->where('archived', false)->orderByDesc('id');
        if ($after) {
            $query->where('id', '<', $after);
        }
        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $needle)->orWhere('specification', 'ilike', $needle)->orWhere('code', 'ilike', $needle));
        }
        $rows = $query->limit(51)->get(['id', 'name', 'category', 'specification', 'code']);

        return ['items' => $rows->take(50)->values(), 'next' => $rows->count() > 50 ? $rows[49]->id : null,
            'company' => $drawing->site->company?->name, 'canManage' => app(CadDrawingService::class)->canManage(auth()->user())];
    }

    public function find(CadDrawing $drawing, string $id, bool $active = true): CadLibraryPart
    {
        $this->access($drawing);

        return CadLibraryPart::where('company_id', $drawing->company_id)->when($active, fn ($q) => $q->where('archived', false))->findOrFail($id);
    }

    public function create(CadDrawing $drawing, array $data): array
    {
        $this->access($drawing, true);
        $geometry = app(CadPartGeometry::class)->validate($data['geometry']);
        $part = DB::transaction(function () use ($drawing, $data, $geometry) {
            // Serialize the catalog bound across editors of different sites in this company.
            DB::table('companies')->where('id', $drawing->company_id)->lockForUpdate()->first();
            abort_if(CadLibraryPart::where('company_id', $drawing->company_id)->where('archived', false)->count() >= 1000, 422, '회사 공용 부품은 최대 1,000개입니다.');

            return CadLibraryPart::create(['company_id' => $drawing->company_id, 'name' => trim($data['name']),
                'category' => $data['category'], 'specification' => trim($data['specification'] ?? ''), 'code' => trim($data['code'] ?? ''),
                'geometry' => $geometry, 'created_by' => auth()->id()]);
        });

        return $this->payload($part);
    }

    /** Definitions are immutable. Archiving hides future insertions and preserves existing drawings. */
    public function archive(CadDrawing $drawing, string $id): void
    {
        $this->access($drawing, true);
        $this->find($drawing, $id, false)->update(['archived' => true]);
    }

    public function canonicalize(CadDrawing $drawing, array $markups): array
    {
        $ids = collect($markups)->where('kind', 'part')->pluck('part.id')->unique()->all();
        if (! $ids) {
            return $markups;
        }
        $parts = CadLibraryPart::where('company_id', $drawing->company_id)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($markups as &$item) {
            if ($item['kind'] !== 'part') {
                continue;
            }
            $definition = $parts->get($item['part']['id']);
            if (! $definition) {
                throw ValidationException::withMessages(['markups' => '현재 회사의 공용 부품을 찾을 수 없습니다.']);
            }
            $canonical = $this->payload($definition);
            if ($item['part'] != $canonical) {
                throw ValidationException::withMessages(['markups' => '부품 정의가 공용 부품함과 다릅니다. 새 부품으로 저장한 뒤 사용하세요.']);
            }
            $item['part'] = $canonical;
        }

        return $markups;
    }

    public function quantities(CadDrawing $drawing, int $revision): array
    {
        $this->access($drawing);
        $row = $drawing->revisions()->where('revision', $revision)->firstOrFail();
        $groups = [];
        foreach ($row->markups ?? [] as $item) {
            if (($item['kind'] ?? '') !== 'part') {
                continue;
            }
            $part = $item['part'];
            $key = $part['id'];
            $groups[$key] ??= array_diff_key($part, ['geometry' => true]) + ['unit' => 'EA', 'quantity' => 0, 'placements' => []];
            $groups[$key]['quantity']++;
            $groups[$key]['placements'][] = ['id' => $item['id'], 'point' => $item['points'][0], 'layer' => $item['layer'] ?? null];
        }

        return ['drawingId' => $drawing->id, 'revision' => $revision, 'title' => $drawing->title,
            'basis' => '배치한 부품 기호 개수 · 숨긴 레이어 포함 · 실제 시공량/배관·덕트 길이와 별도', 'items' => array_values($groups)];
    }
}
