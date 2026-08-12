<?php

namespace App\Http\Controllers;

use App\Services\Attendance\AttendanceGeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 작업자 앱 → 서버: 위치/네트워크 신호 수신 + 현재 상태 조회.
 *
 * 브라우저와 네이티브 앱이 같은 문으로 들어온다. 다른 점은 앱이 BSSID 를 같이 보낼 수
 * 있다는 것뿐이다 — 브라우저는 못 보낸다. 그래서 공인 IP 는 폰이 보내는 값을 믿지 않고
 * 여기서 요청에서 직접 읽어 넣는다. 폰이 조작할 수 없는 유일한 네트워크 단서다.
 */
class AttendanceGeoController extends Controller
{
    public function __construct(private readonly AttendanceGeoService $service) {}

    public function ping(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '연결된 직원 정보가 없습니다.'], 422);
        }

        $data = $request->validate([
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            'bssid' => ['nullable', 'string', 'max:32'],
            'ssid' => ['nullable', 'string', 'max:64'],
            'isMocked' => ['nullable'],
            'clientTs' => ['nullable', 'numeric'],
        ]);

        // 클라이언트가 ip 를 보내와도 무시하고 서버가 본 주소로 덮어쓴다.
        $data['ip'] = $request->ip();

        return response()->json($this->service->record($employee, $data));
    }

    public function status(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '연결된 직원 정보가 없습니다.'], 422);
        }

        return response()->json($this->service->status($employee));
    }
}
