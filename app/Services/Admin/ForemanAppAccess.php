<?php

namespace App\Services\Admin;

use App\Models\AuthEvent;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Team assignment and app access used to require independent, easily missed edits. */
class ForemanAppAccess
{
    public function apply(Team $team, ?Employee $foreman, ?int $previousId, ?string $email): void
    {
        if ($team->status === 'active' && $foreman) {
            $account = User::where('employee_id', $foreman->id)->lockForUpdate()->first();
            if ($account && (! in_array($account->access_role, ['worker', 'foreman'], true) || $account->account_status !== 'active')) {
                throw ValidationException::withMessages(['applyAppAccess' => '기존 관리자·특수 역할 또는 비활성 계정은 자동 변경하지 않습니다. 권한 함께 적용을 해제하고 기존 계정 권한을 확인하세요.']);
            }
            if (! in_array($foreman->attendance_app_role, [null, '', 'worker', 'foreman'], true)) {
                throw ValidationException::withMessages(['applyAppAccess' => '기존 QR 관리자 권한은 자동 변경하지 않습니다. 권한 함께 적용을 해제하세요.']);
            }
            $result = app(UserAccessService::class)->save([
                'id' => $account?->id,
                'name' => $account?->name ?? $foreman->name,
                'email' => $account?->email ?? ($email ?: $foreman->email),
                'employeeId' => $foreman->id,
                'role' => 'foreman', 'scope' => 'team', 'status' => 'active',
                'companyId' => $team->company_id, 'siteId' => $team->site_id, 'teamId' => $team->id,
                'notes' => $account?->access_notes,
            ]);
            if (! $result['success']) {
                $messages = collect($result['errors'] ?? [$result['error'] ?? '계정 저장 실패'])->flatten()->implode(' ');
                throw ValidationException::withMessages(['foremanEmail' => $messages]);
            }
            $foreman->update(['attendance_app_role' => 'foreman', 'attendance_app_scope' => 'team']);
            AuthEvent::record('foreman_access_applied', User::find($result['id']), auth()->user(), 'crew_setup', request(), 'team='.$team->id);
        }

        if ($previousId && ($previousId !== $foreman?->id || $team->status !== 'active')) {
            $previous = Employee::lockForUpdate()->find($previousId);
            if (! $previous) {
                return;
            }
            $account = User::where('employee_id', $previousId)->lockForUpdate()->first();
            // Only revoke this team's foreman role. Never downgrade an administrator or an unrelated scope.
            if ($account?->access_role === 'foreman' && $account->access_scope === 'team' && (int) $account->allowed_team_id === $team->id) {
                $account->update(['access_role' => 'worker', 'access_scope' => 'self', 'allowed_team_id' => null]);
                AuthEvent::record('foreman_access_removed', $account, auth()->user(), 'crew_setup', request(), 'team='.$team->id);
            }
            if ($previous->attendance_app_role === 'foreman' && $previous->attendance_app_scope === 'team' && (int) $previous->team_id === $team->id) {
                $previous->update(['attendance_app_role' => 'worker', 'attendance_app_scope' => 'self']);
            }
        }
    }
}
