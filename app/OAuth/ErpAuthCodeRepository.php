<?php

namespace App\OAuth;

use App\Support\Mcp\McpOAuth;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/** Bind each grant to its original issuer/resource, including across config changes. */
class ErpAuthCodeRepository extends AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            'resource' => McpOAuth::resource(),
            'issuer' => McpOAuth::issuer(),
            'family_id' => ErpTokenFamily::create(),
        ])->save();
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $code = Passport::authCode()->newQuery()->find($codeId);
        if (! $code || ! ErpTokenFamily::lock($code->family_id)) {
            return true;
        }
        $code = $code->fresh();
        if ($code->revoked) {
            ErpTokenFamily::revoke($code->family_id);

            return true;
        }
        if ($code->resource !== McpOAuth::resource() || $code->issuer !== McpOAuth::issuer()
            || ! $code->expires_at || $code->expires_at->isPast()) {
            return true;
        }
        request()->attributes->set('erp_mcp_token_family', $code->family_id);

        return false;
    }
}
