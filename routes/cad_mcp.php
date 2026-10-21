<?php

use App\Http\Controllers\Mcp\CadConnectionController;
use App\Http\Controllers\Mcp\CadOAuthController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Middleware\AuthenticateErpMcp;
use App\Http\Middleware\EnsureErpMcpEnabled;
use App\Http\Middleware\RequireErpMcpConsentActor;
use App\Http\Middleware\SelectCadMcpProfile;
use App\Http\Middleware\ValidateErpMcpConsent;
use App\Http\Middleware\ValidateErpMcpOAuthRequest;
use App\Mcp\Servers\CadSessionServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// Separate issuer, resource, scope and client population preserve ERP read-only grants.
Route::middleware([SelectCadMcpProfile::class, EnsureErpMcpEnabled::class])->group(function (): void {
    Route::get('/.well-known/oauth-protected-resource/mcp/cad', [OAuthMetadataController::class, 'resource']);
    Route::get('/.well-known/oauth-authorization-server/oauth/cad', [OAuthMetadataController::class, 'authorizationServer']);
    Route::post('/oauth/cad/register', [CadConnectionController::class, 'register'])->middleware('throttle:10,60');
    Route::post('/oauth/cad/token', [CadOAuthController::class, 'token'])
        ->middleware(['throttle:30,1', ValidateErpMcpOAuthRequest::class.':token'])->name('cad-mcp.oauth.token');
    Route::middleware(['web', 'auth:web', RequireErpMcpConsentActor::class])->group(function (): void {
        Route::get('/oauth/cad/authorize', [CadOAuthController::class, 'authorize'])
            ->middleware(['throttle:20,1', ValidateErpMcpOAuthRequest::class.':authorize'])->name('cad-mcp.oauth.authorize');
        Route::post('/oauth/cad/authorize', [CadOAuthController::class, 'approve'])
            ->middleware(ValidateErpMcpConsent::class)->name('cad-mcp.oauth.approve');
        Route::delete('/oauth/cad/authorize', [CadOAuthController::class, 'deny'])
            ->middleware(ValidateErpMcpConsent::class)->name('cad-mcp.oauth.deny');
    });
    Route::middleware(['throttle:erp-mcp-ip', AuthenticateErpMcp::class, 'throttle:erp-mcp-user'])->group(function (): void {
        Mcp::web('/mcp/cad', CadSessionServer::class)->withoutMiddleware(AddWwwAuthenticateHeader::class);
    });
});
