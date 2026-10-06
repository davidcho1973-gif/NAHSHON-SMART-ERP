<?php

namespace App\OAuth;

use App\Models\User;
use App\Support\Mcp\ErpMcpOAuth;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class ErpAccessTokenRepository extends AccessTokenRepository
{
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        Passport::token()->forceFill([
            'id' => $id = $accessTokenEntity->getIdentifier(),
            'user_id' => $userId = $accessTokenEntity->getUserIdentifier(),
            'client_id' => $clientId = $accessTokenEntity->getClient()->getIdentifier(),
            'scopes' => $accessTokenEntity->getScopes(),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
            'resource' => ErpMcpOAuth::resource(),
            'issuer' => ErpMcpOAuth::issuer(),
            'family_id' => request()->attributes->get('erp_mcp_token_family'),
        ])->save();
        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        // This applies to code exchange AND refresh, before anything is persisted.
        if (! config('erp_mcp.enabled') || ! ErpMcpOAuth::configured()
            || request()->attributes->get('erp_mcp_oauth_request') !== true
            || ! is_string(request()->attributes->get('erp_mcp_token_family'))
            || ErpMcpOAuth::client($clientEntity->getIdentifier()) === null
            || ! ErpMcpOAuth::eligible($userIdentifier === null ? null : User::query()->find($userIdentifier))) {
            throw OAuthServerException::accessDenied('ERP access is no longer approved.');
        }
        if (array_map(fn ($scope) => $scope->getIdentifier(), $scopes) !== [ErpMcpOAuth::SCOPE]) {
            throw OAuthServerException::invalidScope(ErpMcpOAuth::SCOPE);
        }

        return parent::getNewToken($clientEntity, $scopes, $userIdentifier);
    }
}
