<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\AttendanceQrCode;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
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
                        'label' => trim($site->code . ' - ' . $site->name),
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
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authUserViewData(User $user): array
    {
        $name = $user->name ?: 'ERP User';

        return [
            'name' => $name,
            'email' => $user->email,
            'role' => User::ROLE_OPTIONS[$user->access_role] ?? Str::headline($user->access_role ?: 'user'),
            'initials' => $this->initials($name),
            'employee_id' => $user->employee_id,
            'raw_role' => $user->access_role,
            'site_code' => $user->employee?->site?->code,
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
