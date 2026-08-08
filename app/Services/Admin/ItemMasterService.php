<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * 품목 · 분류 마스터 — Filament ItemResource / ItemCategoryResource 를 SPA 로 옮긴 것.
 *
 * 자재를 세는 단위를 정하는 곳이다. 여기가 흔들리면 재고도 발주도 같이 흔들리므로,
 * 이름은 자유롭게 고치되 "쓰이고 있는 것" 은 지우지 못하게 한다.
 *
 * company_id 가 비면 전사 공통 품목이다. 회사 범위 계정은 자기 회사 것 + 전사 공통을 본다.
 */
class ItemMasterService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'payroll', 'site_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'payroll'];

    public const STATUSES = [
        'active' => '사용',
        'inactive' => '미사용',
    ];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * 품목과 분류를 한 번에 내려준다 — 화면이 두 탭이라 왕복을 두 번 할 이유가 없다.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '품목 관리 권한이 없습니다.'];
        }

        $cats = ItemCategory::query()->with(['parent:id,name', 'company:id,name'])
            ->orderBy('sort')->orderBy('name');
        $this->applyScope($cats);

        $categories = $cats->get()->map(fn (ItemCategory $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'parentId' => $c->parent_id,
            'parent' => $c->parent?->name,
            'sort' => $c->sort,
            'status' => $c->status,
            'statusLabel' => self::STATUSES[$c->status] ?? (string) $c->status,
            'companyId' => $c->company_id,
            'company' => $c->company?->name,
            'isShared' => $c->company_id === null,
        ])->values()->all();

        $its = Item::query()->with(['category:id,name', 'company:id,name'])->orderBy('name');
        $this->applyScope($its);

        // 어느 분류에 몇 개가 매달려 있는지 — 분류를 지울 수 있는지 판단하는 근거다.
        $usage = Item::query()->selectRaw('item_category_id, count(*) as n')
            ->groupBy('item_category_id')->pluck('n', 'item_category_id');

        $items = $its->get()->map(fn (Item $i): array => [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'categoryId' => $i->item_category_id,
            'category' => $i->category?->name,
            'unit' => $i->unit,
            'standardCost' => $i->standard_cost !== null ? (float) $i->standard_cost : null,
            'status' => $i->status,
            'statusLabel' => self::STATUSES[$i->status] ?? (string) $i->status,
            'description' => $i->description,
            'companyId' => $i->company_id,
            'company' => $i->company?->name,
            'isShared' => $i->company_id === null,
        ])->values()->all();

        foreach ($categories as &$c) {
            $c['itemCount'] = (int) ($usage[$c['id']] ?? 0);
            $c['childCount'] = count(array_filter($categories, fn ($x) => $x['parentId'] === $c['id']));
        }
        unset($c);

        return ['success' => true, 'items' => $items, 'categories' => $categories, 'canManage' => $this->canManage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '품목 관리 권한이 없습니다.'];
        }

        $cats = ItemCategory::query()->where('status', 'active')->orderBy('sort')->orderBy('name');
        $this->applyScope($cats);

        return [
            'success' => true,
            'statuses' => array_map(
                fn ($k, $v): array => ['value' => $k, 'label' => $v],
                array_keys(self::STATUSES),
                array_values(self::STATUSES),
            ),
            'categories' => $cats->get(['id', 'name'])
                ->map(fn (ItemCategory $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveItem(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '품목 관리 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? Item::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '품목을 찾을 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $code = trim((string) ($input['code'] ?? ''));
        $status = (string) ($input['status'] ?? 'active');
        $companyId = $this->resolveCompanyId($input['companyId'] ?? null);

        $errors = [];
        if ($name === '') {
            $errors['name'] = '품목명을 입력하세요.';
        }
        if (! array_key_exists($status, self::STATUSES)) {
            $errors['status'] = '상태를 선택하세요.';
        }

        // SKU 는 같은 회사 안에서만 유일하면 된다. 전사 공통(null)끼리도 겹치면 안 된다.
        if ($code !== '') {
            $dup = Item::query()->where('code', $code)
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
                ->when($row, fn ($q) => $q->whereKeyNot($row->id))
                ->exists();
            if ($dup) {
                $errors['code'] = '같은 회사에 이미 있는 코드입니다.';
            }
        }

        $cost = trim((string) ($input['standardCost'] ?? ''));
        if ($cost !== '' && ! is_numeric($cost)) {
            $errors['standardCost'] = '숫자만 입력하세요.';
        } elseif ($cost !== '' && (float) $cost < 0) {
            $errors['standardCost'] = '단가는 0보다 작을 수 없습니다.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $data = [
            'company_id' => $companyId,
            'item_category_id' => $this->intOrNull($input['categoryId'] ?? null),
            'code' => $code ?: null,
            'name' => mb_substr($name, 0, 255),
            // 세는 단위가 없는 품목은 재고를 셀 수 없다. 비우면 개수(EA)로 둔다.
            'unit' => trim((string) ($input['unit'] ?? '')) ?: 'EA',
            'standard_cost' => $cost !== '' ? (float) $cost : null,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'status' => $status,
        ];

        if ($row) {
            $row->update($data);

            return ['success' => true, 'id' => $row->id];
        }

        return ['success' => true, 'id' => Item::create($data)->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveCategory(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '분류 관리 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? ItemCategory::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '분류를 찾을 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $status = (string) ($input['status'] ?? 'active');
        $parentId = $this->intOrNull($input['parentId'] ?? null);

        $errors = [];
        if ($name === '') {
            $errors['name'] = '분류명을 입력하세요.';
        }
        if (! array_key_exists($status, self::STATUSES)) {
            $errors['status'] = '상태를 선택하세요.';
        }

        // 자기 자신이나 자기 하위를 상위로 삼으면 트리가 고리가 되어 화면이 무한히 돈다.
        if ($row && $parentId) {
            if ($parentId === $row->id) {
                $errors['parentId'] = '자기 자신을 상위 분류로 지정할 수 없습니다.';
            } elseif ($this->isDescendant($parentId, $row->id)) {
                $errors['parentId'] = '하위 분류를 상위로 지정할 수 없습니다.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $data = [
            'company_id' => $this->resolveCompanyId($input['companyId'] ?? null),
            'parent_id' => $parentId,
            'name' => mb_substr($name, 0, 255),
            'code' => trim((string) ($input['code'] ?? '')) ?: null,
            'sort' => (int) ($input['sort'] ?? 0),
            'status' => $status,
        ];

        if ($row) {
            $row->update($data);

            return ['success' => true, 'id' => $row->id];
        }

        return ['success' => true, 'id' => ItemCategory::create($data)->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteItem(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '품목 관리 권한이 없습니다.'];
        }

        $row = Item::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '품목을 찾을 수 없습니다.'];
        }
        $row->delete();

        return ['success' => true];
    }

    /**
     * 분류 삭제. 쓰이고 있으면 막는다 — 지우면 품목의 분류가 통째로 비어버린다.
     *
     * @return array<string, mixed>
     */
    public function deleteCategory(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '분류 관리 권한이 없습니다.'];
        }

        $row = ItemCategory::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '분류를 찾을 수 없습니다.'];
        }

        $items = Item::query()->where('item_category_id', $id)->count();
        if ($items > 0) {
            return ['success' => false, 'error' => "이 분류를 쓰는 품목이 {$items}개 있습니다. 품목을 옮긴 뒤 지우거나, 상태를 \"미사용\" 으로 두세요."];
        }

        $children = ItemCategory::query()->where('parent_id', $id)->count();
        if ($children > 0) {
            return ['success' => false, 'error' => "하위 분류가 {$children}개 있습니다. 먼저 정리하세요."];
        }

        $row->delete();

        return ['success' => true];
    }

    /** $candidate 가 $ofId 의 하위(자손)인가. */
    private function isDescendant(int $candidate, int $ofId): bool
    {
        $seen = [];
        $cur = ItemCategory::find($candidate);
        while ($cur && $cur->parent_id && ! in_array($cur->parent_id, $seen, true)) {
            if ((int) $cur->parent_id === $ofId) {
                return true;
            }
            $seen[] = $cur->parent_id;
            $cur = ItemCategory::find($cur->parent_id);
        }

        return false;
    }

    /**
     * 회사 범위 계정은 자기 회사 것 + 전사 공통(null)만 본다.
     */
    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return;
        }

        $companyId = CurrentCompany::id() ?? ($user->allowed_company_id ?: $user->employee?->company_id);
        if ($companyId) {
            $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));

            return;
        }

        $query->whereNull('company_id');
    }

    private function resolveCompanyId(mixed $v): ?int
    {
        $id = $this->intOrNull($v);
        if ($id !== null) {
            return $id;
        }

        // 회사를 안 고르면 "전사 공통" 이다 — 특권 계정만 그렇게 만들 수 있고,
        // 회사 범위 계정은 자기 회사로 묶인다(남의 회사 목록을 오염시키지 못하게).
        $user = auth()->user();
        if ($user && (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites')) {
            return null;
        }

        return CurrentCompany::id() ?? ($user?->allowed_company_id ?: $user?->employee?->company_id);
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
