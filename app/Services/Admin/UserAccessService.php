<?php

namespace App\Services\Admin;

use App\Http\Middleware\RequireApprovedErpAccess;
use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\ManagerInvitationService;
use App\Support\JobAccess;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
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
    public function canManagePurchasingGrants(): bool
    {
        return auth()->user()?->account_status === 'active'
            && auth()->user()?->access_role === 'super_admin'
            && request()->hasSession()
            && ! WorkerDeviceSession::isDeviceOnly(request())
            && EmailPasswordAuthService::hasStrongAuthentication(request(), auth()->user());
    }

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
        if (JobAccess::managed($actor) && $actor->access_role !== 'super_admin') {
            return false;
        }

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();
        if (JobAccess::managed($actor) && $actor->access_role !== 'super_admin') {
            return false;
        }

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

        $pendingInvitations = ManagerInvitation::whereNotNull('user_id')->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->pluck('user_id')->flip();
        $rows = User::query()
            ->with(['employee:id,name,employee_number,phone,employment_status,company_id,site_id,team_id', 'allowedCompany:id,name', 'allowedSite:id,code', 'allowedTeam:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'employeeId' => $u->employee_id,
                'employeeNumber' => $u->employee?->employee_number,
                'role' => $u->access_role,
                'roleLabel' => JobAccess::label($u),
                'jobRole' => $u->job_role, 'jobTrade' => $u->job_trade, 'jobDuties' => $u->job_duties, 'jobPermissions' => $u->job_permissions, 'siteIds' => $u->job_site_ids,
                'roleTier' => User::ROLE_TIERS[$u->access_role] ?? 'low',
                'scope' => $u->access_scope,
                'scopeLabel' => User::SCOPE_LABELS_KO[$u->access_scope] ?? (string) $u->access_scope,
                'status' => $u->account_status,
                'statusLabel' => User::STATUS_LABELS_KO[$u->account_status] ?? (string) $u->account_status,
                'companyId' => $u->allowed_company_id ?: $u->employee?->company_id,
                'company' => $u->allowedCompany?->name,
                'siteId' => $u->allowed_site_id ?: $u->employee?->site_id,
                'site' => $u->allowedSite?->code,
                'teamId' => $u->allowed_team_id,
                'team' => $u->allowedTeam?->name,
                'notes' => $u->access_notes,
                // 이 계정이 ERP 본화면에 들어갈 수 있는가 — 목록에서 한눈에 보여야 한다.
                //
                // 2026-09-23 부터 현장 인력은 전화번호 뒷 4자리로 작업자 앱에 들어오고,
                // ERP 본화면은 «승인된 역할» 에게만 열린다(RequireApprovedErpAccess).
                // 그 판정을 화면이 따로 계산하면 두 벌이 되어 언젠가 어긋난다 — 여기서 내려준다.
                'erpAccess' => in_array($u->access_role, RequireApprovedErpAccess::ERP_ROLES, true),
                'hasGoogle' => filled($u->google_id),
                'lastLoginAt' => $u->last_login_at?->toDateTimeString(),
                // 자기 자신은 화면에서 역할·상태 손잡이를 잠근다(자물쇠 아이콘 표시용).
                'isSelf' => $u->id === auth()->id(),
                'canInvite' => app(ManagerInvitationService::class)->eligible($u),
                'invitationPending' => $pendingInvitations->has($u->id),
                'purchaseRequestAccess' => (bool) $u->purchase_request_enabled,
                'purchaseBuyerAccess' => (bool) $u->purchase_buy_enabled,
            ])
            ->values()
            ->all();

        $newInvitations = app(ManagerInvitationService::class)->canIssue()
            ? ManagerInvitation::where('kind', 'new_employee')->whereNull('accepted_at')->whereNull('revoked_at')
                ->orderByDesc('id')->get()->map(fn (ManagerInvitation $invite): array => [
                    'id' => $invite->id, 'label' => $invite->recipient_label ?: '신규 관리자 초대 #'.$invite->id,
                    'roleLabel' => isset($invite->grant['job_role']) ? config('job_access.jobs.'.$invite->grant['job_role'].'.label') : User::ROLE_LABELS_KO[$invite->grant['access_role']],
                    'scopeLabel' => User::SCOPE_LABELS_KO[$invite->grant['access_scope']],
                    'grant' => $invite->grant, 'enrollment' => $invite->enrollment,
                    'expiresAt' => $invite->expires_at->toDateTimeString(), 'expired' => $invite->expires_at->isPast(),
                ])->all() : [];

        return ['success' => true, 'rows' => $rows, 'newInvitations' => $newInvitations];
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
            'canManagePurchasingGrants' => $this->canManagePurchasingGrants(),
            'canIssueInvitations' => app(ManagerInvitationService::class)->canIssue(),
            'jobCatalog' => $this->canManagePurchasingGrants() ? JobAccess::catalog() : null,
            'purchasingReauthenticationRequired' => auth()->user()?->access_role === 'super_admin'
                && ! $this->canManagePurchasingGrants(),
            'purchasingRoles' => PurchaseAccess::ELIGIBLE_ROLES,
            'scopes' => $pairs(User::SCOPE_LABELS_KO),
            'statuses' => $pairs(User::STATUS_LABELS_KO),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name', 'company_id'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'companyId' => $s->company_id, 'label' => $s->code.' — '.$s->name])->all(),
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
        if ($row && ! array_key_exists($row->access_role, $this->assignableRoles())) {
            return ['success' => false, 'error' => '상위 권한 계정은 수정할 수 없습니다.'];
        }

        if ($row && JobAccess::managed($row) && ! $this->canManagePurchasingGrants()) {
            return ['success' => false, 'error' => '직책별 권한 계정은 슈퍼관리자만 변경할 수 있습니다.'];
        }
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $role = (string) ($input['role'] ?? 'worker');
        $scope = (string) ($input['scope'] ?? 'self');
        $status = (string) ($input['status'] ?? 'active');

        $errors = [];
        // A new superadmin inherits purchasing and delegation authority without explicit flags.
        if ($role === 'super_admin' && (! $row || $row->access_role !== 'super_admin')
            && ! $this->canManagePurchasingGrants()) {
            $errors['role'] = '수퍼관리자 부여는 비밀번호 또는 Google로 다시 로그인한 뒤 진행하세요.';
        }
        $grantFields = ['purchaseRequestAccess' => 'purchase_request_enabled', 'purchaseBuyerAccess' => 'purchase_buy_enabled'];
        foreach ($grantFields as $inputKey => $column) {
            if (array_key_exists($inputKey, $input)) {
                if (! $this->canManagePurchasingGrants()) {
                    $errors[$inputKey] = '구매 권한은 정식 로그인한 수퍼관리자만 변경할 수 있습니다.';
                } elseif (! in_array($input[$inputKey], [true, false, 0, 1, '0', '1'], true)) {
                    $errors[$inputKey] = '권한 설정값이 올바르지 않습니다.';
                } elseif (filter_var($input[$inputKey], FILTER_VALIDATE_BOOLEAN) && ! in_array($role, PurchaseAccess::ELIGIBLE_ROLES, true)) {
                    $errors[$inputKey] = '관리자 계정에만 구매 권한을 부여할 수 있습니다.';
                }
            }
        }
        $employeeId = $this->intOrNull($input['employeeId'] ?? null);
        if ($employeeId && ! Employee::whereKey($employeeId)->exists()) {
            $errors['employeeId'] = '존재하는 직원을 선택하세요.';
        } elseif ($employeeId && User::where('employee_id', $employeeId)->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
            $errors['employeeId'] = '이미 다른 로그인 계정에 연결된 직원입니다.';
        }
        if ($name === '') {
            $errors['name'] = '이름을 입력하세요.';
        }
        $phoneAccount = in_array($role, ['worker', 'foreman'], true)
            && Employee::whereKey($this->intOrNull($input['employeeId'] ?? null))->whereNotNull('phone')->exists();
        if ($email === '' && ! $phoneAccount) {
            $errors['email'] = '이메일을 입력하세요.';
        } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '이메일 형식이 올바르지 않습니다.';
        } elseif ($email !== '' && User::query()->where('email', $email)->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
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
            'email' => $email ?: null,
            'access_role' => $role,
            'access_scope' => $scope,
            'account_status' => $status,
            'employee_id' => $this->intOrNull($input['employeeId'] ?? null),
            'allowed_company_id' => $companyId,
            'allowed_site_id' => $siteId,
            'allowed_team_id' => $teamId,
            'access_notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        // A lower administrator must not take over or expand an explicitly granted account.
        if ($row && ($row->access_role === 'super_admin' || $row->purchase_request_enabled || $row->purchase_buy_enabled) && ! $this->canManagePurchasingGrants()) {
            if ($status === 'active' && $row->account_status !== 'active') {
                return ['success' => false, 'error' => '구매 권한 계정의 재활성화는 수퍼관리자에게 요청하세요.'];
            }
            foreach (['email', 'employee_id', 'access_role', 'access_scope', 'allowed_company_id', 'allowed_site_id', 'allowed_team_id'] as $key) {
                if ((string) $data[$key] !== (string) $row->{$key}) {
                    return ['success' => false, 'error' => '구매 권한 계정의 신원·역할·범위 변경은 수퍼관리자에게 요청하세요.'];
                }
            }
        }
        foreach ($grantFields as $inputKey => $column) {
            if ($this->canManagePurchasingGrants() && array_key_exists($inputKey, $input)) {
                $data[$column] = filter_var($input[$inputKey], FILTER_VALIDATE_BOOLEAN);
            }
            if (! in_array($role, PurchaseAccess::ELIGIBLE_ROLES, true)) {
                $data[$column] = false;
            }
        }

        if ($row) {
            $grantBefore = [(bool) $row->purchase_request_enabled, (bool) $row->purchase_buy_enabled];
            $row->forceFill($data)->save();
            if ($grantBefore !== [(bool) $row->purchase_request_enabled, (bool) $row->purchase_buy_enabled]) {
                AuthEvent::record('purchase_permissions_changed', user: $row, actor: auth()->user(),
                    method: 'erp', request: request(), note: json_encode(['before' => $grantBefore, 'after' => [(bool) $row->purchase_request_enabled, (bool) $row->purchase_buy_enabled]]));
            }

            return ['success' => true, 'id' => $row->id];
        }

        // Only a user-chosen password (password_set_at) is accepted for email login.
        // Until setup, keep an unknown placeholder rather than storing phone digits.
        $data['password'] = Hash::make(Str::random(48));

        $created = new User;
        $created->forceFill($data)->save();

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
        if (! array_key_exists($row->access_role, $this->assignableRoles())) {
            return ['success' => false, 'error' => '상위 권한 계정의 상태는 변경할 수 없습니다.'];
        }
        if (($row->access_role === 'super_admin' || JobAccess::managed($row) || $row->purchase_request_enabled || $row->purchase_buy_enabled) && $status === 'active'
            && $row->account_status !== 'active' && ! $this->canManagePurchasingGrants()) {
            return ['success' => false, 'error' => '구매 권한 계정의 재활성화는 수퍼관리자에게 요청하세요.'];
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
        if (JobAccess::managed(auth()->user()) && ! JobAccess::can(auth()->user(), 'system', 'delete')) {
            return ['success' => false, 'error' => '삭제 권한이 없습니다.'];
        }
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
        if ((JobAccess::managed($row) || $row->purchase_request_enabled || $row->purchase_buy_enabled) && ! $this->canManagePurchasingGrants()) {
            return ['success' => false, 'error' => '구매 권한 계정은 수퍼관리자만 삭제할 수 있습니다.'];
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
