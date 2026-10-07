<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\ManagerInvitationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ManagerInvitationController extends Controller
{
    public function show(string $token, Request $request, ManagerInvitationService $service)
    {
        $invite = $service->find($token);

        $canonicalRoot = rtrim((string) config('app.url'), '/');
        if ($request->getHost() !== parse_url($canonicalRoot, PHP_URL_HOST)) {
            return redirect()->away($canonicalRoot.route('manager-invitation.show', ['token' => $token], absolute: false))
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('auth.manager-invitation', [
            'token' => $token, 'user' => $invite->user_id ? User::findOrFail($invite->user_id) : null,
            'newEmployee' => $invite->kind === 'new_employee',
            'jobLabel' => config('job_access.jobs.'.($invite->grant['job_role'] ?? '').'.label'),
            'jobTrade' => $invite->grant['job_trade'] ?? null,
            'enrollmentName' => $request->session()->get(ManagerInvitationService::SESSION.'.name'),
            'verified' => $service->sessionToken($request) === $token,
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(string $token, Request $request, ManagerInvitationService $service)
    {
        $invite = $service->find($token);
        $input = $request->validate(['phone' => ['required', 'string', 'max:40'],
            'name' => [$invite->kind === 'new_employee' ? 'required' : 'nullable', 'string', 'max:255']]);
        $service->verifyPhone($token, $input['phone'], $request, $input['name'] ?? null);

        return redirect()->route('manager-invitation.show', ['token' => $token]);
    }

    public function complete(string $token, Request $request, ManagerInvitationService $service)
    {
        abort_unless($service->sessionToken($request) === $token, 410, '전화번호 확인부터 다시 진행하세요.');
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers(), 'max:128'],
        ]);
        $service->accept($request, $input['email'], $input['password']);

        return redirect()->route('manager-invitation.welcome');
    }

    public function welcome()
    {
        return response()->view('auth.manager-invitation-welcome')->header('Cache-Control', 'no-store, private');
    }
}
