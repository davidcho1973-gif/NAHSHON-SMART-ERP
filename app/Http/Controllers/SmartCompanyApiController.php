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

        return response()->json(
            SmartCompanyData::handle($method, is_array($args) ? $args : [], is_string($siteId) ? $siteId : 'ALL')
        );
    }
}
