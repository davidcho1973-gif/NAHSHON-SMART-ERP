<?php

namespace App\Support\Mcp;

use App\Models\User;
use Laravel\Passport\Client;

/** Route-selected OAuth profile. Input parameters can never select a trust boundary. */
final class McpOAuth
{
    public static function cad(): bool
    {
        return request()->attributes->get('cad_mcp_profile') === true;
    }

    private static function profile(): string
    {
        return self::cad() ? CadMcpOAuth::class : ErpMcpOAuth::class;
    }

    public static function scope(): string
    {
        return self::profile()::SCOPE;
    }

    public static function issuer(): string
    {
        return self::profile()::issuer();
    }

    public static function resource(): string
    {
        return self::profile()::resource();
    }

    public static function configured(): bool
    {
        return self::profile()::configured();
    }

    public static function eligible(?User $user): bool
    {
        return self::profile()::eligible($user);
    }

    public static function client(mixed $id): ?Client
    {
        return self::profile()::client($id);
    }

    public static function validRedirect(Client $client, mixed $redirect): bool
    {
        return ErpMcpOAuth::validRedirect($client, $redirect);
    }

    public static function enabled(): bool
    {
        return (bool) config(self::cad() ? 'cad_ai.enabled' : 'erp_mcp.enabled');
    }

    public static function origin(): string
    {
        return self::cad() ? CadMcpOAuth::origin() : ErpMcpOAuth::issuer();
    }

    public static function metadata(): string
    {
        return self::origin().'/.well-known/oauth-protected-resource/mcp/'.(self::cad() ? 'cad' : 'erp');
    }

    public static function allowedOrigins(): array
    {
        return config(self::cad() ? 'cad_ai.allowed_origins' : 'erp_mcp.allowed_origins', []);
    }

    public static function authorizationEndpoint(): string
    {
        return self::cad() ? self::issuer().'/authorize' : self::issuer().'/oauth/erp/authorize';
    }

    public static function tokenEndpoint(): string
    {
        return self::cad() ? self::issuer().'/token' : self::issuer().'/oauth/erp/token';
    }
}
