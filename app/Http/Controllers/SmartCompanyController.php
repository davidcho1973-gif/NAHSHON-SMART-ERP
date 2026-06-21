<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SmartCompanyController extends Controller
{
    public function index(): View
    {
        $siteOptions = [];

        try {
            if (Schema::hasTable('sites')) {
                $siteOptions = Site::query()
                    ->whereHas('employees', fn ($query) => $query->where('employment_status', 'active'))
                    ->orderBy('code')
                    ->get()
                    ->map(fn (Site $site): array => [
                        'code' => $site->code,
                        'label' => trim($site->code . ' - ' . $site->name),
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

        $user = auth()->user();

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
}
