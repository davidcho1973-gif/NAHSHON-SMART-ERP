<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

class AuthorizationController extends \Laravel\Passport\Http\Controllers\AuthorizationController
{
    /** Sensitive ERP data needs a visible consent page on each connection. */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
