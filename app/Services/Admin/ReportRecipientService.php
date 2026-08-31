<?php

namespace App\Services\Admin;

use App\Models\ReportRecipient;
use App\Models\Site;
use Illuminate\Support\Facades\Validator;

/**
 * 일일 보고 수신처 관리 — "누가 무엇을 받는가" 를 현장별로 저장해 둔다.
 *
 * 보낼 때마다 주소를 손으로 치면 언젠가 한 사람이 빠지고, 빠진 줄도 모른다.
 * 여기에 한 번 등록하면 그 뒤로는 발송 버튼만 누른다.
 */
class ReportRecipientService
{
    /**
     * @return array<string, mixed>
     */
    public function list(?int $siteId = null): array
    {
        $rows = ReportRecipient::query()
            ->with('site:id,name')
            ->when($siteId, fn ($q) => $q->where(function ($w) use ($siteId): void {
                $w->whereNull('site_id')->orWhere('site_id', $siteId);
            }))
            ->orderBy('site_id')->orderBy('is_cc')->orderBy('id')
            ->get();

        return [
            'success' => true,
            'rows' => $rows->map(fn (ReportRecipient $r): array => $r->toClientArray())->all(),
            'sites' => Site::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $s): array => ['id' => $s->id, 'name' => $s->name])->all(),
            'roles' => ReportRecipient::ROLE_LABELS,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input, ?int $userId = null): array
    {
        $v = Validator::make($input, [
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'org' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:20'],
            'siteId' => ['nullable', 'integer'],
            'receives' => ['nullable', 'array'],
            'isCc' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($v->fails()) {
            return ['success' => false, 'error' => $v->errors()->first()];
        }

        $data = $v->validated();

        // 받을 것을 하나도 안 고르면 등록해 봐야 아무것도 안 간다 — 미리 막는다.
        $receives = array_values(array_intersect(
            (array) ($data['receives'] ?? []),
            [ReportRecipient::PLAN, ReportRecipient::CLOSING],
        ));
        if ($receives === []) {
            return ['success' => false, 'error' => '받을 보고서를 하나 이상 선택해 주세요(작업계획서 / 작업보고서).'];
        }

        $attributes = [
            'site_id' => $data['siteId'] ?: null,
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'org' => $data['org'] ?? null,
            'role' => in_array($data['role'] ?? '', array_keys(ReportRecipient::ROLE_LABELS), true)
                ? $data['role'] : 'owner',
            'receives' => $receives,
            'is_cc' => (bool) ($data['isCc'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
        ];

        if (! empty($data['id'])) {
            $row = ReportRecipient::find($data['id']);
            if (! $row) {
                return ['success' => false, 'error' => '수신처를 찾을 수 없습니다.'];
            }
            $row->update($attributes);

            return ['success' => true, 'id' => $row->id, 'message' => '수신처를 수정했습니다.'];
        }

        // 같은 현장에 같은 주소를 두 번 넣으면 같은 메일이 두 통 간다.
        $dup = ReportRecipient::query()
            ->where('email', $attributes['email'])
            ->where('site_id', $attributes['site_id'])
            ->exists();
        if ($dup) {
            return ['success' => false, 'error' => '같은 현장에 이미 등록된 주소입니다.'];
        }

        $row = ReportRecipient::create($attributes + ['created_by_id' => $userId]);

        return ['success' => true, 'id' => $row->id, 'message' => '수신처를 추가했습니다.'];
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        $row = ReportRecipient::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '수신처를 찾을 수 없습니다.'];
        }
        $name = $row->name;
        $row->delete();

        return ['success' => true, 'message' => "{$name} 을(를) 수신처에서 제외했습니다."];
    }
}
