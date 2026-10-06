<?php

namespace App\OAuth;

use App\Support\Mcp\ErpMcpOAuth;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

class ErpRefreshTokenRepository extends RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $refresh = Passport::refreshToken()->newQuery()->find($tokenId);
        $access = $refresh ? Passport::token()->newQuery()->find($refresh->access_token_id) : null;
        if (! $access || ! ErpTokenFamily::lock($access->family_id)) {
            return true;
        }
        $refresh = $refresh->fresh();
        $access = $access->fresh();
        if ($refresh->revoked) {
            ErpTokenFamily::revoke($access->family_id);

            return true;
        }
        if ($access->revoked || $access->resource !== ErpMcpOAuth::resource()
            || $access->issuer !== ErpMcpOAuth::issuer()
            || ! $refresh->expires_at || $refresh->expires_at->isPast()) {
            return true;
        }
        request()->attributes->set('erp_mcp_token_family', $access->family_id);

        return false;
    }
}
