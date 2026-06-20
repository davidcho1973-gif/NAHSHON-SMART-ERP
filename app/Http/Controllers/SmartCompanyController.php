<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

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

        return view('smart-company.index', [
            'siteOptions' => $siteOptions,
            'siteNames' => $siteNames,
        ]);
    }
}
