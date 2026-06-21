<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoogleAuthController extends Controller
{
    public function login(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect()->intended($this->landingPathFor($user));
        }

        return view('auth.google-login', [
            'googleConfigured' => $this->googleIsConfigured(),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->googleIsConfigured()) {
            return $this->deny('Google login is not configured yet. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.');
        }

        $state = Str::random(40);

        $request->session()->put('google_oauth_state', $state);

        $parameters = [
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
        ];

        if (filled(config('services.google.prompt'))) {
            $parameters['prompt'] = config('services.google.prompt');
        }

        return redirect()->away(config('services.google.auth_url') . '?' . http_build_query($parameters));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->deny('Google login was cancelled or denied.');
        }

        $expectedState = $request->session()->pull('google_oauth_state');
        $actualState = (string) $request->query('state', '');

        if (! $expectedState || ! hash_equals($expectedState, $actualState)) {
            return $this->deny('Google login state expired. Please try again.');
        }

        if (! $request->filled('code')) {
            return $this->deny('Google did not return an authorization code.');
        }

        try {
            $tokenResponse = Http::asForm()->post(config('services.google.token_url'), [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code' => $request->query('code'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]);

            if (! $tokenResponse->successful()) {
                return $this->deny('Google token exchange failed. Please try again.');
            }

            $accessToken = (string) $tokenResponse->json('access_token', '');

            if ($accessToken === '') {
                return $this->deny('Google did not return an access token.');
            }

            $profileResponse = Http::withToken($accessToken)->get(config('services.google.userinfo_url'));

            if (! $profileResponse->successful()) {
                return $this->deny('Google profile lookup failed. Please try again.');
            }
        } catch (ConnectionException) {
            return $this->deny('Could not connect to Google. Please try again.');
        }

        $profile = $profileResponse->json();

        if (! is_array($profile)) {
            return $this->deny('Google profile response was invalid. Please try again.');
        }

        return $this->loginGoogleProfile($request, $profile);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Signed out successfully.');
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function loginGoogleProfile(Request $request, array $profile): RedirectResponse
    {
        $googleId = (string) ($profile['sub'] ?? '');
        $email = Str::lower((string) ($profile['email'] ?? ''));
        $emailVerified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if ($googleId === '' || $email === '') {
            return $this->deny('Google did not return a usable account profile.');
        }

        if (! $emailVerified) {
            return $this->deny('Please verify your Google email before signing in.');
        }

        $linkedUser = User::query()->where('google_id', $googleId)->first();
        $emailUser = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($linkedUser && $emailUser && ! $linkedUser->is($emailUser)) {
            return $this->deny('This Google account is already linked to another ERP user.');
        }

        $user = $linkedUser ?? $emailUser;

        if (! $user) {
            return $this->deny('No active ERP account is registered for this Google email.');
        }

        if ($user->google_id && $user->google_id !== $googleId) {
            return $this->deny('This ERP account is linked to a different Google account.');
        }

        if ($user->account_status !== 'active') {
            return $this->deny('This ERP account is not active. Please contact an administrator.');
        }

        $user->forceFill([
            'google_id' => $user->google_id ?: $googleId,
            'email_verified_at' => $user->email_verified_at ?: now(),
            'last_login_at' => now(),
        ])->save();

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended($this->landingPathFor($user));
    }

    private function deny(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }

    private function googleIsConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function redirectUri(): string
    {
        return (string) (config('services.google.redirect') ?: route('auth.google.callback'));
    }

    private function landingPathFor(User $user): string
    {
        $path = $user->landingPath();

        return str_starts_with($path, '/portal/') ? '/' : $path;
    }
}
