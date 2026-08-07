<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 계정 · 권한 관리 — 누가 무엇을 볼 수 있는지 정하는 화면의 뒷단.
 *
 * 이 화면은 권한을 나눠주는 곳이라 다른 화면보다 규칙이 엄격하다. 화면에서 선택지를
 * 숨기는 것은 방어가 아니므로(요청은 직접 만들어 보낼 수 있다) 모든 판단을 여기서 한다.
 *
 * 지키는 것 넷:
 *   1. 자기보다 높은 권한은 줄 수 없다      — 인사담당자가 스스로를 관리자로 올리는 길을 막는다
 *   2. 자기 권한·상태는 자기가 못 바꾼다     — 실수로 자기를 잠그고 아무도 못 들어가는 상황 방지
 *   3. 마지막 슈퍼관리자는 못 지운다         — 시스템을 관리할 사람이 0명이 되는 것을 막는다
 *   4. 원청·뷰어 계정은 이 화면 자체를 못 연다 — 남의 계정 목록은 열람 대상이 아니다
 */
class UserAccessService
{
    /** 이 화면을 열 수 있는 역할. */
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager'];

    /** 계정을 만들고 고칠 수 있는 역할. */
    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager'];

    /**
     * 지금 로그인한 사람이 부여할 수 있는 역할.
     *
     * 슈퍼관리자만 슈퍼관리자를 만들 수 있고, 관리자는 관리자까지, 인사담당자는 그 아래까지다.
     * 이게 없으면 인사담당자가 아무 계정이나 슈퍼관리자로 올린 뒤 그 계정으로 로그인할 수 있다.
     *
     * @return array<string, string>
     */
    public function assignableRoles(?User $actor = null): array
    {
        $actor ??= auth()->user();

        return match ($actor?->access_role) {
            'super_admin' => User::ROLE_OPTIONS,
            'admin' => array_diff_key(User::ROLE_OPTIONS, ['super_admin' => '']),
            default => array_diff_key(User::ROLE_OPTIONS, ['super_admin' => '', 'admin' => '']),
        };
    }

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
     * 목록. 역할·범위·상태는 코드가 아니라 사람이 읽는 이름으로 내려준다.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '계정 관리 권한이 없습니다.'];
        }

        $rows = User::query()
            ->with(['employee:id,name,employee_number', 'allowedCompany:id,name', 'allowedSite:id,code', 'allowedTeam:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'employeeId' => $u->employee_id,
                'employeeNumber' => $u->employee?->employee_number,
                'role' => $u->access_role,
                'roleLabel' => User::ROLE_LABELS_KO[$u->access_role] ?? (string) $u->access_role,
                'roleTier' => User::ROLE_TIERS[$u->access_role] ?? 'low',
                'scope' => $u->access_scope,
                'scopeLabel' => User::SCOPE_LABELS_KO[$u->access_scope] ?? (string) $u->access_scope,
                'status' => $u->account_status,
                'statusLabel' => User::STATUS_LABELS_KO[$u->account_status] ?? (string) $u->account_status,
                'companyId' => $u->allowed_company_id,
                'company' => $u->allowedCompany?->name,
                'siteId' => $u->allowed_site_id,
                'site' => $u->allowedSite?->code,
                'teamId' => $u->allowed_team_id,
                'team' => $u->allowedTeam?->name,
                'notes' => $u->access_notes,
                'hasGoogle' => filled($u->google_id),
                'lastLoginAt' => $u->last_login_at?->toDateTimeString(),
                // 자기 자신은 화면에서 역할·상태 손잡이를 잠근다(자물쇠 아이콘 표시용).
                'isSelf' => $u->id === auth()->id(),
            ])
            ->values()
            ->all();

        return ['success' => true, 'rows' => $rows];
    }

    /**
     * 폼에 필요한 선택지. 역할 목록은 "지금 로그인한 사람이 줄 수 있는 것" 만 담긴다.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '계정 관리 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        return [
            'success' => true,
            'roles' => $pairs(array_intersect_key(User::ROLE_LABELS_KO, $this->assignableRoles())),
            'scopes' => $pairs(User::SCOPE_LABELS_KO),
            'statuses' => $pairs(User::STATUS_LABELS_KO),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' — '.$s->name])->all(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Team $t): array => ['value' => (string) $t->id, 'label' => $t->name])->all(),
            'employees' => Employee::query()->orderBy('name')->get(['id', 'name', 'employee_number'])
                ->map(fn (Employee $e): array => [
                    'value' => (string) $e->id,
                    'label' => $e->name.($e->employee_number ? ' ('.$e->employee_number.')' : ''),
                ])->all(),
        ];
    }

    /**
     * 만들거나 고친다. 실패는 어느 칸이 문제인지 `errors` 로 돌려줘 화면이 그 칸 밑에 붙인다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '계정 관리 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? User::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '계정을 찾을 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $role = (string) ($input['role'] ?? 'worker');
        $scope = (string) ($input['scope'] ?? 'self');
        $status = (string) ($input['status'] ?? 'active');

        $errors = [];
        if ($name === '') {
            $errors['name'] = '이름을 입력하세요.';
        }
        if ($email === '') {
            $errors['email'] = '이메일을 입력하세요.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '이메일 형식이 올바르지 않습니다.';
        } elseif (User::query()->where('email', $email)->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
            $errors['email'] = '이미 등록된 이메일입니다.';
        }

        // 줄 수 없는 역할을 요청하면 조용히 낮추지 않고 거절한다 — 조용히 낮추면
        // 화면에는 저장됐다고 뜨는데 실제 권한은 다른 상태가 된다.
        if (! array_key_exists($role, $this->assignableRoles())) {
            $errors['role'] = '이 역할을 부여할 권한이 없습니다.';
        }
        if (! array_key_exists($scope, User::SCOPE_OPTIONS)) {
            $errors['scope'] = '올바른 범위를 선택하세요.';
        }
        if (! array_key_exists($status, User::STATUS_OPTIONS)) {
            $errors['status'] = '올바른 상태를 선택하세요.';
        }

        // 범위를 골랐으면 대상도 골라야 한다. "지정 현장" 인데 현장이 비면 아무것도 못 본다.
        $siteId = $this->intOrNull($input['siteId'] ?? null);
        $companyId = $this->intOrNull($input['companyId'] ?? null);
        $teamId = $this->intOrNull($input['teamId'] ?? null);
        if ($scope === 'site' && ! $siteId) {
            $errors['siteId'] = '범위가 "현장"이면 현장을 지정해야 합니다.';
        }
        if ($scope === 'company' && ! $companyId) {
            $errors['companyId'] = '범위가 "회사"면 회사를 지정해야 합니다.';
        }
        if ($scope === 'team' && ! $teamId) {
            $errors['teamId'] = '범위가 "팀"이면 팀을 지정해야 합니다.';
        }

        // 자기 계정의 역할·상태는 자기가 못 바꾼다. 이걸 허용하면 실수 한 번으로
        // 자기를 잠그고, 남은 관리자가 없으면 아무도 되돌릴 수 없다.
        if ($row && $row->id === auth()->id()) {
            if ($role !== $row->access_role) {
                $errors['role'] = '자기 계정의 역할은 바꿀 수 없습니다. 다른 관리자에게 요청하세요.';
            }
            if ($status !== $row->account_status) {
                $errors['status'] = '자기 계정의 상태는 바꿀 수 없습니다.';
            }
        }

        // 마지막 슈퍼관리자를 끌어내리면 시스템을 관리할 사람이 사라진다.
        if ($row && $row->access_role === 'super_admin' && ($role !== 'super_admin' || $status !== 'active')) {
            if ($this->activeSuperAdminCount($row->id) === 0) {
                $errors['role'] = '마지막 슈퍼관리자입니다. 다른 슈퍼관리자를 먼저 지정하세요.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $data = [
            'name' => mb_substr($name, 0, 255),
            'email' => $email,
            'access_role' => $role,
            'access_scope' => $scope,
            'account_status' => $status,
            'employee_id' => $this->intOrNull($input['employeeId'] ?? null),
            'allowed_company_id' => $companyId,
            'allowed_site_id' => $siteId,
            'allowed_team_id' => $teamId,
            'access_notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        if ($row) {
            $row->forceFill($data)->save();

            return ['success' => true, 'id' => $row->id];
        }

        // 새 계정은 구글 로그인으로 들어오므로 비밀번호를 쓰지 않는다. 다만 컬럼이
        // 비어 있으면 안 되므로 아무도 모르는 값을 넣어 둔다(로그인 경로가 아니다).
        $data['password'] = Hash::make(Str::random(48));

        $created = User::create($data);

        return ['success' => true, 'id' => $created->id];
    }

    /**
     * 목록에서 바로 누르는 활성/정지 토글.
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, string $status): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '계정 관리 권한이 없습니다.'];
        }
        if (! array_key_exists($status, User::STATUS_OPTIONS)) {
            return ['success' => false, 'error' => '올바른 상태가 아닙니다.'];
        }

        $row = User::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '계정을 찾을 수 없습니다.'];
        }
        if ($row->id === auth()->id()) {
            return ['success' => false, 'error' => '자기 계정의 상태는 바꿀 수 없습니다.'];
        }
        if ($row->access_role === 'super_admin' && $status !== 'active' && $this->activeSuperAdminCount($row->id) === 0) {
            return ['success' => false, 'error' => '마지막 슈퍼관리자입니다. 다른 슈퍼관리자를 먼저 지정하세요.'];
        }

        $row->forceFill(['account_status' => $status])->save();

        return ['success' => true, 'status' => $row->account_status];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        // 삭제는 관리자만 — 인사담당자는 만들고 고칠 수는 있어도 지울 수는 없다.
        $actor = auth()->user();
        if (! $actor || $actor->account_status !== 'active' || ! in_array($actor->access_role, ['super_admin', 'admin'], true)) {
            return ['success' => false, 'error' => '계정 삭제 권한이 없습니다.'];
        }

        $row = User::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '계정을 찾을 수 없습니다.'];
        }
        if ($row->id === $actor->id) {
            return ['success' => false, 'error' => '자기 계정은 삭제할 수 없습니다.'];
        }
        if ($row->access_role === 'super_admin' && $this->activeSuperAdminCount($row->id) === 0) {
            return ['success' => false, 'error' => '마지막 슈퍼관리자입니다. 다른 슈퍼관리자를 먼저 지정하세요.'];
        }
        // 슈퍼관리자는 슈퍼관리자만 지울 수 있다.
        if ($row->access_role === 'super_admin' && $actor->access_role !== 'super_admin') {
            return ['success' => false, 'error' => '슈퍼관리자 계정은 슈퍼관리자만 삭제할 수 있습니다.'];
        }

        $row->delete();

        return ['success' => true];
    }

    /** $exceptId 를 뺀 나머지 활성 슈퍼관리자 수. */
    private function activeSuperAdminCount(?int $exceptId = null): int
    {
        return User::query()
            ->where('access_role', 'super_admin')
            ->where('account_status', 'active')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->count();
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
