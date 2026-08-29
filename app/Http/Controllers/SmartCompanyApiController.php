<?php

namespace App\Http\Controllers;

use App\Services\Admin\EmployeeAdminService;
use App\Support\AccessPolicy;
use App\Support\SmartCompanyData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartCompanyApiController extends Controller
{
    /**
     * 이 창구를 지나려면 어떤 역할이어야 하는가 — 메서드별 관문.
     *
     * 지금까지 이 창구의 유일한 검사는 "읽기냐 쓰기냐" 였다. 그래서 로그인만 하면
     * 누구든 전 직원 급여와 인사 명부를 받아 갈 수 있었다. 화면(SPA)은 역할에 따라
     * 메뉴를 감추지만, 창구를 직접 부르면 그 가림막은 아무 소용이 없다.
     *
     * 여기 없는 이름은 지금처럼 통과시킨다(막을 것만 적는 방식). 217개를 한 번에
     * 화이트리스트로 바꾸면 하나만 빠뜨려도 멀쩡히 쓰던 화면이 죽는다 — 위험한
     * 것부터 좁혀 들어가는 편이 안전하다.
     *
     * 역할 목록은 새로 만들지 않고 이미 그 기능이 쓰는 정본을 그대로 부른다.
     *
     * @var array<string, array<int, string>>
     */
    private const GATES = [
        // [금전] 같은 데이터의 PDF 라우트는 이미 재무 권한자만 열 수 있다 —
        // 화면용 창구만 새고 있었다.
        'api_getPayrollDashboard' => AccessPolicy::MONEY_ROLES,

        // [인사] 이름·연락처·국적·비자 만료일이 함께 나가는 조회들.
        // '직원 명부를 볼 수 있는 사람'의 기존 정본을 그대로 쓴다.
        'api_getHRData' => EmployeeAdminService::VIEW_ROLES,
        'api_getPersonnelStats' => EmployeeAdminService::VIEW_ROLES,
        'api_getEmployeeDetail' => EmployeeAdminService::VIEW_ROLES,
        'api_getPersonnelCard' => EmployeeAdminService::VIEW_ROLES,
        'api_getHrDirectory' => EmployeeAdminService::VIEW_ROLES,
        'api_getGlobalAttendance' => EmployeeAdminService::VIEW_ROLES,
        'api_getAttendanceLive' => EmployeeAdminService::VIEW_ROLES,
        'api_getAttendanceDetailed' => EmployeeAdminService::VIEW_ROLES,
        'api_getDailyTeamMatrix' => EmployeeAdminService::VIEW_ROLES,
        'api_getDailyAttendanceDetail' => EmployeeAdminService::VIEW_ROLES,
    ];

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

        $required = self::GATES[$method] ?? null;
        if ($required !== null && ! in_array($request->user()?->access_role, $required, true)) {
            // 조회 거부는 200 으로 돌려준다 — 403 을 던지면 화면 스크립트가 통신 실패로
            // 보고 페이지를 통째로 비운다. 쓰기는 위 열람전용 게이트와 같이 403 이다.
            return response()->json([
                'success' => false,
                'error' => '이 자료를 볼 권한이 없습니다. 필요하면 관리자에게 요청하세요.',
            ], $this->isReadEndpoint($method) ? 200 : 403);
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
