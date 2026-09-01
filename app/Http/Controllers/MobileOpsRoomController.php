<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Ops\OpsIntakeService;
use App\Services\Ops\TradeReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 모바일 현장 상황실 — 현장에서 휴대폰으로 원문 기록을 보고, 올리고, 고친다.
 *
 * PC 상황실(SPA)과 같은 데이터를 쓴다. 화면만 한 손으로 쓸 수 있게 단순화했다:
 * 붙여넣기 · 원문 목록 · 원문 상세(수정/삭제) · 오늘 보고 제출.
 *
 * 「오늘 보고 제출」이 곧 반영이다 — 진척·자재·검사 일정은 ERP 로 바로 넘어가고,
 * 금액과 승인처럼 사람이 정해야 하는 것만 PC 상황실의 «확인 대기» 로 남는다.
 */
class MobileOpsRoomController extends Controller
{
    /** 원문 기록을 고치거나 지울 수 있는 역할(PC 상황실과 동일). */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager', 'safety_manager'];

    public function __construct(
        private readonly OpsIntakeService $ops,
        private readonly TradeReportService $tradeReports,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $siteId = $user?->employee?->site_id;

        // 오늘 내 몫 — 공종이 있는 사람에게만 만들어진다(사무·관리는 낼 보고가 없다).
        $report = $user instanceof User ? $this->tradeReports->forUser($user) : null;

        return view('attendance-app.ops-room', [
            'user' => $user,
            'siteName' => $user?->employee?->site?->name,
            // 이 화면이 올리는 기록이 어느 현장 것인지. 예전에는 'ALL' 을 보내서
            // 배치가 현장 없이(site_id=null) 저장됐고, 그래서 그날 그 공종의 보고에
            // <b>한 건도</b> 묶이지 않았다 — 「올린 기록 0건」, 제출은 늘 거절.
            'siteScope' => $siteId ? (string) $siteId : 'ALL',
            'batches' => $this->ops->batches($siteId, 30)['batches'],
            'canManage' => $user instanceof User && in_array($user->access_role, self::MANAGE_ROLES, true),
            'myTrade' => $report?->trade,
            'reportStatus' => $report?->status,
            'reportEntries' => $report ? $report->batches()->count() : 0,
            'reopenReason' => $report?->reopen_reason,
            // 제출한 것이 ERP 로 넘어갔는지 — 페이지를 다시 열어도 결과가 남아 있어야 한다.
            'reflectionNote' => $report?->isSubmitted() ? $report->reflection_note : null,
        ]);
    }

    /**
     * 「오늘 보고 제출」 — 반장이 자기 공종의 몫을 확정한다.
     *
     * 이 버튼이 있어야 소장이 저녁에 "덕트는 아직 안 냈다" 를 알 수 있다.
     * 예전에는 올린 것만 쌓이고 <b>다 올렸다는 신호가 없어서</b>, 빠진 공종이
     * 있는 채로 마감보고서가 원청에 나갔다.
     */
    public function submitTradeReport(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json($this->tradeReports->submit($user));
    }

    /**
     * 제출한 보고가 ERP 로 넘어갔는가 — 화면이 이걸 몇 초 동안 물어본다.
     *
     * 반영은 응답을 보낸 뒤에 돈다(CPM 재계산이 느릴 수 있어서). 그래서 제출 직후
     * 화면은 결과를 모른다. 결과를 안 보여 주면 반장은 «올리긴 했는데 반영은 됐나»
     * 를 알 방법이 없고, 모르면 같은 것을 다시 올린다.
     */
    public function tradeReportStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $report = $this->tradeReports->forUser($user);
        if (! $report) {
            return response()->json(['success' => false, 'error' => '담당 공정이 없습니다.']);
        }

        return response()->json([
            'success' => true,
            'status' => $report->status,
            'reflected' => $report->reflected_at !== null,
            'applied' => (int) $report->applied_count,
            'held' => (int) $report->held_count,
            'note' => $report->reflection_note,
        ]);
    }
}
