<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\WorkerEnrollment;
use App\Services\Auth\WorkerEnrollmentService;
use App\Support\QrSvg;
use Illuminate\Http\Request;

class WorkerEnrollmentController extends Controller
{
    public function __construct(private readonly WorkerEnrollmentService $enrollment) {}

    public function index(Request $request)
    {
        $teams = Team::with(['company', 'site'])->where('status', 'active')->get()
            ->filter(fn ($team) => $this->enrollment->canView($request->user(), $team));
        abort_unless($request->user()->account_status === 'active' && in_array($request->user()->access_role, ['super_admin', 'admin', 'hr_manager', 'foreman'], true), 403);
        $canRegister = in_array($request->user()->access_role, ['super_admin', 'admin', 'hr_manager'], true);
        $rows = WorkerEnrollment::with(['team', 'employee.user'])->whereIn('team_id', $teams->modelKeys())->latest()->paginate(30);
        $returnTo = $this->returnTo($request);

        return response()->view('worker-enrollment.manage', compact('teams', 'rows', 'canRegister', 'returnTo'))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->access_role, ['super_admin', 'admin', 'hr_manager'], true), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'confirmed' => ['sometimes', 'accepted'],
        ]);
        $team = Team::findOrFail($data['team_id']);

        if ($request->boolean('confirmed')) {
            // 등록과 승인을 한 번에 끝낸다. 개인 링크는 만들지 않는다 —
            // 본인은 현장 QR 을 찍고 전화번호 뒷 4자리를 넣으면 들어온다.
            $result = $this->enrollment->registerAndActivate($request->user(), $team, $data);

            return $this->qr(
                route('gate.show', ['site' => $team->site_id]),
                $result['enrollment']->name.' · 출퇴근 시작 QR',
                '본인이 기본 카메라로 이 QR 을 찍고 전화번호 뒷 4자리를 넣으면 바로 출근 화면이 열립니다.',
            );
        }

        $this->enrollment->submit($request->user(), $team, $data);

        return redirect()->route('worker-enrollment.index', $this->returnQuery($request))->with('notice', '등록 내용을 저장했습니다. 인사담당자가 확인 후 계정을 승인하세요.');
    }

    public function approve(Request $request, WorkerEnrollment $enrollment)
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->enrollment->approve($request->user(), $enrollment);

        return redirect()->route('worker-enrollment.index', $this->returnQuery($request))->with('notice', '승인했습니다. 본인은 전화번호 뒷 4자리로 바로 들어옵니다. 시급 금액은 급여 설정에서 확인하세요.');
    }

    public function reject(Request $request, WorkerEnrollment $enrollment)
    {
        abort_unless($this->enrollment->canManage($request->user(), $enrollment->team), 403);
        // Conditional update prevents a stale rejection from undoing a concurrent approval.
        WorkerEnrollment::whereKey($enrollment->id)->where('status', 'pending')->update(['status' => 'rejected']);

        return redirect()->route('worker-enrollment.index', $this->returnQuery($request))->with('notice', '신청을 반려했습니다.');
    }

    private function returnTo(Request $request): string
    {
        // This page is also opened from the compact attendance app.  Preserve that
        // explicit source without accepting arbitrary redirect destinations.
        return $request->string('return_to')->toString() === '/attendance-app'
            ? '/attendance-app'
            : $request->user()->landingPath();
    }

    private function returnQuery(Request $request): array
    {
        return $this->returnTo($request) === '/attendance-app'
            ? ['return_to' => '/attendance-app']
            : [];
    }

    private function qr(string $url, string $title, string $note)
    {
        return response()->view('worker-enrollment.qr', ['url' => $url, 'qr' => QrSvg::dataUri($url, 300), 'title' => $title, 'note' => $note])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
