<?php

namespace App\Http\Controllers\Mcp;

use App\Support\Mcp\McpOAuth;
use Illuminate\Http\JsonResponse;

class OAuthMetadataController
{
    public function resource(): JsonResponse
    {
        return response()->json([
            'resource' => McpOAuth::resource(),
            'resource_name' => McpOAuth::cad() ? 'ERP shared CAD sessions' : 'ERP read-only data',
            'authorization_servers' => [McpOAuth::issuer()],
            'scopes_supported' => [McpOAuth::scope()],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => McpOAuth::issuer(),
            'authorization_endpoint' => McpOAuth::authorizationEndpoint(),
            'token_endpoint' => McpOAuth::tokenEndpoint(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [McpOAuth::scope()],
            // Passport does not add iss to every error redirect. Do not claim RFC 9207.
            'authorization_response_iss_parameter_supported' => false,
            'client_id_metadata_document_supported' => false,
            ...(McpOAuth::cad() ? ['registration_endpoint' => McpOAuth::issuer().'/register'] : []),
        ]);
    }
}
