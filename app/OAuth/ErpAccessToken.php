<?php

namespace App\OAuth;

use App\Support\Mcp\ErpMcpOAuth;
use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use LogicException;

/** Passport's default JWT has only a client audience, not a resource or issuer. */
class ErpAccessToken extends AccessToken
{
    private CryptKeyInterface $erpSigningKey;

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        parent::setPrivateKey($privateKey);
        $this->erpSigningKey = $privateKey;
    }

    public function toString(): string
    {
        if (! ErpMcpOAuth::configured() || $this->getUserIdentifier() === null) {
            throw new LogicException('ERP OAuth requires a canonical resource and a user.');
        }
        $jwt = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->erpSigningKey->getKeyContents(), $this->erpSigningKey->getPassPhrase() ?? ''),
            InMemory::plainText('unused'),
        );
        $now = new DateTimeImmutable;

        return $jwt->builder()
            ->issuedBy(ErpMcpOAuth::issuer())
            // League/Passport reads aud[0] as the client ID. Keep that contract.
            ->permittedFor($this->getClient()->getIdentifier(), ErpMcpOAuth::resource())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($jwt->signer(), $jwt->signingKey())->toString();
    }
}
