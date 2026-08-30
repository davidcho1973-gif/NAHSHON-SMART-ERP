<?php

namespace App\Http\Controllers;

use App\Models\AttendanceQrCode;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SmartCompanyController extends Controller
{
    public function index(): View
    {
        $siteOptions = [];
        $user = auth()->user();

        try {
            if (Schema::hasTable('sites')) {
                $query = Site::query()->where('status', 'active');

                // Role-based filtering for site visibility
                if ($user && ! in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)) {
                    if ($user->allowed_site_id) {
                        $query->where('id', $user->allowed_site_id);
                    }
                }

                $siteOptions = $query->orderBy('code')
                    ->get()
                    ->map(fn (Site $site): array => [
                        'code' => $site->code,
                        // 문서함처럼 ERP 안에 얹혀 열리는 화면은 현장 코드가 아니라
                        // id 로 거른다. 화면이 코드만 갖고 있으면 그 화면에는 현장을
                        // 전달할 방법이 없다.
                        'id' => $site->id,
                        'label' => trim($site->code.' - '.$site->name),
                        'setup_pending' => is_null($site->setup_completed_at),
                    ])
                    ->values()
                    ->all();
            }
        } catch (\Throwable) {
            $siteOptions = [];
        }

        $siteNames = ['ALL' => 'Global'];
        foreach ($siteOptions as $site) {
            $siteNames[$site['code']] = $site['label'];
        }

        return view('smart-company.index', [
            'siteOptions' => $siteOptions,
            'siteNames' => $siteNames,
            'authUser' => $user instanceof User ? $this->authUserViewData($user) : null,
            // 회사 전환은 예전 관리자 패널에만 있었다. 패널을 없애면서 여기로 옮긴다 —
            // 회사가 하나뿐인 사람에게는 보이지 않는다.
            'companySwitcher' => $user instanceof User ? $this->companySwitcherViewData($user) : null,
        ]);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function authUserViewData(User $user): array
    {
        $name = $user->name ?: 'ERP User';

        return [
            'name' => $name,
            // 등록(직원) 이름 — 출퇴근 등 본인 확인 화면에서 계정명 대신 실제 등록명을 표시.
            'employee_name' => $user->employee?->name ?: $name,
            'email' => $user->email,
            'role' => User::ROLE_OPTIONS[$user->access_role] ?? Str::headline($user->access_role ?: 'user'),
            'initials' => $this->initials($name),
            'employee_id' => $user->employee_id,
            'raw_role' => $user->access_role,
            'site_code' => $user->employee?->site?->code,
            'can_access_admin' => $user->account_status === 'active'
                && in_array($user->access_role, User::ADMIN_PANEL_ROLES, true),
        ];
    }

    /**
     * 회사 전환 선택지. 소속 회사가 하나면 null 을 돌려 화면에서 아예 감춘다 —
     * 고를 것이 없는 선택 상자는 자리만 차지한다.
     *
     * @return array{current: int|null, companies: array<int, array{id: int, name: string}>}|null
     */
    private function companySwitcherViewData(User $user): ?array
    {
        $companies = $user->accessibleCompanies();

        if ($companies->count() < 2) {
            return null;
        }

        return [
            'current' => CurrentCompany::id(),
            'companies' => $companies->map(fn ($company): array => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
            ])->values()->all(),
        ];
    }

    private function initials(string $name): string
    {
        $parts = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $parts !== '' ? $parts : 'ER';
    }

    public function teamQr(Request $request, Team $team): View
    {
        $team->loadMissing(['site', 'company']);
        $qrCode = AttendanceQrCode::forTeam($team, $request->user()?->id);
        $intakeUrl = route('attendance-app.team', ['token' => $qrCode->token]);

        return view('smart-company.team-qr', [
            'team' => $team,
            'qrCode' => $qrCode,
            'intakeUrl' => $intakeUrl,
        ]);
    }
}
