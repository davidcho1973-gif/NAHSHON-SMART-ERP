<?php

namespace App\Services\Admin;

use App\Models\ReportRecipient;
use App\Models\Site;
use App\Support\AccessPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * 일일 보고 수신처 관리 — "누가 무엇을 받는가" 를 현장별로 저장해 둔다.
 *
 * 보낼 때마다 주소를 손으로 치면 언젠가 한 사람이 빠지고, 빠진 줄도 모른다.
 * 여기에 한 번 등록하면 그 뒤로는 발송 버튼만 누른다.
 *
 * <b>이 표는 «상시 수신 권한» 그 자체다.</b> 여기 한 줄을 넣으면 그 주소로 매일 08:30
 * 작업계획서와 18:30 마감보고서가 현장 사진까지 붙어 자동으로 나간다. 사람이 다시
 * 승인하는 자리가 없다 — 그래서 등록 자체가 결재여야 하고, 아무나 못 넣어야 한다.
 * 처음에는 권한 검사가 한 줄도 없었다(열람 전용만 아니면 누구나 임의의 외부 주소를
 * 꽂을 수 있었다). 발송을 켜기 전에 막는다.
 */
class ReportRecipientService
{
    /** 수신처를 고칠 수 있는 역할 — 현장을 책임지는 사람들. */
    public const MANAGE_ROLES = AccessPolicy::SITE_ROLES;

    /**
     * <b>전 현장</b> 수신처(site_id 가 비어 있는 줄)는 더 좁힌다.
     *
     * 그 한 줄은 법인·현장을 가리지 않고 모든 보고를 받는다. 현장 소장이 자기 현장을
     * 넘어서는 범위를 혼자 정할 수 있으면 안 된다.
     */
    public const MANAGE_ALL_SITES_ROLES = AccessPolicy::SYSTEM_ROLES;

    private function canManage(): bool
    {
        return in_array((string) Auth::user()?->access_role, self::MANAGE_ROLES, true);
    }

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
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '수신처를 고칠 권한이 없습니다.'];
        }

        // 전 현장 수신처는 한 겹 더 좁힌다 — 그 줄은 모든 법인·현장의 보고를 받는다.
        $siteId = $input['siteId'] ?? null;
        if (blank($siteId) && ! in_array((string) Auth::user()?->access_role, self::MANAGE_ALL_SITES_ROLES, true)) {
            return ['success' => false, 'error' => '모든 현장이 받는 수신처는 시스템 관리자만 등록할 수 있습니다. 현장을 지정해 주세요.'];
        }

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
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '수신처를 고칠 권한이 없습니다.'];
        }

        $row = ReportRecipient::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '수신처를 찾을 수 없습니다.'];
        }

        // 전 현장 수신처를 지우는 것도 같은 무게다 — 지우면 원청이 조용히 못 받게 된다.
        if ($row->site_id === null && ! in_array((string) Auth::user()?->access_role, self::MANAGE_ALL_SITES_ROLES, true)) {
            return ['success' => false, 'error' => '모든 현장이 받는 수신처는 시스템 관리자만 지울 수 있습니다.'];
        }
        $name = $row->name;
        $row->delete();

        return ['success' => true, 'message' => "{$name} 을(를) 수신처에서 제외했습니다."];
    }
}
