<?php

namespace App\Http\Controllers;

use App\Support\SmartCompanyData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartCompanyApiController extends Controller
{
    /**
     * 열람 전용 역할이 호출할 수 있는 엔드포인트.
     *
     * 조회는 관례상 전부 `api_get*` 이라 접두사로 잡고, 예외적으로 이름이 다른 조회만 나열한다.
     * 즉 기본은 "거부" 이므로 새 쓰기 엔드포인트가 생겨도 원청 계정에 자동으로 열리지 않는다.
     */
    private const READ_ONLY_PREFIX = 'api_get';

    /** @var array<int, string> */
    private const READ_ONLY_EXTRA = ['api_searchDocs'];

    public function __invoke(Request $request, string $method): JsonResponse
    {
        $args = $request->input('args', []);
        $siteId = $request->input('siteId', 'ALL');

        if ($request->user()?->isReadOnly() && ! $this->isReadEndpoint($method)) {
            return response()->json([
                'success' => false,
                'error' => '열람 전용 계정입니다. 데이터를 변경할 수 없습니다.',
            ], 403);
        }

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

    private function isReadEndpoint(string $method): bool
    {
        return str_starts_with($method, self::READ_ONLY_PREFIX)
            || in_array($method, self::READ_ONLY_EXTRA, true);
    }
}
