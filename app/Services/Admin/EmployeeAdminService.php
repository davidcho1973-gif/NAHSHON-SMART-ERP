<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkerLang;
use Illuminate\Support\Carbon;

/**
 * 직원 등록 · 수정 — Filament EmployeeResource 를 SPA 로 옮긴 것.
 *
 * 옮기면서 폼 순서를 다시 짰다. 예전 폼은 필드 23개가 구역 없이 한 줄로 늘어서 있었고,
 * 순서가 사번 → NFC → 배지사진 → 배지번호 → 이름 이었다. 사람을 등록하는데 배지부터
 * 물어보니 "등록 폼이 불편하다" 는 말이 나올 수밖에 없었다.
 *
 * 지금은 실제로 묻는 순서대로 다섯 구역이다:
 *   누구인가 → 어디 소속인가 → 출입증 → QR 출퇴근 → 자격 만료
 *
 * 사번은 비워도 된다(모델이 자동 발급). 이름도 성/이름만 넣으면 합쳐진다.
 */
class EmployeeAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll', 'safety_manager'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager'];

    /** 지우는 것은 관리자만 — 출퇴근·급여 기록이 이 사람에게 매달려 있다. */
    public const DELETE_ROLES = ['super_admin', 'admin'];

    /** 기존 데이터가 쓰는 다섯 값을 그대로 둔다 — 줄이면 이미 저장된 상태를 못 읽는다. */
    public const STATUSES = [
        'active' => '재직',
        'pending' => '입사 예정',
        'on_leave' => '휴직',
        'inactive' => '비활성',
        'terminated' => '퇴사',
    ];

    public const QR_ROLES = [
        'worker' => '작업자 (본인 출퇴근만)',
        'foreman' => '작업반장 (팀 출퇴근)',
        'safety_manager' => '안전관리자',
        'attendance_admin' => '출퇴근 관리자',
    ];

    public const QR_SCOPES = [
        'self' => '본인만',
        'team' => '소속 팀',
        'site' => '현장 전체',
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
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '직원 정보 조회 권한이 없습니다.'];
        }

        $query = Employee::query()
            ->with(['company:id,name', 'site:id,code', 'team:id,name', 'user:id,employee_id,access_role,account_status',
                'w9Form:id,employee_id,tin_last4,certified_at'])
            ->orderBy('name');
        $this->applyScope($query);

        $status = trim((string) ($filters['status'] ?? ''));
        if (array_key_exists($status, self::STATUSES)) {
            $query->where('employment_status', $status);
        }
        if ($siteId = $this->intOrNull($filters['siteId'] ?? null)) {
            $query->where('site_id', $siteId);
        }
        $type = trim((string) ($filters['employmentType'] ?? ''));
        if (array_key_exists($type, Employee::EMPLOYMENT_TYPES)) {
            $query->where('employment_type', $type);
        }

        $today = Carbon::now()->toDateString();
        $soon = Carbon::now()->addDays(30)->toDateString();

        $rows = $query->get()->map(function (Employee $e) use ($today, $soon): array {
            // 만료가 지났거나 30일 안에 닥친 것은 목록에서 바로 보여준다. 비자나 안전교육이
            // 끊긴 사람이 현장에 들어가는 것이 실제 사고로 이어진다.
            $expiring = [];
            foreach (['visa_expires_on' => '비자', 'safety_training_expires_on' => '안전교육'] as $f => $label) {
                $d = $e->{$f}?->toDateString();
                if (! $d) {
                    continue;
                }
                if ($d < $today) {
                    $expiring[] = ['label' => $label, 'date' => $d, 'state' => 'expired'];
                } elseif ($d <= $soon) {
                    $expiring[] = ['label' => $label, 'date' => $d, 'state' => 'soon'];
                }
            }

            return [
                'id' => $e->id,
                'employeeNumber' => $e->employee_number,
                'name' => $e->name,
                'firstName' => $e->first_name,
                'lastName' => $e->last_name,
                'email' => $e->email,
                'phone' => $e->phone,
                'nationality' => $e->nationality,
                'language' => $e->preferred_language,
                'languageLabel' => WorkerLang::OPTIONS[$e->preferred_language] ?? null,
                'companyId' => $e->company_id,
                'company' => $e->company?->name,
                'siteId' => $e->site_id,
                'site' => $e->site?->code,
                'teamId' => $e->team_id,
                'team' => $e->team?->name,
                'role' => $e->role,
                'startDate' => $e->start_date?->toDateString(),
                'status' => $e->employment_status,
                'statusLabel' => self::STATUSES[$e->employment_status] ?? (string) $e->employment_status,
                'employmentType' => $e->employment_type,
                'employmentTypeLabel' => Employee::EMPLOYMENT_TYPES[$e->employment_type] ?? (string) $e->employment_type,
                'badgeNumber' => $e->badge_number,
                'badgePrintedNumber' => $e->badge_printed_number,
                'badgeCompanyName' => $e->badge_company_name,
                'badgeIssuedOn' => $e->badge_issued_on?->toDateString(),
                'hasBadgePhoto' => filled($e->badge_photo_path),
                'qrRole' => $e->attendance_app_role,
                'qrScope' => $e->attendance_app_scope,
                // 로그인 계정이 있는지 — 없으면 이 사람은 앱에 못 들어온다.
                'hasAccount' => $e->user !== null,
                'accountRole' => $e->user?->access_role,
                'accountStatus' => $e->user?->account_status,
                'visaExpiresOn' => $e->visa_expires_on?->toDateString(),
                'safetyExpiresOn' => $e->safety_training_expires_on?->toDateString(),
                'expiring' => $expiring,
                // W-9 제출 여부 — 1099 지급 전제조건. TIN 은 뒤 4자리만 내려간다.
                'w9OnFile' => $e->w9Form !== null,
                'w9TinLast4' => $e->w9Form?->tin_last4,
                'w9CertifiedOn' => $e->w9Form?->certified_at?->toDateString(),
            ];
        })->values()->all();

        return ['success' => true, 'rows' => $rows, 'canManage' => $this->canManage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '직원 정보 조회 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        return [
            'success' => true,
            'statuses' => $pairs(self::STATUSES),
            'employmentTypes' => $pairs(Employee::EMPLOYMENT_TYPES),
            'qrRoles' => $pairs(self::QR_ROLES),
            'qrScopes' => $pairs(self::QR_SCOPES),
            'languages' => $pairs(WorkerLang::OPTIONS),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' — '.$s->name])->all(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Team $t): array => ['value' => (string) $t->id, 'label' => $t->name])->all(),
            // 계정 부여 폼에서 쓴다. 줄 수 있는 역할은 부여하는 사람의 등급에 달렸다.
            'accountRoles' => $pairs(array_intersect_key(
                User::ROLE_LABELS_KO,
                app(UserAccessService::class)->assignableRoles(),
            )),
            'accountScopes' => $pairs(User::SCOPE_OPTIONS),
            'canGrantAccount' => app(UserAccessService::class)->canManage(),
            // 남의 작업자 화면에는 그 사람의 시급과 급여가 그대로 나온다. 그래서 급여를
            // 이미 볼 수 있는 역할에만 연다(같은 상수를 쓴다 — 따로 적으면 어긋난다).
            'canViewAsWorker' => in_array(auth()->user()?->access_role, PayProfileService::VIEW_ROLES, true),
        ];
    }

    /**
     * 직원에게 로그인 계정을 만들어 준다.
     *
     * 이름과 이메일은 이미 직원 정보에 있다 — 계정 화면에서 다시 치게 하면 오타가 나고,
     * 오타가 나면 그 사람은 자기 계정으로 못 들어온다. 그래서 여기서 직원 정보를 그대로
     * 넘긴다. 역할 검증·중복 검사·마지막 슈퍼관리자 보호는 계정 서비스가 그대로 한다 —
     * 규칙을 두 군데 두면 언젠가 어긋난다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function grantAccount(int $employeeId, array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '직원 관리 권한이 없습니다.'];
        }

        $employee = Employee::find($employeeId);
        if (! $employee) {
            return ['success' => false, 'error' => '직원을 찾을 수 없습니다.'];
        }
        if (! $this->inScope($employee)) {
            return ['success' => false, 'error' => '다른 현장의 직원에게는 계정을 만들 수 없습니다.'];
        }
        if ($employee->user) {
            return ['success' => false, 'error' => '이 직원은 이미 로그인 계정이 있습니다. 계정 · 권한 관리에서 수정해 주세요.'];
        }

        $email = mb_strtolower(trim((string) ($input['email'] ?? $employee->email ?? '')));
        if ($email === '') {
            return ['success' => false, 'errors' => ['email' => '이메일을 입력하세요. 구글 로그인에 쓰는 주소입니다.']];
        }

        $scope = (string) ($input['scope'] ?? 'self');

        return app(UserAccessService::class)->save([
            'name' => $employee->name,
            'email' => $email,
            'role' => (string) ($input['role'] ?? 'worker'),
            'scope' => $scope,
            'status' => 'active',
            'employeeId' => $employee->id,
            // 범위 대상은 직원 정보에서 그대로 가져온다 — 같은 것을 또 고르게 하지 않는다.
            'companyId' => $employee->company_id,
            'siteId' => $employee->site_id,
            'teamId' => $employee->team_id,
            'notes' => $input['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '직원 등록 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? Employee::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '직원을 찾을 수 없습니다.'];
        }
        if ($row && ! $this->inScope($row)) {
            return ['success' => false, 'error' => '다른 현장의 직원은 수정할 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $first = trim((string) ($input['firstName'] ?? ''));
        $last = trim((string) ($input['lastName'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $number = trim((string) ($input['employeeNumber'] ?? ''));
        $badge = trim((string) ($input['badgeNumber'] ?? ''));

        $errors = [];

        // 이름은 전체 이름이든 성/이름이든 하나는 있어야 한다. 셋 다 비면 모델이
        // "Employee E-0001" 같은 이름을 만들어 버려서 나중에 누군지 알 수 없다.
        if ($name === '' && $first === '' && $last === '') {
            $errors['name'] = '이름을 입력하세요. 성·이름만 넣어도 됩니다.';
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '이메일 형식이 올바르지 않습니다.';
        }

        $companyId = $this->intOrNull($input['companyId'] ?? null);
        if (! $companyId) {
            $errors['companyId'] = '소속 회사를 선택하세요.';
        }

        $type = (string) ($input['employmentType'] ?? Employee::TYPE_DIRECT);
        if (! array_key_exists($type, Employee::EMPLOYMENT_TYPES)) {
            $errors['employmentType'] = '고용 형태를 선택하세요.';
        }

        $status = (string) ($input['status'] ?? 'active');
        if (! array_key_exists($status, self::STATUSES)) {
            $errors['status'] = '재직 상태를 선택하세요.';
        }

        // 사번과 NFC 는 사람을 특정하는 열쇠다. 겹치면 다른 사람의 출퇴근이 찍힌다.
        if ($number !== '' && Employee::query()->where('employee_number', $number)
            ->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
            $errors['employeeNumber'] = '이미 쓰이는 사번입니다.';
        }
        if ($badge !== '' && Employee::query()->where('badge_number', $badge)
            ->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
            $errors['badgeNumber'] = '이미 쓰이는 NFC ID 입니다. 다른 사람의 출퇴근이 찍힐 수 있습니다.';
        }

        $qrRole = (string) ($input['qrRole'] ?? 'worker');
        $qrScope = (string) ($input['qrScope'] ?? 'self');
        if (! array_key_exists($qrRole, self::QR_ROLES)) {
            $qrRole = 'worker';
        }
        if (! array_key_exists($qrScope, self::QR_SCOPES)) {
            $qrScope = 'self';
        }
        // 본인 출퇴근만 찍는 작업자에게 현장 전체 범위를 주면 남의 출퇴근을 대신 찍을 수 있다.
        if ($qrRole === 'worker' && $qrScope !== 'self') {
            $errors['qrScope'] = '작업자 역할은 본인만 찍을 수 있습니다. 팀·현장을 찍으려면 역할을 올리세요.';
        }

        $siteId = $this->intOrNull($input['siteId'] ?? null);
        if (! $this->siteAllowed($siteId)) {
            $errors['siteId'] = '담당하지 않는 현장에는 배정할 수 없습니다.';
        }

        foreach (['startDate' => 'start_date', 'badgeIssuedOn' => 'badge_issued_on',
            'visaExpiresOn' => 'visa_expires_on', 'safetyExpiresOn' => 'safety_training_expires_on'] as $in => $col) {
            $v = trim((string) ($input[$in] ?? ''));
            if ($v !== '') {
                try {
                    Carbon::parse($v);
                } catch (\Throwable) {
                    $errors[$in] = '날짜 형식이 올바르지 않습니다.';
                }
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $date = fn (string $k): ?string => trim((string) ($input[$k] ?? '')) !== ''
            ? Carbon::parse($input[$k])->toDateString() : null;

        $data = [
            'name' => $name ?: trim($first.' '.$last),
            'first_name' => $first ?: null,
            'last_name' => $last ?: null,
            'email' => $email ?: null,
            // 앱 링크를 문자·왓츠앱으로 바로 보낼 때 쓴다. 여기 없으면 반장이 매번
            // 받는 사람을 손으로 고른다 — 등록 폼이 이미 받은 값을 또 묻는 셈이다.
            'phone' => trim((string) ($input['phone'] ?? '')) ?: null,
            'nationality' => trim((string) ($input['nationality'] ?? '')) ?: null,
            'preferred_language' => WorkerLang::resolve($input['language'] ?? null),
            'company_id' => $companyId,
            'site_id' => $siteId,
            'team_id' => $this->intOrNull($input['teamId'] ?? null),
            'role' => trim((string) ($input['role'] ?? '')) ?: null,
            'employment_type' => $type,
            'employment_status' => $status,
            'start_date' => $date('startDate'),
            'employee_number' => $number ?: ($row?->employee_number),
            'badge_number' => $badge ?: null,
            'badge_printed_number' => trim((string) ($input['badgePrintedNumber'] ?? '')) ?: null,
            'badge_company_name' => trim((string) ($input['badgeCompanyName'] ?? '')) ?: null,
            'badge_issued_on' => $date('badgeIssuedOn'),
            'attendance_app_role' => $qrRole,
            'attendance_app_scope' => $qrScope,
            'visa_expires_on' => $date('visaExpiresOn'),
            'safety_training_expires_on' => $date('safetyExpiresOn'),
        ];

        if ($row) {
            $row->update($data);

            return ['success' => true, 'id' => $row->id];
        }

        $created = Employee::create($data);

        // 사번은 모델이 자동 발급하므로 새로 만든 뒤에야 알 수 있다 — 화면에 알려준다.
        return ['success' => true, 'id' => $created->id, 'employeeNumber' => $created->employee_number];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $actor = auth()->user();
        if (! $actor || $actor->account_status !== 'active' || ! in_array($actor->access_role, self::DELETE_ROLES, true)) {
            return ['success' => false, 'error' => '직원 삭제 권한이 없습니다. 퇴사자는 상태를 "퇴사" 로 두세요.'];
        }

        $row = Employee::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '직원을 찾을 수 없습니다.'];
        }

        // 출퇴근 기록이 남아 있으면 지우지 않는다. 지우면 그 사람의 근무 사실과
        // 급여 근거가 함께 사라진다.
        $logs = $row->attendanceLogs()->count();
        if ($logs > 0) {
            return ['success' => false, 'error' => "출퇴근 기록이 {$logs}건 있습니다. 삭제 대신 상태를 \"퇴사\" 로 두세요."];
        }

        $row->delete();

        return ['success' => true];
    }

    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)
            || $user->access_scope === 'all_sites') {
            return;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            $query->where('company_id', $user->allowed_company_id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function inScope(Employee $row): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager'], true)
            || $user->access_scope === 'all_sites') {
            return true;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            return (int) $row->site_id === (int) $user->allowed_site_id;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            return (int) $row->company_id === (int) $user->allowed_company_id;
        }

        return false;
    }

    /** 현장 범위 계정이 남의 현장으로 배정하는 것을 막는다. */
    private function siteAllowed(?int $siteId): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager'], true)
            || $user->access_scope === 'all_sites') {
            return true;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            return $siteId === null || (int) $siteId === (int) $user->allowed_site_id;
        }

        return true;
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
