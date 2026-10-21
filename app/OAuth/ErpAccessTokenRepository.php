<?php

namespace App\OAuth;

use App\Models\User;
use App\Support\Mcp\McpOAuth;
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
            'resource' => McpOAuth::resource(),
            'issuer' => McpOAuth::issuer(),
            'family_id' => request()->attributes->get('erp_mcp_token_family'),
        ])->save();
        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        // This applies to code exchange AND refresh, before anything is persisted.
        if (! McpOAuth::enabled() || ! McpOAuth::configured()
            || request()->attributes->get('erp_mcp_oauth_request') !== true
            || ! is_string(request()->attributes->get('erp_mcp_token_family'))
            || McpOAuth::client($clientEntity->getIdentifier()) === null
            || ! McpOAuth::eligible($userIdentifier === null ? null : User::query()->find($userIdentifier))) {
            throw OAuthServerException::accessDenied('ERP access is no longer approved.');
        }
        if (array_map(fn ($scope) => $scope->getIdentifier(), $scopes) !== [McpOAuth::scope()]) {
            throw OAuthServerException::invalidScope(McpOAuth::scope());
        }

        return parent::getNewToken($clientEntity, $scopes, $userIdentifier);
    }
}
