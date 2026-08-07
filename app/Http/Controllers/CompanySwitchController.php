<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        // set() rejects companies the user is not a member of, so a tampered
        // form leaves the current selection alone.
        if (! CurrentCompany::set((int) $validated['company_id'])) {
            return back()->with('company_switch_error', '선택한 회사에 접근할 권한이 없습니다.');
        }

        return back();
    }
}
