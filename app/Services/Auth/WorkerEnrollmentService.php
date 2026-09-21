<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\AuthSetupToken;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkerEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** HR owns registration and activation; foremen have read-only team visibility. */
class WorkerEnrollmentService
{
    public function canManage(User $actor, Team $team): bool
    {
        return in_array($actor->access_role, ['super_admin', 'admin', 'hr_manager'], true)
            && $this->canView($actor, $team);
    }

    public function canView(User $actor, Team $team): bool
    {
        if ($actor->account_status !== 'active' || $team->status !== 'active' || ! $team->company_id || ! $team->site_id) {
            return false;
        }
        if (in_array($actor->access_role, ['super_admin', 'admin'], true)) {
            return true;
        }
        if ($actor->access_role === 'foreman') {
            return $actor->access_scope === 'team'
                && (int) $actor->allowed_team_id === $team->id
                && (int) $team->foreman_employee_id === (int) $actor->employee_id
                && $actor->employee?->employment_status === 'active'
                && (int) $actor->employee?->team_id === $team->id
                && (int) $actor->employee?->site_id === (int) $team->site_id
                && (int) $actor->employee?->company_id === (int) $team->company_id;
        }
        if ($actor->access_role !== 'hr_manager') {
            return false;
        }

        return match ($actor->access_scope) {
            'all_sites' => true,
            'company' => (int) $actor->allowed_company_id === (int) $team->company_id,
            'site' => (int) $actor->allowed_site_id === (int) $team->site_id,
            'team' => (int) $actor->allowed_team_id === $team->id,
            default => false,
        };
    }

    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 10 && ! str_starts_with(trim($value), '+')) {
            $digits = '1'.$digits;
        }
        if (! preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => '미국 번호 10자리 또는 국가번호 포함 번호를 입력하세요. / Enter a phone number with country code.']);
        }

        return '+'.$digits;
    }

    public function submit(User $actor, Team $team, array $data): WorkerEnrollment
    {
        abort_unless($this->canManage($actor, $team), 403);
        abort_unless($team->status === 'active' && $team->site_id && $team->company_id, 404);
        $phone = self::phone($data['phone']);

        return DB::transaction(function () use ($team, $data, $phone) {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            // Repeated registration never overwrites existing names or access.
            return WorkerEnrollment::firstOrCreate(['team_id' => $team->id, 'phone' => $phone], [
                'name' => trim($data['name']), 'status' => 'pending',
            ]);
        });
    }

    /** HR-confirmed desk registration: create, approve and issue one personal QR as one unit. */
    public function registerAndActivate(User $actor, Team $team, array $data): array
    {
        return DB::transaction(function () use ($actor, $team, $data) {
            $enrollment = $this->submit($actor, $team, $data);
            $this->approve($actor, $enrollment);
            $url = $this->activation($actor, $enrollment->fresh());

            return ['enrollment' => $enrollment->fresh(), 'url' => $url];
        });
    }

    public function approve(User $actor, WorkerEnrollment $request): User
    {
        return DB::transaction(function () use ($actor, $request) {
            $request = WorkerEnrollment::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $team = Team::whereKey($request->team_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canManage($actor, $team), 403);
            if ($request->status === 'approved') {
                return $request->employee->user;
            }
            abort_unless($request->status === 'pending', 409);
            // Serialize approvals for a phone even when submitted to different teams.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['worker-phone:'.$request->phone]);
            $matches = Employee::whereNotNull('phone')->get()->filter(function (Employee $employee) use ($request) {
                try {
                    return self::phone($employee->phone) === $request->phone;
                } catch (ValidationException) {
                    return false;
                }
            });
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['approval' => '동일 번호의 기존 직원이 여러 명입니다. 인사담당자가 중복을 먼저 확인하세요.']);
            }
            $employee = $matches->first();
            if ($employee) {
                $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $account = $employee->user;
                if (mb_strtolower(trim($employee->name)) !== mb_strtolower(trim($request->name))
                    || (int) $employee->team_id !== $team->id
                    || (int) $employee->company_id !== (int) $team->company_id
                    || (int) $employee->site_id !== (int) $team->site_id
                    || $employee->employment_status !== 'active' || ! $employee->isHourly()
                    || ! in_array($employee->position, [null, '', 'worker'], true)
                    || ! in_array($employee->attendance_app_role, [null, '', 'worker'], true)
                    || ($account && ($account->access_role !== 'worker' || $account->access_scope !== 'self' || $account->account_status !== 'active' || $account->hasPin()))) {
                    throw ValidationException::withMessages(['approval' => '기존 직원 정보 또는 계정이 있습니다. 중복 생성하지 않습니다. 관리자가 소속·신원을 확인하고 기존 계정 복구를 진행하세요.']);
                }
            } else {
                $employee = Employee::create([
                    'name' => $request->name, 'phone' => $request->phone,
                    'company_id' => $team->company_id, 'site_id' => $team->site_id, 'team_id' => $team->id,
                    'role' => $team->trade_type, 'position' => 'worker',
                    'employment_type' => Employee::TYPE_DIRECT, 'employment_status' => 'active',
                    'attendance_app_role' => 'worker', 'attendance_app_scope' => 'self',
                ]);
            }
            $user = $employee->user ?: User::create([
                'name' => $employee->name, 'email' => null, 'password' => Str::random(64),
                'employee_id' => $employee->id, 'access_role' => 'worker', 'access_scope' => 'self',
                'account_status' => 'active', 'allowed_company_id' => $team->company_id,
                'allowed_site_id' => $team->site_id, 'allowed_team_id' => $team->id,
            ]);
            $request->update(['status' => 'approved', 'employee_id' => $employee->id,
                'approved_by_id' => $actor->id, 'approved_at' => now()]);
            AuthEvent::record('worker_enrollment_approved', user: $user, actor: $actor, method: 'onboarding');

            return $user;
        });
    }

    public function activation(User $actor, WorkerEnrollment $request): string
    {
        return DB::transaction(function () use ($actor, $request) {
            $request = WorkerEnrollment::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canManage($actor, $request->team), 403);
            abort_unless($request->status === 'approved', 409);
            $user = User::where('employee_id', $request->employee_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->access_role === 'worker' && $user->access_scope === 'self'
                && $user->account_status === 'active' && ! $user->hasPin()
                && $user->employee?->employment_status === 'active'
                && (int) $user->employee?->team_id === (int) $request->team_id, 409);

            return app(PinAuthService::class)->issueSetupLink($user, AuthSetupToken::PURPOSE_ACTIVATION, $actor);
        });
    }
}
