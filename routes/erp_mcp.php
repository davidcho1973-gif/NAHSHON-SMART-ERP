<?php

use App\Http\Controllers\Mcp\AccessTokenController;
use App\Http\Controllers\Mcp\AuthorizationController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Middleware\AuthenticateErpMcp;
use App\Http\Middleware\EnsureErpMcpEnabled;
use App\Http\Middleware\RequireErpMcpConsentActor;
use App\Http\Middleware\ValidateErpMcpConsent;
use App\Http\Middleware\ValidateErpMcpOAuthRequest;
use App\Mcp\Servers\ErpReadOnlyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

// Dedicated routes: no DCR, personal token, transient-cookie or client-management API.
Route::middleware([EnsureErpMcpEnabled::class])->group(function (): void {
    Route::get('/.well-known/oauth-protected-resource/mcp/erp', [OAuthMetadataController::class, 'resource'])
        ->name('erp-mcp.oauth.resource');
    Route::get('/.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'authorizationServer'])
        ->name('erp-mcp.oauth.server');

    Route::post('/oauth/erp/token', [AccessTokenController::class, 'issueToken'])
        ->middleware(['throttle:30,1', ValidateErpMcpOAuthRequest::class.':token'])
        ->name('erp-mcp.oauth.token');

    Route::middleware(['web', 'auth:web', RequireErpMcpConsentActor::class])->group(function (): void {
        Route::get('/oauth/erp/authorize', [AuthorizationController::class, 'authorize'])
            ->middleware(['throttle:20,1', ValidateErpMcpOAuthRequest::class.':authorize'])
            ->name('erp-mcp.oauth.authorize');
        Route::post('/oauth/erp/authorize', [ApproveAuthorizationController::class, 'approve'])
            ->middleware(ValidateErpMcpConsent::class)->name('erp-mcp.oauth.approve');
        Route::delete('/oauth/erp/authorize', [DenyAuthorizationController::class, 'deny'])
            ->middleware(ValidateErpMcpConsent::class)->name('erp-mcp.oauth.deny');
    });

    // The outer group protects the package's GET/DELETE helpers as well as POST.
    Route::middleware(['throttle:erp-mcp-ip', AuthenticateErpMcp::class, 'throttle:erp-mcp-user'])->group(function (): void {
        // Keep our exact RFC 9728 challenge; the package fallback overwrites it.
        Mcp::web('/mcp/erp', ErpReadOnlyServer::class)->withoutMiddleware(AddWwwAuthenticateHeader::class);
    });
});
