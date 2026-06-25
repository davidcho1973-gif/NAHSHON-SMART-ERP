<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Services\AttendanceQrService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceAppController extends Controller
{
    public function __construct(private readonly AttendanceQrService $attendanceQrService)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $employee = $user?->employee;
        $today = Carbon::today()->toDateString();

        $todayLogs = $employee
            ? AttendanceLog::query()
                ->with(['dailyWorkAssignment.site', 'dailyWorkAssignment.team', 'dailyWorkAssignment.siteContractor'])
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $today)
                ->orderBy('event_at', 'desc')
                ->limit(8)
                ->get()
            : collect();

        return view('attendance-app.index', [
            'user' => $user,
            'employee' => $employee,
            'todayLogs' => $todayLogs,
            'canProcessCrew' => $user ? $this->attendanceQrService->canProcessCrew($user) : false,
        ]);
    }

    public function team(Request $request, string $token): View|RedirectResponse
    {
        $qrCode = AttendanceQrCode::activeForToken($token);
        if (! $qrCode) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This attendance QR is inactive or invalid.');
        }

        return view('attendance-app.team', [
            'qrCode' => $qrCode,
            'token' => $token,
            'employee' => $request->user()?->employee,
            'result' => session('attendance_result'),
            'error' => session('attendance_error'),
            'canProcessCrew' => $this->attendanceQrService->canProcessCrew($request->user(), $qrCode),
        ]);
    }

    public function recordTeam(Request $request, string $token): RedirectResponse
    {
        $qrCode = AttendanceQrCode::activeForToken($token);
        if (! $qrCode) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This attendance QR is inactive or invalid.');
        }

        try {
            $result = $this->attendanceQrService->recordSelfScan(
                $request->user(),
                $qrCode,
                (string) $request->input('mode', 'auto'),
            );

            return redirect()->route('attendance-app.team', ['token' => $token])->with('attendance_result', $this->viewResult($result));
        } catch (\Throwable $exception) {
            return redirect()->route('attendance-app.team', ['token' => $token])->with('attendance_error', $exception->getMessage());
        }
    }

    public function crew(Request $request, string $token): View|RedirectResponse
    {
        $qrCode = AttendanceQrCode::activeForToken($token);
        if (! $qrCode) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This attendance QR is inactive or invalid.');
        }

        if (! $this->attendanceQrService->canProcessCrew($request->user(), $qrCode)) {
            return redirect()->route('attendance-app.team', ['token' => $token])->with('attendance_error', 'You do not have crew attendance permission for this QR.');
        }

        $recentLogs = AttendanceLog::query()
            ->with('employee')
            ->where('attendance_qr_code_id', $qrCode->id)
            ->whereDate('attendance_date', Carbon::today($qrCode->site?->timezone ?: config('app.timezone'))->toDateString())
            ->orderBy('event_at', 'desc')
            ->limit(20)
            ->get();

        return view('attendance-app.crew', [
            'qrCode' => $qrCode,
            'token' => $token,
            'recentLogs' => $recentLogs,
            'result' => session('attendance_result'),
            'error' => session('attendance_error'),
        ]);
    }

    public function recordCrew(Request $request, string $token): RedirectResponse
    {
        $qrCode = AttendanceQrCode::activeForToken($token);
        if (! $qrCode) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This attendance QR is inactive or invalid.');
        }

        $request->validate([
            'badge_token' => ['required', 'string'],
            'mode' => ['nullable', 'in:auto,clock_in,clock_out'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $badgeTokenValue = $this->extractToken((string) $request->input('badge_token'));
        $badgeToken = EmployeeBadgeQrToken::activeForToken($badgeTokenValue);

        if (! $badgeToken) {
            return redirect()->route('attendance-app.crew', ['token' => $token])->with('attendance_error', 'Worker badge QR is inactive or invalid.');
        }

        try {
            $result = $this->attendanceQrService->recordForemanBadgeScan(
                $request->user(),
                $qrCode,
                $badgeToken,
                (string) $request->input('mode', 'auto'),
                $request->input('reason') ?: null,
            );

            return redirect()->route('attendance-app.crew', ['token' => $token])->with('attendance_result', $this->viewResult($result));
        } catch (\Throwable $exception) {
            return redirect()->route('attendance-app.crew', ['token' => $token])->with('attendance_error', $exception->getMessage());
        }
    }

    public function badge(Request $request, string $token): View|RedirectResponse
    {
        $badgeToken = EmployeeBadgeQrToken::activeForToken($token);
        if (! $badgeToken) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This worker badge QR is inactive or invalid.');
        }

        return view('attendance-app.badge', [
            'badgeToken' => $badgeToken,
            'employee' => $badgeToken->employee,
        ]);
    }

    public function employeeBadgeQr(Request $request, Employee $employee): View
    {
        $user = $request->user();
        abort_unless(
            in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'], true)
                || ($user && (int) $user->employee_id === (int) $employee->id),
            403,
        );

        $badgeToken = EmployeeBadgeQrToken::activeForEmployee($employee, $request->user()?->id);

        return view('attendance-app.badge-qr', [
            'employee' => $employee->loadMissing(['company', 'site']),
            'badgeToken' => $badgeToken,
            'badgeUrl' => route('attendance-app.badge', ['token' => $badgeToken->token]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function viewResult(array $result): array
    {
        return [
            'ignored' => (bool) ($result['ignored'] ?? false),
            'employee_name' => $result['employee']->name ?? null,
            'event_type' => $result['event_type'] ?? null,
            'event_at' => isset($result['event_at']) ? Carbon::parse($result['event_at'])->format('m/d/Y h:i A') : null,
            'status' => $result['status'] ?? null,
            'message' => $result['message'] ?? null,
        ];
    }

    private function extractToken(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '/attendance-app/badge/')) {
            $path = (string) parse_url($value, PHP_URL_PATH);

            return trim((string) str($path)->afterLast('/attendance-app/badge/'), " \t\n\r\0\x0B/");
        }

        return trim($value, " \t\n\r\0\x0B/");
    }
}
