<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Site;
use App\Services\Attendance\GateAttendanceService;
use App\Support\QrPosters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 게이트 QR 출퇴근 — 현장 출입구 QR(로그인 불필요). 이름으로 본인을 찾아 출근/퇴근을 찍는다.
 */
class GateAttendanceController extends Controller
{
    public function __construct(private readonly GateAttendanceService $service)
    {
    }

    /** 출입구 게이트 페이지(모바일). */
    public function show(Site $site): View
    {
        return view('gate.index', ['site' => $site]);
    }

    /** 인쇄용 게이트 QR 포스터. */
    public function qr(Site $site): View
    {
        return view('gate.qr', [
            'site' => $site,
            'poster' => QrPosters::make($site, QrPosters::GATE),
        ]);
    }

    /** 이름/소속 자동완성. */
    public function search(Request $request, Site $site): JsonResponse
    {
        $q = (string) $request->query('q', $request->input('q', ''));

        return response()->json(['success' => true, 'workers' => $this->service->search($site, $q)->all()]);
    }

    /** 출근/퇴근 기록(자동 판별). */
    public function punch(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $employee = Employee::query()->where('id', $data['employee_id'])->where('site_id', $site->id)->first();
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '작업자를 찾을 수 없습니다.'], 404);
        }

        $lat = isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null;

        return response()->json($this->service->punch($employee, $site, $lat, $lng));
    }
}
