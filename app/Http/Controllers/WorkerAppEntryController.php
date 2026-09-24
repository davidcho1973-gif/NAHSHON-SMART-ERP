<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\WorkerDevice;
use App\Support\WorkerDeviceSession;
use App\Support\WorkerLang;
use App\Support\WorkerPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * 작업자가 앱으로 들어오는 문 — <b>로그인 화면을 거치지 않는다.</b>
 *
 * ── 고친 것 ────────────────────────────────────────────────────────────
 * 작업자가 「작업자 앱 열기」 를 누르면 ERP 로그인 화면이 떠서 <b>이메일과 비밀번호</b>를
 * 물었다. 작업자에게는 둘 다 없다. 거기가 막다른 길이었다.
 *
 * 원인은 «앱이 로그인을 요구한다» 가 아니라, <b>작업자를 알아보는 방법이 이미 있는데
 * 앱이 그걸 안 썼다</b> 는 것이다. 등록할 때 그 휴대폰에 기기 토큰을 발급해 두었고,
 * 게이트는 그것만으로 사람을 알아본다. 앱만 사무직과 같은 문을 쓰고 있었다.
 *
 * 그래서 게이트와 같은 열쇠로 앱도 연다. 대신 그렇게 들어온 세션에는 표시를 남겨
 * (WorkerDeviceSession) 메시지·문서처럼 남의 글이 있는 곳에서만 PIN 을 한 번 받는다.
 *
 * ── 토큰이 두 군데 있는 이유 ──────────────────────────────────────────
 * 원래 토큰은 localStorage 에만 있었다. 그건 서버가 못 읽으므로 첫 요청에서 바로
 * 알아볼 수 없고, 화면을 한 번 그린 뒤 JS 가 건네줘야 한다. 그래서 한 번 건네받으면
 * <b>쿠키로도 심어</b> 다음부터는 서버가 첫 요청에서 바로 알아본다. localStorage 가
 * 여전히 원본이다 — 게이트가 그것을 쓰고, 공용 폰 판정도 그쪽에 걸려 있다.
 */
class WorkerAppEntryController extends Controller
{
    /**
     * 앱 문 앞.
     *
     * 이미 로그인돼 있으면 그대로 들여보낸다. 쿠키에 기기 토큰이 있으면 그것으로 들어간다.
     * 둘 다 없으면 <b>ERP 로그인 화면이 아니라</b> 작업자가 읽을 수 있는 안내를 보여 주고,
     * 그 화면의 JS 가 localStorage 의 토큰을 서버로 건넨다.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('attendance-app.index');
        }

        $cookie = (string) $request->cookie(WorkerDeviceSession::COOKIE, '');
        if ($cookie !== '' && $this->signIn($request, $cookie)) {
            return redirect()->route('attendance-app.index');
        }

        return view('worker-app.entry', [
            'lang' => WorkerLang::resolve($request->query('lang', $request->cookie('app_locale'))),
            'langOptions' => WorkerLang::OPTIONS,
            // 토큰을 못 찾은 채로 이 화면이 다시 뜨면, 안내 문구를 바꿔 보여 준다.
            'failed' => $request->boolean('retry'),
        ]);
    }

    /**
     * 화면의 JS 가 localStorage 에서 꺼낸 토큰을 건넨다.
     *
     * 토큰이 맞으면 로그인시키고 쿠키로도 심는다(다음부터는 이 왕복이 없다).
     */
    public function device(Request $request): RedirectResponse
    {
        $token = trim((string) $request->input('device_token', ''));

        if ($token === '' || ! $this->signIn($request, $token)) {
            // 실패해도 로그인 화면으로 보내지 않는다 — 그게 이 화면이 생긴 이유다.
            return redirect()->route('worker-app.entry', ['retry' => 1]);
        }

        return redirect()->route('attendance-app.index')
            ->withCookie(Cookie::make(
                WorkerDeviceSession::COOKIE,
                $token,
                60 * 24 * WorkerDeviceSession::COOKIE_DAYS,
                httpOnly: true,
                sameSite: 'Lax',
            ));
    }

    /**
     * 토큰 하나로 이 사람이 되게 한다.
     *
     * 작업자 본인 범위 계정만 통과한다(WorkerDeviceSession). 벽에 붙은 QR 로 등록한
     * 휴대폰 한 대가 관리자 열쇠가 되면 안 된다.
     */
    private function signIn(Request $request, string $token): bool
    {
        return WorkerDeviceSession::openFor($request, $token);
    }

    /**
     * 전화번호 뒷 4자리로 본인 찾기 — 휴대폰이 기억돼 있지 않을 때의 문.
     *
     * <b>사장님 결정(2026-09-23):</b> «작업자앱은 작업반장, 관리자도 본인 핸드폰 뒷자리
     * 4자리만 입력하고 입장 가능하게. 대신 ERP 본화면은 슈퍼관리자가 승인한 경우에만.»
     *
     * 그래서 이 문은 직책을 가리지 않는다 — 재직 중이고 번호가 적혀 있으면 누구든 열린다.
     * 열리는 것은 <b>작업자 앱뿐</b>이고, ERP 본화면은 이 문으로 열리지 않는다(세션에 표시가
     * 남고 RequireApprovedErpAccess 가 그 표시를 본다).
     */
    public function find(Request $request): JsonResponse
    {
        $matches = WorkerPhone::matchingLast4((string) $request->input('last4', ''))
            ->with(['site:id,code,name', 'company:id,name'])
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'site' => $e->site?->code,
                'company' => $e->company?->name,
                'position' => $e->positionLabel(),
            ]);

        return response()->json(['success' => true, 'workers' => $matches->all()])
            ->header('Cache-Control', 'no-store');
    }

    /** 고른 사람으로 앱에 들어간다 — 이 휴대폰도 함께 기억한다. */
    public function enter(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer']]);

        $employee = Employee::query()
            ->where('id', $data['employee_id'])
            ->where('employment_status', 'active')
            ->first();

        if (! $employee) {
            return response()->json(['success' => false, 'error' => '찾을 수 없습니다. 인사담당자에게 문의하세요. / Not found — contact HR.'], 422);
        }

        $token = WorkerDevice::issueFor($employee, $request->userAgent(), verified: true);
        if (! WorkerDeviceSession::openForApp($request, $employee)) {
            return response()->json(['success' => false, 'error' => '이 사람의 계정이 아직 없습니다. 인사담당자에게 문의하세요. / No account yet — contact HR.'], 422);
        }

        return response()->json(['success' => true, 'device_token' => $token, 'redirect' => route('attendance-app.index')])
            ->header('Cache-Control', 'no-store');
    }
}
