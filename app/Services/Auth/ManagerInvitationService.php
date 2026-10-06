<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ManagerInvitation;
use App\Models\Site;
use App\Models\User;
use App\Support\QrSvg;
use App\Support\WorkerDeviceSession;
use App\Support\WorkerPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagerInvitationService
{
    public const SESSION = 'manager_invitation';

    public function canIssue(): bool
    {
        $user = auth()->user();

        return $user?->account_status === 'active' && $user->access_role === 'super_admin'
            && request()->hasSession() && ! WorkerDeviceSession::isDeviceOnly(request())
            && EmailPasswordAuthService::hasStrongAuthentication(request(), $user);
    }

    // The existing worker is the identity; self-enrollment must never create a second employee.
    public function eligible(User $user): bool
    {
        return $user->account_status === 'active' && in_array($user->access_role, ['worker', 'foreman'], true)
            && $user->employee?->employment_status === 'active' && WorkerPhone::normalize((string) $user->employee->phone) !== null && blank($user->email)
            && blank($user->google_id) && ! $user->password_set_at
            && ! $user->purchase_request_enabled && ! $user->purchase_buy_enabled;
    }

    public function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([$user->employee_id, $user->email, $user->google_id,
            $user->password, $user->access_role, $user->access_scope, $user->account_status,
            $user->allowed_site_id, $user->allowed_company_id, $user->allowed_team_id,
            $user->purchase_request_enabled, $user->purchase_buy_enabled, $user->employee?->phone, $user->employee?->employment_status]));
    }

    public function issue(array $input): array
    {
        if (! $this->canIssue()) {
            return ['success' => false, 'error' => '정식 로그인한 수퍼관리자만 초대할 수 있습니다.'];
        }
        $kind = $input['kind'] ?? 'existing_worker';
        if (! in_array($kind, ['existing_worker', 'new_employee'], true)) {
            return ['success' => false, 'error' => '초대 종류를 확인하세요.'];
        }
        $role = $input['role'] ?? '';
        $scope = $input['scope'] ?? '';
        if (! in_array($role, ['admin', 'site_manager'], true) || ! in_array($scope, ['site', 'company', 'all_sites'], true)) {
            return ['success' => false, 'error' => '관리자 역할과 관리 범위를 선택하세요.'];
        }
        if ($role === 'admin' && $scope !== 'all_sites') {
            return ['success' => false, 'error' => '관리자는 전체 현장 권한입니다. 특정 현장만 맡기려면 현장관리자를 선택하세요.'];
        }
        $site = $scope === 'site' ? Site::find((int) ($input['siteId'] ?? 0)) : null;
        $company = $scope === 'company' ? Company::find((int) ($input['companyId'] ?? 0)) : null;
        if (($scope === 'site' && ! $site) || ($scope === 'company' && ! $company)) {
            return ['success' => false, 'error' => '담당 현장 또는 회사를 선택하세요.'];
        }
        $enrollment = null;
        $label = trim((string) ($input['recipientLabel'] ?? ''));
        if ($kind === 'new_employee') {
            $homeSite = filled($input['siteId'] ?? null) ? Site::find((int) $input['siteId']) : null;
            $homeCompany = filled($input['companyId'] ?? null) ? Company::find((int) $input['companyId']) : null;
            if (mb_strlen($label) > 100 || (filled($input['siteId'] ?? null) && ! $homeSite)
                || (filled($input['companyId'] ?? null) && ! $homeCompany)) {
                return ['success' => false, 'error' => '초대 메모와 소속 현장·회사를 확인하세요.'];
            }
            $enrollment = ['site_id' => $homeSite?->id, 'company_id' => $homeCompany?->id ?? $homeSite?->company_id];
        }

        return DB::transaction(function () use ($input, $kind, $label, $enrollment, $role, $scope, $site, $company) {
            $user = $kind === 'existing_worker' ? User::query()->lockForUpdate()->find((int) ($input['id'] ?? 0)) : null;
            if ($kind === 'existing_worker' && (! $user || ! $this->eligible($user))) {
                return ['success' => false, 'error' => '이메일·로그인 정보가 없는 활성 작업자 또는 반장만 초대할 수 있습니다. 기존 관리자 계정은 계정 수정으로 관리하세요.'];
            }
            if ($user) {
                ManagerInvitation::where('user_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            } elseif (filled($input['replaceInvitationId'] ?? null)) {
                $previous = ManagerInvitation::query()->lockForUpdate()->find((int) $input['replaceInvitationId']);
                if (! $previous || $previous->kind !== 'new_employee' || $previous->accepted_at) {
                    return ['success' => false, 'error' => '완료된 초대는 재발급할 수 없습니다.'];
                }
                $previous->update(['revoked_at' => now()]);
            }
            $token = Str::random(64);
            $invite = ManagerInvitation::create([
                'user_id' => $user?->id, 'created_by_id' => auth()->id(), 'token_hash' => hash('sha256', $token),
                'kind' => $kind, 'enrollment' => $enrollment, 'recipient_label' => $label ?: null,
                'account_fingerprint' => $user ? $this->fingerprint($user) : hash('sha256', 'new_employee'), 'expires_at' => now()->addDays(7),
                'grant' => ['access_role' => $role, 'access_scope' => $scope, 'allowed_site_id' => $site?->id,
                    'allowed_company_id' => $company?->id, 'allowed_team_id' => null],
            ]);
            AuthEvent::record('manager_invitation_created', user: $user, actor: auth()->user(), method: 'erp', request: request(), note: 'invitation_id='.$invite->id);
            // Alias hosts do not share the canonical Google callback's session cookie.
            $url = rtrim((string) config('app.url'), '/').route('manager-invitation.show', ['token' => $token], absolute: false);

            return ['success' => true, 'id' => $invite->id, 'url' => $url, 'qr' => QrSvg::dataUri($url),
                'expiresAt' => $invite->expires_at->toDateTimeString(), 'name' => $user?->name ?? ($label ?: '새 입사자'), 'kind' => $kind];
        });
    }

    public function revoke(int $userId): array
    {
        if (! $this->canIssue()) {
            return ['success' => false, 'error' => '정식 로그인한 수퍼관리자만 초대를 취소할 수 있습니다.'];
        }

        return DB::transaction(function () use ($userId) {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            ManagerInvitation::where('user_id', $userId)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            AuthEvent::record('manager_invitation_revoked', user: $user, actor: auth()->user(), method: 'erp', request: request());

            return ['success' => true];
        });
    }

    public function find(string $token): ManagerInvitation
    {
        $invite = ManagerInvitation::where('token_hash', hash('sha256', $token))->first();
        abort_unless($invite && $this->valid($invite), 410, '초대가 만료·취소되었거나 이미 사용되었습니다. 관리자에게 새 초대를 요청하세요.');

        return $invite;
    }

    public function revokeNew(int $invitationId): array
    {
        if (! $this->canIssue()) {
            return ['success' => false, 'error' => '정식 로그인한 수퍼관리자만 초대를 취소할 수 있습니다.'];
        }

        return DB::transaction(function () use ($invitationId) {
            $invite = ManagerInvitation::query()->lockForUpdate()->find($invitationId);
            if (! $invite || $invite->kind !== 'new_employee' || $invite->accepted_at) {
                return ['success' => false, 'error' => '취소할 수 없는 초대입니다.'];
            }
            $invite->update(['revoked_at' => now()]);
            AuthEvent::record('manager_invitation_revoked', actor: auth()->user(), method: 'erp', request: request(), note: 'invitation_id='.$invite->id);

            return ['success' => true];
        });
    }

    private function valid(ManagerInvitation $invite): bool
    {
        $user = $invite->user_id ? User::find($invite->user_id) : null;
        $issuer = User::find($invite->created_by_id);
        $grant = $invite->grant;

        return ! $invite->accepted_at && ! $invite->revoked_at && $invite->expires_at->isFuture()
            && $issuer?->account_status === 'active' && $issuer->access_role === 'super_admin'
            && in_array($grant['access_role'] ?? null, ['admin', 'site_manager'], true)
            && in_array($grant['access_scope'] ?? null, ['site', 'company', 'all_sites'], true)
            && ($grant['access_role'] !== 'admin' || $grant['access_scope'] === 'all_sites')
            && ($invite->kind === 'new_employee'
                ? ! $invite->user_id && is_array($invite->enrollment)
                    && (empty($invite->enrollment['site_id']) || Site::whereKey($invite->enrollment['site_id'])->exists())
                    && (empty($invite->enrollment['company_id']) || Company::whereKey($invite->enrollment['company_id'])->exists())
                : $invite->kind === 'existing_worker' && $user && $this->eligible($user) && hash_equals($invite->account_fingerprint, $this->fingerprint($user)))
            && ($grant['access_scope'] !== 'site' || Site::whereKey($grant['allowed_site_id'])->exists())
            && ($grant['access_scope'] !== 'company' || Company::whereKey($grant['allowed_company_id'])->exists());
    }

    public function verifyPhone(string $token, string $phone, Request $request, ?string $name = null): void
    {
        $invite = $this->find($token);
        if ($invite->kind === 'new_employee') {
            $name = trim((string) $name);
            $phone = WorkerPhone::normalize($phone);
            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['name' => '이름을 입력하세요.']);
            }
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => '전화번호 전체와 국가번호를 입력하세요. 미국 번호는 10자리로 입력할 수 있습니다.']);
            }
            $this->ensureNewPhone($phone);
            $request->session()->regenerate();
            $request->session()->put(self::SESSION, ['token' => $token, 'expires' => now()->addMinutes(15)->timestamp,
                'name' => $name, 'phone' => '+'.$phone]);

            return;
        }
        $expected = WorkerPhone::normalize((string) User::findOrFail($invite->user_id)->employee->phone);
        $actual = WorkerPhone::normalize($phone);
        if ($actual === null || $expected === null || ! hash_equals($expected, $actual)) {
            throw ValidationException::withMessages(['phone' => '등록된 전화번호 전체를 입력하세요. 국가번호도 등록된 번호와 같아야 합니다.']);
        }
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['token' => $token, 'expires' => now()->addMinutes(15)->timestamp]);
    }

    public function sessionToken(Request $request): ?string
    {
        $session = $request->session()->get(self::SESSION);

        return is_array($session) && ($session['expires'] ?? 0) > now()->timestamp ? ($session['token'] ?? null) : null;
    }

    public function accept(Request $request, string $email, ?string $password = null, ?string $googleId = null): User
    {
        $token = $this->sessionToken($request);
        abort_unless($token, 410, '전화번호 확인부터 다시 진행하세요.');
        $email = Str::lower(trim($email));
        $user = DB::transaction(function () use ($token, $email, $password, $googleId, $request) {
            $invite = $this->find($token);
            // Existing identity mutations lock the account first; new enrollments lock their invitation.
            $user = $invite->kind === 'existing_worker' ? User::query()->lockForUpdate()->findOrFail($invite->user_id) : null;
            $invite = ManagerInvitation::query()->lockForUpdate()->findOrFail($invite->id);
            abort_unless($this->valid($invite), 410, '사용할 수 없는 초대입니다.');
            // Distinct invitations can present the same login identity concurrently.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['manager-email:'.$email]);
            if ($googleId) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['manager-google:'.$googleId]);
            }
            if (User::whereRaw('lower(email) = ?', [$email])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()
                || ($googleId && User::where('google_id', $googleId)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists())
                || (! $user && Employee::whereRaw('lower(email) = ?', [$email])->exists())) {
                throw ValidationException::withMessages(['email' => '이미 다른 계정에 등록된 로그인 정보입니다. 관리자에게 확인하세요.']);
            }
            if (! $user) {
                $session = $request->session()->get(self::SESSION);
                $phone = WorkerPhone::normalize((string) ($session['phone'] ?? ''));
                abort_unless($phone && filled($session['name'] ?? null), 410, '직원 정보 입력부터 다시 진행하세요.');
                // Share the public registration/HR phone locks; one invitation can create only one identity.
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['worker-register:'.$phone]);
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['worker-phone:+'.$phone]);
                $this->ensureNewPhone($phone);
                $employee = Employee::create($invite->enrollment + [
                    'name' => $session['name'], 'phone' => '+'.$phone, 'email' => $email,
                    'employment_status' => 'active', 'employment_type' => Employee::TYPE_STAFF,
                    'position' => $invite->grant['access_role'] === 'admin' ? 'general_manager' : 'superintendent',
                    'payload' => ['manager_invitation_id' => $invite->id],
                ]);
                $user = User::create(['name' => $employee->name, 'employee_id' => $employee->id,
                    'email' => $email, 'password' => Str::random(64)]);
            }
            $data = $invite->grant + ['email' => $email, 'google_id' => $googleId, 'account_status' => 'active',
                'email_verified_at' => $googleId ? now() : null, 'last_login_at' => now(),
                'remember_token' => Str::random(60), 'password_login_failures' => 0, 'password_login_locked_until' => null];
            if ($password !== null) {
                $data['password'] = Hash::make($password);
                $data['password_set_at'] = now();
            }
            $user->forceFill($data)->save();
            $invite->update(['user_id' => $user->id, 'accepted_at' => now()]);
            AuthEvent::record('manager_invitation_accepted', user: $user, actor: User::find($invite->created_by_id), method: $googleId ? 'google' : 'password', request: $request);

            return $user;
        });
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->forget([self::SESSION, 'url.intended', EmailPasswordAuthService::ERP_LOGIN_SESSION]);
        PersonalAppAccessService::clearSession($request);
        WorkerDeviceSession::clear($request);
        $request->session()->put(EmailPasswordAuthService::STRONG_AUTH_SESSION, $user->id);

        return $user;
    }

    private function ensureNewPhone(string $phone): void
    {
        if (WorkerPhone::employees($phone)->exists()) {
            throw ValidationException::withMessages(['phone' => '이미 등록된 직원 번호입니다. 관리자에게 기존 직원의 관리자 초대를 요청하세요.']);
        }
    }
}
