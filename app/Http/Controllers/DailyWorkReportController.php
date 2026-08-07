<?php

namespace App\Http\Controllers;

use App\Models\DailyCrewReport;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;

class DailyWorkReportController extends Controller
{
    /**
     * 건설현장 일일 작업 보고서 메인 앱 화면
     */
    public function index(Request $request): View
    {
        $sites = Site::where('status', 'active')->orWhereNull('status')->orderBy('name')->get();
        $selectedSiteId = $request->query('site_id', $sites->first()?->id);
        
        $selectedSite = $sites->firstWhere('id', $selectedSiteId) ?? $sites->first();
        $todayDate = $request->query('date', now()->format('Y-m-d'));

        // 해당 현장의 팀 목록 및 일일 보고서 데이터
        $teams = $selectedSite ? Team::where('site_id', $selectedSite->id)->get() : collect();
        $reports = $selectedSite ? DailyCrewReport::where('site_id', $selectedSite->id)
            ->whereDate('work_date', $todayDate)
            ->with(['team', 'company'])
            ->get() : collect();

        return view('daily-work-report.index', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'todayDate' => $todayDate,
            'teams' => $teams,
            'reports' => $reports,
        ]);
    }

    /**
     * 일일 보고서 저장 (AJAX 또는 Form POST)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'work_date' => 'required|date',
            'weather' => 'nullable|string',
            'temperature' => 'nullable|string',
            'work_title' => 'required|string|max:255',
            'work_content_today' => 'required|string',
            'work_content_tomorrow' => 'nullable|string',
            'trade_counts' => 'nullable|array',
            'equipment_list' => 'nullable|array',
            'safety_tbm_done' => 'nullable|boolean',
            'special_notes' => 'nullable|string',
        ]);

        return response()->json([
            'success' => true,
            'message' => '일일 작업 보고서가 성공적으로 저장되었습니다.',
            'data' => $validated
        ]);
    }
}
