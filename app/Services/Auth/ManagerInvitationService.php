<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\Company;
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

        return DB::transaction(function () use ($input, $role, $scope, $site, $company) {
            $user = User::query()->lockForUpdate()->find((int) ($input['id'] ?? 0));
            if (! $user || ! $this->eligible($user)) {
                return ['success' => false, 'error' => '이메일·로그인 정보가 없는 활성 작업자 또는 반장만 초대할 수 있습니다. 기존 관리자 계정은 계정 수정으로 관리하세요.'];
            }
            ManagerInvitation::where('user_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = Str::random(64);
            $invite = ManagerInvitation::create([
                'user_id' => $user->id, 'created_by_id' => auth()->id(), 'token_hash' => hash('sha256', $token),
                'account_fingerprint' => $this->fingerprint($user), 'expires_at' => now()->addDays(7),
                'grant' => ['access_role' => $role, 'access_scope' => $scope, 'allowed_site_id' => $site?->id,
                    'allowed_company_id' => $company?->id, 'allowed_team_id' => null],
            ]);
            AuthEvent::record('manager_invitation_created', user: $user, actor: auth()->user(), method: 'erp', request: request());
            // Alias hosts do not share the canonical Google callback's session cookie.
            $url = rtrim((string) config('app.url'), '/').route('manager-invitation.show', ['token' => $token], absolute: false);

            return ['success' => true, 'id' => $invite->id, 'url' => $url, 'qr' => QrSvg::dataUri($url),
                'expiresAt' => $invite->expires_at->toDateTimeString(), 'name' => $user->name];
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

    private function valid(ManagerInvitation $invite): bool
    {
        $user = User::find($invite->user_id);
        $issuer = User::find($invite->created_by_id);
        $grant = $invite->grant;

        return ! $invite->accepted_at && ! $invite->revoked_at && $invite->expires_at->isFuture()
            && $issuer?->account_status === 'active' && $issuer->access_role === 'super_admin'
            && $user && $this->eligible($user) && hash_equals($invite->account_fingerprint, $this->fingerprint($user))
            && ($grant['access_scope'] !== 'site' || Site::whereKey($grant['allowed_site_id'])->exists())
            && ($grant['access_scope'] !== 'company' || Company::whereKey($grant['allowed_company_id'])->exists());
    }

    public function verifyPhone(string $token, string $phone, Request $request): void
    {
        $invite = $this->find($token);
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
            // All invitation mutation paths lock the account first, then its invitation.
            $user = User::query()->lockForUpdate()->findOrFail($invite->user_id);
            $invite = ManagerInvitation::query()->lockForUpdate()->findOrFail($invite->id);
            abort_unless($this->valid($invite), 410, '사용할 수 없는 초대입니다.');
            if (User::whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists()
                || ($googleId && User::where('google_id', $googleId)->whereKeyNot($user->id)->exists())) {
                throw ValidationException::withMessages(['email' => '이미 다른 계정에 등록된 로그인 정보입니다. 관리자에게 확인하세요.']);
            }
            $data = $invite->grant + ['email' => $email, 'google_id' => $googleId, 'account_status' => 'active',
                'email_verified_at' => $googleId ? now() : null, 'last_login_at' => now(),
                'remember_token' => Str::random(60), 'password_login_failures' => 0, 'password_login_locked_until' => null];
            if ($password !== null) {
                $data['password'] = Hash::make($password);
                $data['password_set_at'] = now();
            }
            $user->forceFill($data)->save();
            $invite->update(['accepted_at' => now()]);
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
}
