<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\EmployeeAdminService;
use Illuminate\Support\Facades\DB;

/**
 * 관리자가 자기 직원 정보를 스스로 만든다 — 앱을 관리한다고 현장 사람이 아닌 것은 아니다.
 *
 * ── 왜 필요한가 ────────────────────────────────────────────────────────
 * 관리자 계정에는 직원 기록이 안 붙어 있어서, 앱을 처음 열면 반드시 «연결 대기 중»
 * 화면을 본다. 그 화면이 하는 말은 «ERP 인원관리에 가서 계정을 만드세요» 였고,
 * 못 하는 사람에게는 «현장 관리자에게 부탁하세요» 였다.
 *
 * 둘 다 <b>자기가 그 관리자인 사람</b>에게는 쓸모가 없다. 앱 관리를 겸하는 소장은
 * 자기도 출퇴근을 찍고, 보고를 올리고, 영수증을 낸다. 그 사람에게 «남에게 부탁하라»
 * 고 말하는 화면은 틀린 화면이다.
 *
 * ── 왜 아무나 못 하게 하는가 ───────────────────────────────────────────
 * 직원 기록은 급여가 매달리는 자리다. 로그인만 하면 스스로 직원이 될 수 있다면,
 * 구글 계정 하나로 급여 대장에 줄을 만들 수 있다는 뜻이 된다. 그래서 <b>이미 인원을
 * 관리할 수 있는 역할</b>에게만 연다 — 그 사람은 어차피 ERP 에서 같은 일을 할 수
 * 있으니 새로 열리는 권한이 없고, 다만 화면을 옮겨 다닐 필요가 없어질 뿐이다.
 *
 * ── 역할은 건드리지 않는다 ─────────────────────────────────────────────
 * 직원을 만들면서 계정 역할까지 손대면, 최고 관리자가 자기 손으로 자기 권한을
 * 떨어뜨리는 일이 생긴다. 여기서는 «이 계정이 이 사람이다» 만 잇는다.
 */
class SelfEmployeeLink
{
    /** 스스로 만들 수 있는 역할 — 이미 ERP 에서 인원을 관리할 수 있는 사람들. */
    public const ALLOWED_ROLES = EmployeeAdminService::MANAGE_ROLES;

    public function allowed(?User $actor): bool
    {
        return $actor !== null
            && $actor->account_status === 'active'
            && $actor->employee_id === null
            && in_array($actor->access_role, self::ALLOWED_ROLES, true);
    }

    /**
     * 화면이 물어볼 것들 — 현장 목록과, 이미 아는 값(이름·이메일).
     *
     * @return array<string, mixed>
     */
    public function options(User $actor): array
    {
        return [
            'name' => $actor->name,
            'email' => $actor->email,
            'sites' => Site::query()
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => [
                    'id' => $s->id,
                    'label' => trim($s->code.' · '.$s->name),
                ])->all(),
            'positions' => collect(Employee::POSITIONS)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()->all(),
        ];
    }

    /**
     * 내 직원 정보를 만들고 이 계정에 잇는다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(User $actor, array $input): array
    {
        if (! $this->allowed($actor)) {
            return ['success' => false, 'error' => $actor->employee_id !== null
                ? '이미 직원 정보가 연결되어 있습니다.'
                : '이 계정으로는 직원 정보를 스스로 만들 수 없습니다. 인원 담당자에게 요청해 주세요.'];
        }

        $name = trim((string) ($input['name'] ?? '')) ?: (string) $actor->name;
        $siteId = (int) ($input['siteId'] ?? 0);
        $trade = trim((string) ($input['trade'] ?? ''));
        $position = trim((string) ($input['position'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'error' => '이름을 입력해 주세요.'];
        }

        $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        if (! $site) {
            return ['success' => false, 'error' => '현장을 골라 주세요. 출퇴근과 보고가 현장별로 묶입니다.'];
        }

        if ($position !== '' && ! array_key_exists($position, Employee::POSITIONS)) {
            return ['success' => false, 'error' => '직책을 다시 골라 주세요.'];
        }

        // 같은 이메일의 직원이 이미 있으면 새로 만들지 않고 그것에 잇는다 —
        // 누군가 ERP 에서 먼저 만들어 두었을 수 있고, 그때 둘이 되면 급여가 갈린다.
        $existing = $actor->email
            ? Employee::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($actor->email))])->first()
            : null;

        $employee = DB::transaction(function () use ($existing, $actor, $name, $site, $trade, $position): Employee {
            $employee = $existing ?: new Employee;

            $employee->fill([
                'company_id' => $employee->company_id ?: $site->company_id,
                'site_id' => $employee->site_id ?: $site->id,
                'name' => $employee->name ?: $name,
                'email' => $employee->email ?: $actor->email,
                'employment_status' => 'active',
                // 관리직이다. 시급 근로자로 잡히면 급여 계산에 잘못 들어간다.
                'employment_type' => $employee->employment_type ?: Employee::TYPE_STAFF,
            ]);

            if ($trade !== '') {
                $employee->role = $trade;
            }
            if ($position !== '') {
                $employee->position = $position;
            }

            $employee->save();

            // 역할(권한)은 건드리지 않는다 — «이 계정이 이 사람이다» 만 잇는다.
            $actor->forceFill(['employee_id' => $employee->id])->save();

            return $employee;
        });

        return [
            'success' => true,
            'employeeId' => $employee->id,
            'linkedExisting' => $existing !== null,
            'message' => $existing !== null
                ? '이미 있던 직원 정보에 계정을 이었습니다.'
                : '직원 정보를 만들고 계정을 이었습니다.',
        ];
    }
}
