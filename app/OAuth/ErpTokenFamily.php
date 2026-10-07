<?php

namespace App\OAuth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/** One serialization point for redemption and replay revocation of a complete grant. */
final class ErpTokenFamily
{
    public static function create(): string
    {
        $id = (string) Str::uuid();
        DB::connection(config('passport.connection'))->table('oauth_erp_grants')->insert([
            'id' => $id, 'revoked' => false, 'created_at' => now(),
        ]);

        return $id;
    }

    public static function lock(string $id): bool
    {
        $db = DB::connection(config('passport.connection'));
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('OAuth redemption must run in a database transaction.');
        }
        $family = $db->table('oauth_erp_grants')->where('id', $id)->lockForUpdate()->first();

        return $family !== null && ! $family->revoked;
    }

    public static function revoke(string $id): void
    {
        DB::connection(config('passport.connection'))->table('oauth_erp_grants')->where('id', $id)->update(['revoked' => true]);
        $tokens = Passport::token()->newQuery()->where('family_id', $id);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->where('family_id', $id)->update(['revoked' => true]);
    }
}
