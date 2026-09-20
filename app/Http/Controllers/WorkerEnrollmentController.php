<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\WorkerEnrollment;
use App\Services\Auth\WorkerEnrollmentService;
use App\Support\QrSvg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class WorkerEnrollmentController extends Controller
{
    public function __construct(private readonly WorkerEnrollmentService $enrollment) {}

    public function index(Request $request)
    {
        $teams = Team::with(['company', 'site'])->where('status', 'active')->get()
            ->filter(fn ($team) => $this->enrollment->canManage($request->user(), $team));
        abort_unless(in_array($request->user()->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'foreman'], true), 403);
        $rows = WorkerEnrollment::with(['team', 'employee.user'])->whereIn('team_id', $teams->modelKeys())->latest()->paginate(30);

        return response()->view('worker-enrollment.manage', compact('teams', 'rows'))->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, Team $team)
    {
        abort_unless($this->enrollment->canManage($request->user(), $team), 403);
        $url = URL::temporarySignedRoute('worker-enrollment.join', now()->addDays(7), ['team' => $team->id]);

        return $this->qr($url, $team->name.' · 가입 QR', '가입 신청용 · 7일 유효 · 이 QR만으로 로그인되지 않습니다.');
    }

    public function form(Team $team)
    {
        abort_unless($team->status === 'active' && $team->company_id && $team->site_id, 404);

        return response()->view('worker-enrollment.join', ['team' => $team, 'done' => false])->header('Referrer-Policy', 'no-referrer');
    }

    public function submit(Request $request, Team $team)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'phone' => ['required', 'string', 'max:30']]);
        $this->enrollment->submit($team, $data);

        // No private record or activation token is exposed to the public applicant.
        return response()->view('worker-enrollment.join', ['team' => $team, 'done' => true])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, WorkerEnrollment $enrollment)
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->enrollment->approve($request->user(), $enrollment);

        return redirect()->route('worker-enrollment.index')->with('notice', '승인했습니다. 개인용 앱 연결 QR을 발급하여 본인에게 전달하세요. 시급 금액은 급여 설정에서 확인하세요.');
    }

    public function activation(Request $request, WorkerEnrollment $enrollment)
    {
        $url = $this->enrollment->activation($request->user(), $enrollment);

        return $this->qr($url, $enrollment->name.' · 개인용 앱 연결', '본인에게만 전달 · 15분 유효 · 1회 사용 · 재발급하면 이전 링크 무효');
    }

    public function reject(Request $request, WorkerEnrollment $enrollment)
    {
        abort_unless($this->enrollment->canManage($request->user(), $enrollment->team), 403);
        // Conditional update prevents a stale rejection from undoing a concurrent approval.
        WorkerEnrollment::whereKey($enrollment->id)->where('status', 'pending')->update(['status' => 'rejected']);

        return redirect()->route('worker-enrollment.index')->with('notice', '신청을 반려했습니다.');
    }

    private function qr(string $url, string $title, string $note)
    {
        return response()->view('worker-enrollment.qr', ['url' => $url, 'qr' => QrSvg::dataUri($url, 300), 'title' => $title, 'note' => $note])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
