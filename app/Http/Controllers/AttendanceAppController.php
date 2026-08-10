<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Services\Attendance\WorkerAttendanceService;
use App\Services\AttendanceQrService;
use App\Services\Communication\CommunicationService;
use App\Services\DailyCrewReportService;
use App\Models\User;
use App\Support\QrSvg;
use App\Support\WorkerLang;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceAppController extends Controller
{
    public function __construct(
        private readonly AttendanceQrService $attendanceQrService,
        private readonly CommunicationService $communicationService,
        private readonly DailyCrewReportService $dailyCrewReportService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $employee = $user?->employee;

        return view('attendance-app.index', [
            'user' => $user,
            'employee' => $employee,
            'canProcessCrew' => $user ? $this->attendanceQrService->canProcessCrew($user) : false,
            'messageUnreadCount' => $user ? $this->communicationService->unreadCountForUser($user) : 0,
            // 3단(QR)은 인터넷이 끊겼을 때 쓰는 마지막 수단이다. 그런데 그때 QR 을 받으러
            // 서버에 다녀올 수는 없다 — 끊긴 게 인터넷이기 때문이다. 그래서 화면을 열 때
            // 미리 그림째로 박아 넣는다. 한 번 연 화면은 신호가 끊겨도 QR 을 보여 준다.
            'badgeQr' => $employee ? $this->badgeQrFor($employee, $user?->id) : null,
        ]);
    }

    /**
     * 작업자 배지 QR 을 페이지에 박아 넣을 수 있는 형태로.
     *
     * @return array{uri: string, badge: string|null}|null
     */
    private function badgeQrFor(Employee $employee, ?int $userId): ?array
    {
        try {
            $token = EmployeeBadgeQrToken::activeForEmployee($employee, $userId);
            $uri = QrSvg::dataUri(route('attendance-app.badge', ['token' => $token->token]), 280);

            return $uri === '' ? null : ['uri' => $uri, 'badge' => $employee->badge_number];
        } catch (\Throwable $e) {
            // QR 을 못 만들어도 출퇴근 화면 자체는 열려야 한다. 1·2단은 그대로 쓸 수 있다.
            report($e);

            return null;
        }
    }

    /**
     * 작업자 홈 화면이 필요한 것 전부(상태 · 오늘 기록 · 현장 정보).
     *
     * 화면이 30초마다 부른다. 자동 기록이 들어오면 여기 결과가 바뀌면서 화면이 따라 바뀐다.
     */
    public function home(Request $request, WorkerAttendanceService $worker): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json([
                'success' => false,
                'error' => '이 계정에 직원 정보가 연결되어 있지 않습니다. 관리자에게 요청해 주세요.',
            ], 422);
        }

        return response()->json($worker->home($employee));
    }

    /**
     * 2단 — 직접 누르는 출퇴근. 위치를 같이 보내면 반경 안인지 보고 승인 여부를 가른다.
     */
    public function punch(Request $request, WorkerAttendanceService $worker): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '연결된 직원 정보가 없습니다.'], 422);
        }

        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
        ]);

        // 폰이 보낸 ip 는 믿지 않는다 — 서버가 본 주소로 넣는다.
        $data['ip'] = $request->ip();

        return response()->json($worker->punch($employee, $data['direction'], $data));
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
            ->whereDate('attendance_date', $this->dailyCrewReportService->todayFor($qrCode))
            ->orderBy('event_at', 'desc')
            ->limit(20)
            ->get();

        return view('attendance-app.crew', [
            'qrCode' => $qrCode,
            'token' => $token,
            'recentLogs' => $recentLogs,
            'dailyCrewSummary' => $this->dailyCrewReportService->summary($qrCode),
            'result' => session('attendance_result'),
            'dailyCrewResult' => session('daily_crew_result'),
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

    public function closeCrewDay(Request $request, string $token): RedirectResponse
    {
        $qrCode = AttendanceQrCode::activeForToken($token);
        if (! $qrCode) {
            return redirect()->route('attendance-app.index')->with('attendance_error', 'This attendance QR is inactive or invalid.');
        }

        $validated = $request->validate([
            'external_headcount' => ['required', 'integer', 'min:0', 'max:5000'],
            'manual_adjustment' => ['nullable', 'integer', 'between:-5000,5000'],
            'work_description' => ['nullable', 'string', 'max:500'],
            'adjustment_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $report = $this->dailyCrewReportService->closeDay(
                $request->user(),
                $qrCode,
                $validated,
            );

            return redirect()
                ->route('attendance-app.crew', ['token' => $token])
                ->with('daily_crew_result', [
                    'final_headcount' => $report->final_headcount,
                    'closed_at' => $report->closed_at?->format('Y-m-d H:i'),
                ]);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('attendance-app.crew', ['token' => $token])
                ->withInput()
                ->with('attendance_error', $exception->getMessage());
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
     * 직영 작업자에게 건네는 앱 설치 카드(인쇄용).
     *
     * 협력사는 게이트 포스터 한 장으로 끝나지만(계정이 없고 매일 사람이 바뀐다),
     * 직영은 사람이 정해져 있고 계정이 있다. 그래서 종이도 사람마다 나온다 —
     * 이 카드의 핵심은 QR 이 아니라 "어느 구글 계정으로 로그인하는가" 이다.
     */
    public function installCard(Request $request, Employee $employee): View
    {
        $user = $request->user();
        abort_unless(
            in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager'], true)
                || ($user && (int) $user->employee_id === (int) $employee->id),
            403,
        );

        $url = route('attendance-app.index');

        // 계정이 있어야 로그인이 된다. 없으면 카드에 그 사실을 적는다.
        $account = User::query()->where('employee_id', $employee->getKey())->first();

        return view('attendance-app.install-card', [
            'employee' => $employee->loadMissing('site'),
            'url' => $url,
            'qrImage' => QrSvg::dataUri($url, 300),
            'loginEmail' => $account && $account->account_status === 'active' ? $account->email : null,
            'langs' => WorkerLang::installCard(),
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
