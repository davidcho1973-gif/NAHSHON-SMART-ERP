<?php

namespace App\Providers;

use App\Http\Controllers\Mcp\AuthorizationController;
use App\OAuth\ErpAccessToken;
use App\OAuth\ErpAccessTokenRepository;
use App\OAuth\ErpAuthCodeRepository;
use App\OAuth\ErpRefreshTokenRepository;
use App\Support\Mcp\ErpMcpOAuth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

class ErpMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Called in register(), before any package provider boots its default routes.
        Passport::ignoreRoutes();
        $this->app->bind(AccessTokenRepository::class, ErpAccessTokenRepository::class);
        $this->app->bind(AuthCodeRepository::class, ErpAuthCodeRepository::class);
        $this->app->bind(RefreshTokenRepository::class, ErpRefreshTokenRepository::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)->give(fn () => Auth::guard('web'));
    }

    public function boot(): void
    {
        Passport::useAccessTokenEntity(ErpAccessToken::class);
        Passport::tokensCan([ErpMcpOAuth::SCOPE => 'ERP read-only access, including permitted HR, payroll and business documents']);
        Passport::defaultScopes([]);
        Passport::tokensExpireIn(now()->addMinutes((int) config('erp_mcp.access_token_minutes', 15)));
        Passport::refreshTokensExpireIn(now()->addDays((int) config('erp_mcp.refresh_token_days', 7)));
        Passport::authorizationView('oauth.erp-authorize');
        RateLimiter::for('erp-mcp-ip', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('erp-mcp-user', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier().'|'.$request->attributes->get('erp_mcp_client_id')
        ));

        if (config('erp_mcp.enabled')) {
            $this->loadRoutesFrom(base_path('routes/erp_mcp.php'));
        }
    }
}
