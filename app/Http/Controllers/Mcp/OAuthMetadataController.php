<?php

namespace App\Http\Controllers\Mcp;

use App\Support\Mcp\ErpMcpOAuth;
use Illuminate\Http\JsonResponse;

class OAuthMetadataController
{
    public function resource(): JsonResponse
    {
        return response()->json([
            'resource' => ErpMcpOAuth::resource(),
            'resource_name' => 'ERP read-only data',
            'authorization_servers' => [ErpMcpOAuth::issuer()],
            'scopes_supported' => [ErpMcpOAuth::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => ErpMcpOAuth::issuer(),
            'authorization_endpoint' => ErpMcpOAuth::issuer().'/oauth/erp/authorize',
            'token_endpoint' => ErpMcpOAuth::issuer().'/oauth/erp/token',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [ErpMcpOAuth::SCOPE],
            // Passport does not add iss to every error redirect. Do not claim RFC 9207.
            'authorization_response_iss_parameter_supported' => false,
            'client_id_metadata_document_supported' => false,
        ]);
    }
}
