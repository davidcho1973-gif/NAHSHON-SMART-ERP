<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Models\User;
use App\Services\Admin\PayProfileService;
use App\Services\Attendance\WorkerAttendanceService;
use App\Services\AttendanceQrService;
use App\Services\Communication\CommunicationService;
use App\Services\DailyCrewReportService;
use App\Services\Hr\SelfEmployeeLink;
use App\Support\AppLocale;
use App\Support\QrSvg;
use App\Support\WorkerLang;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceAppController extends Controller
{
    /** 이 역할이면 화면에서 바로 "인원관리에서 연결하세요" 라고 안내할 수 있다. */
    private const SETUP_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    public function __construct(
        private readonly AttendanceQrService $attendanceQrService,
        private readonly CommunicationService $communicationService,
        private readonly DailyCrewReportService $dailyCrewReportService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $viewingAs = $this->viewAsEmployee($request);
        $employee = $viewingAs ?? $user?->employee;

        return view('attendance-app.index', [
            'viewingAs' => $viewingAs,
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
     * 슈퍼관리자가 "이 사람 화면" 을 그대로 보는 기능(?as=직원ID).
     *
     * 왜 필요한가 — 관리자 계정에는 직원 기록이 안 붙어 있어서, 만들어 놓은 화면을
     * 정작 만든 사람이 못 본다. 확인하려고 자기 계정을 아무 직원에게 붙이면 그 직원의
     * 진짜 기록이 섞인다. 그래서 붙이지 않고 들여다보는 길을 따로 낸다.
     *
     * 보기 전용이다. punch() 가 이 상태를 막는다 — 화면을 둘러보다 누른 버튼 하나가
     * 남의 근무시간이 되면 나중에 아무도 그게 본인이 찍은 것인지 구별할 수 없다.
     *
     * 이 화면에는 그 사람의 시급과 급여가 그대로 나온다. 그래서 급여를 이미 볼 수 있는
     * 역할에만 연다 — 같은 상수를 쓴다. 여기에만 따로 목록을 적어 두면 나중에 급여
     * 권한이 바뀔 때 한쪽만 고쳐져 어긋난다.
     */
    private function viewAsEmployee(Request $request): ?Employee
    {
        return $this->viewAsAllowed($request) ? Employee::query()->find($request->query('as')) : null;
    }

    /** ?as= 를 줬는데 역할이 안 되는 경우 — 화면이 그 이유를 말할 수 있어야 한다. */
    private function viewAsRefused(Request $request): bool
    {
        return filled($request->query('as')) && ! $this->viewAsAllowed($request);
    }

    private function viewAsAllowed(Request $request): bool
    {
        return filled($request->query('as'))
            && in_array($request->user()?->access_role, PayProfileService::VIEW_ROLES, true);
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
        $user = $request->user();
        $viewingAs = $this->viewAsEmployee($request);
        $employee = $viewingAs ?? $user?->employee;

        if (! $employee) {
            // 이건 고장이 아니라 설정이 덜 된 상태다. 화면이 그렇게 말해야 한다 —
            // 빨간 오류 상자를 띄우면 관리자는 앱이 깨진 줄 알고, 작업자는 자기가
            // 뭘 잘못했다고 생각한다.
            return response()->json([
                'success' => false,
                'code' => $this->viewAsRefused($request) ? 'view_as_denied' : 'no_employee',
                // 자기 역할을 볼 방법이 없으면 "왜 안 되는지" 를 영원히 못 알아낸다.
                'role' => $user ? (User::ROLE_OPTIONS[$user->access_role] ?? $user->access_role) : null,
                'allowedRoles' => array_values(array_map(
                    fn (string $r): string => User::ROLE_OPTIONS[$r] ?? $r,
                    PayProfileService::VIEW_ROLES,
                )),
                // 지금 어느 계정으로 들어와 있는지. 휴대폰에 구글 계정이 여러 개면
                // 엉뚱한 것으로 로그인해 놓고 원인을 못 찾는다.
                'email' => $user?->email,
                'canManage' => in_array($user?->access_role, self::SETUP_ROLES, true),
                // 인원을 관리할 수 있는 사람은 여기서 바로 자기 직원 정보를 만든다.
                // 앱 관리를 겸하는 소장에게 «남에게 부탁하라» 고 말하는 화면은 틀렸다 —
                // 그 사람이 바로 그 «남» 이다.
                'canSelfLink' => $user instanceof User && app(SelfEmployeeLink::class)->allowed($user),
                'selfLink' => $user instanceof User && app(SelfEmployeeLink::class)->allowed($user)
                    ? app(SelfEmployeeLink::class)->options($user)
                    : null,
                'error' => '이 계정은 아직 작업자와 연결되지 않았습니다.',
            ], 422);
        }

        return response()->json($worker->home($employee) + [
            // 화면이 "지금 남의 것을 보고 있다" 고 말할 수 있게.
            'viewingAs' => $viewingAs ? ['id' => $viewingAs->getKey(), 'name' => $viewingAs->name] : null,
        ]);
    }

    /**
     * 내 직원 정보를 스스로 만들어 이 계정에 잇는다.
     *
     * 인원을 관리할 수 있는 역할에게만 열린다 — 그 사람은 어차피 ERP 에서 같은 일을
     * 할 수 있으므로 새로 열리는 권한은 없고, 화면을 옮겨 다닐 필요가 없어질 뿐이다.
     */
    public function selfLink(Request $request, SelfEmployeeLink $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json($link->create($user, [
            'name' => $request->input('name'),
            'siteId' => $request->input('siteId'),
            'trade' => $request->input('trade'),
            'position' => $request->input('position'),
        ]));
    }

    /**
     * 2단 — 직접 누르는 출퇴근. 위치를 같이 보내면 반경 안인지 보고 승인 여부를 가른다.
     */
    public function punch(Request $request, WorkerAttendanceService $worker): JsonResponse
    {
        // 남의 화면을 보는 중에는 절대 찍지 않는다. 이건 편의 문제가 아니라 임금 기록이다 —
        // 관리자가 화면을 둘러보다 누른 버튼 하나가 그 사람의 근무시간이 되면, 나중에
        // 아무도 그게 본인이 찍은 것인지 구별할 수 없다.
        if ($this->viewAsEmployee($request)) {
            return response()->json([
                'success' => false,
                'error' => '다른 사람의 화면을 보는 중입니다. 여기서는 출퇴근을 찍을 수 없습니다.',
            ], 403);
        }

        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '연결된 직원 정보가 없습니다.'], 422);
        }

        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            // 화면에서 고른 언어 — 응답 문구를 그 언어로 만든다.
            'lang' => ['nullable', 'in:ko,en,es'],
            // 스캔한 현장 QR. 앱의 출퇴근은 이제 버튼이 아니라 이 스캔으로만 일어난다.
            'gate_site' => ['required', 'integer'],
        ]);

        // 남의 현장 QR 로는 찍히지 않는다 — 찍었으면 그 사람은 거기 없었다.
        if ((int) $data['gate_site'] !== (int) $employee->site_id) {
            return response()->json([
                'success' => false,
                'error' => '이 현장의 QR 이 아닙니다. 배정된 현장의 출입구 QR 을 스캔해 주세요.',
            ], 422);
        }

        // 폰이 보낸 ip 는 믿지 않는다 — 서버가 본 주소로 넣는다.
        $data['ip'] = $request->ip();

        return response()->json($worker->punch($employee, $data['direction'], $data, $data['lang'] ?? 'ko'));
    }

    /**
     * 출근 시각 정정 요청 — "실제로는 더 일찍 왔다". 기록을 바로 고치지 않고
     * 확인 대기로 돌린다. 임금 기록은 본인 신고만으로 바뀌면 안 된다.
     */
    /**
     * 앱에서 고른 언어를 저장한다 — 쿠키(이 브라우저)와 직원 정보(이 사람) 양쪽에.
     *
     * 쿠키만 두면 서버가 그리는 화면은 따라오지만 폰을 바꾸면 사라진다. 직원 정보에만
     * 두면 폰을 바꿔도 따라오지만 로그인 전 화면이 모른다. 그래서 둘 다 쓴다.
     */
    public function language(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lang' => ['required', 'string', 'in:'.implode(',', AppLocale::SUPPORTED)],
        ]);

        $locale = (string) $data['lang'];

        // 다른 사람의 화면을 보는 중이면 그 사람의 언어를 바꾸지 않는다 — 쿠키만 바꾼다.
        if (! $this->viewAsEmployee($request)) {
            $employee = $request->user()?->employee;
            if ($employee && $employee->preferred_language !== $locale) {
                $employee->forceFill(['preferred_language' => $locale])->save();
            }
        }

        return response()
            ->json(['success' => true, 'lang' => $locale])
            ->cookie(AppLocale::COOKIE, $locale, AppLocale::COOKIE_MINUTES);
    }

    public function requestCorrection(Request $request, WorkerAttendanceService $worker): JsonResponse
    {
        if ($this->viewAsEmployee($request)) {
            return response()->json(['success' => false, 'error' => '다른 사람의 화면을 보는 중입니다.'], 403);
        }

        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->json(['success' => false, 'error' => '연결된 직원 정보가 없습니다.'], 422);
        }

        $data = $request->validate([
            'time' => ['required', 'string', 'max:5'],
            'lang' => ['nullable', 'in:ko,en,es'],
        ]);

        return response()->json($worker->requestCorrection($employee, $data['time'], $data['lang'] ?? 'ko'));
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
     * 작업자에게 "보내는" 화면 — 링크·QR·문자 문구가 한 자리에.
     *
     * 인쇄 카드와 목적이 다르다. 카드는 손에 쥐여 주는 종이고, 이건 문자·왓츠앱으로
     * 보내는 것이다. 직영은 사람이 정해져 있어 대개 반장이 그 자리에서 보낸다.
     *
     * 세 가지가 한 화면에 있어야 한다.
     *   링크   — 눌러서 바로 복사(주소를 손으로 옮겨 적다 오타가 난다)
     *   QR    — 반장 휴대폰을 보여 주고 작업자가 스캔(같이 있을 때 가장 빠르다)
     *   문구   — 3개 국어. 링크만 덜렁 보내면 그게 무엇인지 몰라서 안 누른다.
     */
    public function shareLink(Request $request, Employee $employee): View
    {
        $user = $request->user();
        abort_unless(
            in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager'], true)
                || ($user && (int) $user->employee_id === (int) $employee->id),
            403,
        );

        $url = route('attendance-app.index');
        $account = User::query()->where('employee_id', $employee->getKey())->first();

        return view('attendance-app.share', [
            'employee' => $employee->loadMissing('site'),
            'url' => $url,
            'qrImage' => QrSvg::dataUri($url, 320),
            'loginEmail' => $account && $account->account_status === 'active' ? $account->email : null,
            // 직원 정보의 이메일과 로그인 계정이 다를 수 있다. 다르면 반장은 방금
            // 직원 정보를 고쳐 놓고 여기서 옛 주소를 보게 되는데, 어느 쪽도 틀려
            // 보이지 않아 원인을 못 찾는다. 여기서 말해 준다.
            'employeeEmail' => $employee->email,
            'messages' => WorkerLang::shareMessage($url),
            // 번호가 있으면 그 사람에게 바로 열린다. 없으면 반장이 고른다.
            'dial' => $employee->dialNumber(),
            'lang' => WorkerLang::resolve($employee->preferred_language),
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
            // 직원 정보의 이메일과 로그인 계정이 다를 수 있다. 다르면 반장은 방금
            // 직원 정보를 고쳐 놓고 여기서 옛 주소를 보게 되는데, 어느 쪽도 틀려
            // 보이지 않아 원인을 못 찾는다. 여기서 말해 준다.
            'employeeEmail' => $employee->email,
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
