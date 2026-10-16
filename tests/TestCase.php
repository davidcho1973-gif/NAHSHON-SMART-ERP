<?php

namespace Tests;

use App\Services\Auth\EmailPasswordAuthService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Explicit app-business fixture; authentication-boundary tests use the actual login routes. */
    protected function actingAsPersonalAppUser(Authenticatable $user, $guard = null): static
    {
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->getAuthIdentifier()]);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $this->app['request']->setUserResolver(fn () => $this->app['auth']->user());

        return parent::actingAs($user, $guard);
    }

    /** Business-permission fixtures model a verified login; auth tests use the real login endpoint. */
    protected function actingAsPurchaseUser(Authenticatable $user, $guard = null): static
    {
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->getAuthIdentifier()]);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $this->app['request']->setUserResolver(fn () => $this->app['auth']->user());

        return parent::actingAs($user, $guard);
    }
}
