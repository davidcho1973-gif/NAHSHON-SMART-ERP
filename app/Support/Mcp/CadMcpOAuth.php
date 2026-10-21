<?php

namespace App\Support\Mcp;

use App\Models\User;
use App\Services\Drawings\CadDrawingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CadMcpOAuth
{
    public const SCOPE = 'cad:session';

    public static function origin(): string
    {
        return (string) config('cad_ai.origin');
    }

    public static function issuer(): string
    {
        return self::origin().'/oauth/cad';
    }

    public static function resource(): string
    {
        return self::origin().'/mcp/cad';
    }

    public static function configured(): bool
    {
        $p = parse_url(self::origin());

        return is_array($p) && ($p['scheme'] ?? null) === 'https' && ! empty($p['host'])
            && empty($p['path'])
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['query']) && ! isset($p['fragment']);
    }

    public static function eligible(?User $user): bool
    {
        if (! $user || $user->account_status !== 'active'
            || ($user->employee_id && $user->employee?->employment_status !== 'active')) {
            return false;
        }
        try {
            app(CadDrawingService::class)->gate($user);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    public static function client(mixed $id): ?Client
    {
        if (! is_string($id) || ! Str::isUuid($id) || ! DB::table('cad_ai_oauth_clients')->where('client_id', $id)->exists()) {
            return null;
        }
        $client = Passport::client()->newQuery()->whereKey($id)->where('revoked', false)->first();

        return $client && ! $client->confidential() && $client->provider === 'users'
            && $client->hasGrantType('authorization_code')
            && ! array_diff($client->grant_types, ['authorization_code', 'refresh_token']) ? $client : null;
    }

    public static function keysReady(): bool
    {
        $keys = [];
        foreach (['private', 'public'] as $type) {
            $value = str_replace('\\n', "\n", config('passport.'.$type.'_key') ?? '');
            if (! $value) {
                $path = Passport::keyPath('oauth-'.$type.'.key');
                if (! is_readable($path)) {
                    return false;
                }
                $value = file_get_contents($path);
            }
            $key = $type === 'private' ? @openssl_pkey_get_private($value) : @openssl_pkey_get_public($value);
            if ($key === false) {
                return false;
            }
            $keys[$type] = openssl_pkey_get_details($key);
        }

        return $keys['private']['type'] === OPENSSL_KEYTYPE_RSA
            && $keys['private']['bits'] >= 2048
            && hash_equals($keys['private']['key'], $keys['public']['key']);
    }
}
