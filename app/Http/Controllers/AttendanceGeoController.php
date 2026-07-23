<?php

namespace App\Http\Controllers;

use App\Services\Attendance\AttendanceGeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 작업자 앱 → 서버: 위치/WiFi 신호 수신 + 현재 상태 조회.
 * (Phase 2 의 Capacitor 앱이 백그라운드 지오펜스로 이 엔드포인트를 호출한다. 지금은 웹/테스트로도 가능.)
 */
class AttendanceGeoController extends Controller
{
    public function __construct(private readonly AttendanceGeoService $service)
    {
    }

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
