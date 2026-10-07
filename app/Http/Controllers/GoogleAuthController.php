<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\ManagerInvitationService;
use App\Services\Auth\PersonalAppAccessService;
use App\Support\WorkerDeviceSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoogleAuthController extends Controller
{
    public function login(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $erpLogin = EmailPasswordAuthService::rememberErpLogin($request);

        if ($user instanceof User && ! WorkerDeviceSession::isDeviceOnly($request)
            && (! $erpLogin || EmailPasswordAuthService::hasStrongAuthentication($request, $user))) {
            // 이미 로그인돼 있는데 로그인 화면으로 온 경우(북마크·뒤로가기).
            // 작업자를 ERP 로 보내면 안 된다 — 아래 landingPath 가 역할별로 갈라 준다.
            if ($erpLogin) {
                $request->session()->forget([EmailPasswordAuthService::ERP_LOGIN_SESSION, 'url.intended']);
            }

            return redirect()->to($user->landingPath());
        }

        return view('auth.google-login', [
            'googleConfigured' => $this->googleIsConfigured(),
            'sessionExpired' => $request->boolean('expired'),
            'erpLogin' => $erpLogin,
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $erpLogin = EmailPasswordAuthService::rememberErpLogin($request);
        if (! $this->googleIsConfigured()) {
            return $this->deny('Google login is not configured yet. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.');
        }

        $origin = $this->originFor($this->redirectUri());
        if ($origin === null) {
            return $this->deny('Google login callback URL is not configured correctly.');
        }

        $intended = $this->safeDestinationPath($request->query('return_to'))
            ?? $this->safeDestinationPath($request->session()->get('url.intended'), $request->getSchemeAndHttpHost())
            ?? $this->safeDestinationPath($request->session()->get('url.intended'), $this->originFor((string) config('app.url')));

        // Alias-host cookies do not reach the configured callback host. Enter that
        // origin before creating OAuth state; never move a session or state token.
        if ($origin !== $request->getSchemeAndHttpHost()) {
            $parameters = array_filter(['erp' => $erpLogin ? '1' : null, 'return_to' => $intended], fn ($value) => $value !== null);

            return redirect()->away($origin.route('auth.google.redirect', [], false)
                .($parameters ? '?'.http_build_query($parameters) : ''));
        }

        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
        }

        $state = Str::random(40);

        $request->session()->put('google_oauth_state', $state);
        $request->session()->put('google_oauth_invitation', app(ManagerInvitationService::class)->sessionToken($request));

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

        return redirect()->away(config('services.google.auth_url').'?'.http_build_query($parameters));
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
        app(PersonalAppAccessService::class)->logout($request);
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Signed out successfully.');
    }

    /**
     * @param  array<string, mixed>  $profile
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

        $invitationToken = $request->session()->pull('google_oauth_invitation');
        if ($invitationToken !== null) {
            abort_unless($invitationToken === app(ManagerInvitationService::class)->sessionToken($request), 410, '초대 확인이 만료되었습니다. 초대 링크에서 다시 시작하세요.');
            try {
                app(ManagerInvitationService::class)->accept($request, $email, googleId: $googleId);
            } catch (ValidationException $exception) {
                return redirect()->route('manager-invitation.show', ['token' => $invitationToken])->withErrors($exception->errors());
            }

            return redirect()->route('manager-invitation.welcome');
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
        $request->session()->put(EmailPasswordAuthService::STRONG_AUTH_SESSION, (int) $user->id);
        PersonalAppAccessService::clearSession($request);
        WorkerDeviceSession::clear($request);

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
        if ($request->session()->pull(EmailPasswordAuthService::ERP_LOGIN_SESSION) === true) {
            $request->session()->forget('url.intended');

            return $user->landingPath();
        }

        return $this->safeDestinationPath(
            $request->session()->pull('url.intended'),
            $this->originFor((string) config('app.url')),
        ) ?? $user->landingPath();
    }

    /** 우리 앱 안의, 지금도 살아 있는 화면인가. */
    private function safeDestinationPath(mixed $url, ?string $allowedOrigin = null): ?string
    {
        if (! is_string($url) || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // Only explicitly allowed absolute origins may become local paths. Query
        // return_to values have no allowed origin and must already be relative.
        if (isset($parts['scheme']) || isset($parts['host'])) {
            if ($allowedOrigin === null || $this->originFor($url) !== $allowedOrigin) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';
        $decodedPath = rawurldecode($path);
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_starts_with($decodedPath, '//') || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $decodedPath)) {
            return null;
        }

        // 없어진 관리자 패널, 그리고 로그인 자체로 되돌아가는 고리.
        foreach (['/admin', '/login', '/auth/'] as $dead) {
            if (str_starts_with($decodedPath, $dead)) {
                return null;
            }
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function originFor(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host'])
            .($port === ($scheme === 'https' ? 443 : 80) ? '' : ':'.$port);
    }

    private function deny(string $message): RedirectResponse
    {
        $token = app(ManagerInvitationService::class)->sessionToken(request());
        if ($token) {
            return redirect()->route('manager-invitation.show', ['token' => $token])->withErrors(['google' => $message]);
        }

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
