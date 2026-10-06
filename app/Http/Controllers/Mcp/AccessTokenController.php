<?php

namespace App\Http\Controllers\Mcp;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AccessTokenController
{
    use ConvertsPsrResponses;

    public function __construct(private readonly AuthorizationServer $server) {}

    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        return DB::connection(config('passport.connection'))->transaction(function () use ($psrRequest, $psrResponse): Response {
            try {
                return $this->convertResponse($this->server->respondToAccessTokenRequest($psrRequest, $psrResponse));
            } catch (OAuthServerException $exception) {
                // Commit replay revocation even when the OAuth response is an error.
                // Unexpected errors still roll back the whole issuance transaction.
                return $this->convertResponse($exception->generateHttpResponse($psrResponse));
            }
        });
    }
}
