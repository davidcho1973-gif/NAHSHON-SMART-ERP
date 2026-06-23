<?php

namespace App\Http\Controllers;

use App\Support\SmartCompanyData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartCompanyApiController extends Controller
{
    public function __invoke(Request $request, string $method): JsonResponse
    {
        $args = $request->input('args', []);
        $siteId = $request->input('siteId', 'ALL');

        try {
            $result = SmartCompanyData::handle(
                $method,
                is_array($args) ? $args : [],
                is_string($siteId) ? $siteId : 'ALL'
            );

            // The SPA reads `.success`/`.error` off the parsed JSON body, so a bare
            // null (or non array/string) body throws "Cannot read properties of null".
            // Always hand back a structured payload instead.
            if (! is_array($result) && ! is_string($result)) {
                $result = ['success' => false, 'error' => "Endpoint '{$method}' returned no data."];
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            report($e);

            // Return HTTP 200 with a structured error so the front-end surfaces the
            // real message rather than a generic transport failure.
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
