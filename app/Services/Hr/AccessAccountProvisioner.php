<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 직접 인사등록(방법①)의 핵심 — 직원(Employee)에게 로그인 계정(User)을 부여/갱신한다.
 *
 * 관리자/작업자 두 유형 모두 같은 경로로 처리한다:
 *  - 작업자 → access_role 'worker' (현장 출퇴근 앱)
 *  - 관리자 → admin급 역할 (관리 패널)
 *
 * 로그인은 구글 인증 전용이므로 비밀번호는 임의값으로만 채운다(실제 로그인에 사용 안 함).
 * 이메일 = 본인 구글 이메일이어야 하며, 이게 구글 로그인 매칭 키가 된다.
 */
class AccessAccountProvisioner
{
    public function grant(Employee $employee, string $accessRole, string $accessScope = 'self'): User
    {
        $email = $employee->email ? Str::lower(trim($employee->email)) : null;

        if (! $email) {
            throw new RuntimeException('로그인 계정을 만들려면 직원 이메일(구글 이메일)이 필요합니다. 먼저 이메일을 입력하세요.');
        }

        $user = User::query()->where('employee_id', $employee->id)->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->first()
            ?? new User();

        $name = $employee->name
            ?: trim(implode(' ', array_filter([$employee->first_name, $employee->last_name])))
            ?: $email;

        $user->fill([
            'employee_id' => $employee->id,
            'name' => $name,
            'email' => $email,
            'access_role' => $accessRole,
            'access_scope' => $accessScope,
            'account_status' => 'active',
            // 기존 값이 있으면 보존, 없으면 직원 소속으로 채움.
            'allowed_company_id' => $user->allowed_company_id ?: $employee->company_id,
            'allowed_site_id' => $user->allowed_site_id ?: $employee->site_id,
            'allowed_team_id' => $user->allowed_team_id ?: $employee->team_id,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ]);

        // 구글 인증 전용 — 비밀번호는 자리만 채운다.
        if (! $user->exists || ! $user->password) {
            $user->password = Str::password(32);
        }

        $user->save();

        return $user;
    }
}
