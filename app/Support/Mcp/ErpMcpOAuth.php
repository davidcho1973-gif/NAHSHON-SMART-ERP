<?php

namespace App\Support\Mcp;

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

final class ErpMcpOAuth
{
    public const SCOPE = 'erp:read';

    public static function issuer(): string
    {
        return (string) config('erp_mcp.issuer');
    }

    public static function resource(): string
    {
        return (string) config('erp_mcp.resource');
    }

    public static function configured(): bool
    {
        $issuer = self::issuer();
        $parts = parse_url($issuer);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && empty($parts['path'])
            && self::resource() === $issuer.'/mcp/erp';
    }

    public static function eligible(?User $user): bool
    {
        return $user !== null && $user->account_status === 'active'
            && $user->access_role === 'super_admin'
            && in_array((string) $user->getAuthIdentifier(), config('erp_mcp.user_ids', []), true)
            && (! $user->employee_id || $user->employee?->employment_status === 'active');
    }

    /** A fresh DB lookup also invalidates a revoked or reconfigured client immediately. */
    public static function client(mixed $clientId): ?Client
    {
        if (! is_string($clientId) || ! in_array($clientId, config('erp_mcp.client_ids', []), true)) {
            return null;
        }

        $client = Passport::client()->newQuery()->whereKey($clientId)->where('revoked', false)->first();
        if (! $client || $client->confidential() || ! in_array($client->provider, [null, 'users'], true)
            || ! $client->hasGrantType('authorization_code')
            || array_diff($client->grant_types, ['authorization_code', 'refresh_token'])) {
            return null;
        }

        return $client;
    }

    public static function validRedirect(Client $client, mixed $redirect): bool
    {
        if (! is_string($redirect) || ! in_array($redirect, $client->redirect_uris, true)) {
            return false;
        }

        $parts = parse_url($redirect);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ! empty($parts['host']) && ! isset($parts['fragment'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! str_contains($redirect, '*');
    }
}
