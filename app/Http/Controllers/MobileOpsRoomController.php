<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 모바일 현장 상황실 — 현장에서 휴대폰으로 원문 기록을 보고, 올리고, 고친다.
 *
 * PC 상황실(SPA)과 같은 데이터를 쓴다. 화면만 한 손으로 쓸 수 있게 단순화했다:
 * 붙여넣기 · 원문 목록 · 원문 상세(수정/삭제). 공정표 반영은 확인이 필요한 작업이라
 * PC 상황실에서 하도록 남겨 둔다.
 */
class MobileOpsRoomController extends Controller
{
    /** 원문 기록을 고치거나 지울 수 있는 역할(PC 상황실과 동일). */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager', 'safety_manager'];

    public function __construct(
        private readonly OpsIntakeService $ops,
        private readonly \App\Services\Ops\TradeReportService $tradeReports,
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
            'batches' => $this->ops->batches($siteId, 30)['batches'],
            'canManage' => $user instanceof User && in_array($user->access_role, self::MANAGE_ROLES, true),
            'myTrade' => $report?->trade,
            'reportStatus' => $report?->status,
            'reportEntries' => $report ? $report->batches()->count() : 0,
            'reopenReason' => $report?->reopen_reason,
        ]);
    }

    /**
     * 「오늘 보고 제출」 — 반장이 자기 공종의 몫을 확정한다.
     *
     * 이 버튼이 있어야 소장이 저녁에 "덕트는 아직 안 냈다" 를 알 수 있다.
     * 예전에는 올린 것만 쌓이고 <b>다 올렸다는 신호가 없어서</b>, 빠진 공종이
     * 있는 채로 마감보고서가 원청에 나갔다.
     */
    public function submitTradeReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json($this->tradeReports->submit($user));
    }
}
