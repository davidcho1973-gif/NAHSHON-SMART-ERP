<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocaleMiddlewareTest extends TestCase
{
    private function runWithCookie(?string $locale): string
    {
        $request = Request::create('/', 'GET');
        if ($locale !== null) {
            $request->cookies->set('app_locale', $locale);
        }
        (new SetLocale)->handle($request, fn ($r) => response('ok'));

        return app()->getLocale();
    }

    public function test_supported_cookie_sets_app_locale(): void
    {
        $this->assertSame('es', $this->runWithCookie('es'));
        $this->assertSame('en', $this->runWithCookie('en'));
        $this->assertSame('ko', $this->runWithCookie('ko'));
    }

    public function test_invalid_or_missing_cookie_falls_back(): void
    {
        config(['app.locale' => 'en']);
        $this->assertSame('en', $this->runWithCookie(null));
        $this->assertSame('en', $this->runWithCookie('zz'));
    }
}
