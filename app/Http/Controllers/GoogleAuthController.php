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
            // 이미 로그인돼 있는데 로그인 화면으로 온 경우(북마크·뒤로가기).
            // 작업자를 ERP 로 보내면 안 된다 — 아래 landingPath 가 역할별로 갈라 준다.
            return redirect()->to($user->landingPath());
        }

        return view('auth.google-login', [
            'googleConfigured' => $this->googleIsConfigured(),
            'sessionExpired' => $request->boolean('expired'),
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

        return redirect()->to($this->destinationFor($request, $user));
    }

    /**
     * 로그인 뒤에 어디로 보낼 것인가.
     *
     * 원래 열려던 화면이 있으면 거기로 돌려보낸다. 이게 없으면 작업자가
     * /attendance-app 을 열었다가 로그인 뒤 ERP 첫 화면에 떨어진다 — 자기 근무시간을
     * 보러 왔는데 회사 전체 화면이 뜨면 잘못 눌렀다고 생각하고 앱을 지운다. 설치를
     * 부탁하는 첫날에 이걸 겪으면 두 번째 기회는 없다.
     *
     * 다만 세션에 남은 주소를 그대로 믿지는 않는다. 없어진 /admin 화면이 옛 세션·북마크에
     * 남아 있어서(관리 화면은 전부 ERP 안으로 들어왔다), 그대로 따라가면 로그인하자마자
     * 한 번 튕기는 것처럼 보인다. 그런 주소는 버리고 역할에 맞는 곳으로 보낸다.
     */
    private function destinationFor(Request $request, User $user): string
    {
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && $this->isSafeDestination($intended)) {
            return $intended;
        }

        return $user->landingPath();
    }

    /** 우리 앱 안의, 지금도 살아 있는 화면인가. */
    private function isSafeDestination(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        // 다른 사이트로 보내지 않는다(열린 리다이렉트).
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return false;
        }

        if ($path === '' || ! str_starts_with($path, '/')) {
            return false;
        }

        // 없어진 관리자 패널, 그리고 로그인 자체로 되돌아가는 고리.
        foreach (['/admin', '/login', '/auth/'] as $dead) {
            if (str_starts_with($path, $dead)) {
                return false;
            }
        }

        return true;
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
}
