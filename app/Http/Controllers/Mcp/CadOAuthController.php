<?php

namespace App\Http\Controllers\Mcp;

use App\Support\Mcp\CadMcpOAuth;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

/** Resolve Passport only after readiness and route middleware, not during route discovery. */
final class CadOAuthController
{
    public function authorize()
    {
        return $this->dispatch(AuthorizationController::class, 'authorize');
    }

    public function approve()
    {
        return $this->dispatch(ApproveAuthorizationController::class, 'approve');
    }

    public function deny()
    {
        return $this->dispatch(DenyAuthorizationController::class, 'deny');
    }

    public function token()
    {
        return $this->dispatch(AccessTokenController::class, 'issueToken');
    }

    private function dispatch(string $controller, string $method)
    {
        abort_unless(CadMcpOAuth::keysReady(), 503, 'CAD OAuth signing keys require administrator setup.');

        return app()->call([app($controller), $method]);
    }
}
