<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Site;
use App\Models\Team;
use App\Models\Employee;
use App\Services\GeminiEquipmentPhotoAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class MobileEquipmentController extends Controller
{
    public function __construct(private readonly GeminiEquipmentPhotoAnalyzer $analyzer)
    {
    }

    public function index(Request $request): View
    {
        $user = auth()->user();
        $siteCode = $request->query('site');

        $query = Equipment::query()
            ->visibleTo($user)
            ->with(['site', 'team', 'employee']);

        if ($siteCode && $siteCode !== 'ALL') {
            $query->whereHas('site', fn($q) => $q->where('code', $siteCode));
        }

        $equipments = $query->orderByDesc('id')->get();

        // Calculate stats
        $totalCount = $equipments->count();
        $operableCount = $equipments->where('status', '사용중')->count();
        $availableCount = $equipments->where('status', '대기중')->count();

        return view('mobile-equipment.index', [
            'equipments' => $equipments,
            'totalCount' => $totalCount,
            'operableCount' => $operableCount,
            'availableCount' => $availableCount,
            'currentSite' => $siteCode ?: 'ALL',
        ]);
    }

    public function wizard(Request $request): View
    {
        $user = auth()->user();
        $employee = $user->employee;

        // Fetch sites available for user's company
        if ($employee && $employee->company_id) {
            $sites = Site::query()
                ->where('company_id', $employee->company_id)
                ->where('status', 'active')
                ->get();
        } else {
            $sites = Site::query()->where('status', 'active')->get();
        }

        $teams = Team::query()->get();
        $employees = Employee::query()->where('employment_status', 'active')->get();

        return view('mobile-equipment.wizard', [
            'sites' => $sites,
            'teams' => $teams,
            'employees' => $employees,
            'selectedSiteId' => $employee?->site_id ?? $user->allowed_site_id,
        ]);
    }

    public function scanPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:10240', // 10MB limit
        ]);

        try {
            $file = $request->file('photo');
            if (! $file) {
                throw new RuntimeException('사진 파일이 존재하지 않습니다.');
            }

            // Store in storage/app/public/equipments
            $path = $file->store('equipments', 'public');
            $absolutePath = Storage::disk('public')->path($path);

            // Analyze receipt
            $analysisResult = $this->analyzer->analyze($absolutePath, $file->getClientMimeType());

            return response()->json([
                'success' => true,
                'photo_path' => '/storage/' . $path,
                'data' => $analysisResult,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'equipment_type' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'site_id' => 'nullable|exists:sites,id',
            'team_id' => 'nullable|exists:teams,id',
            'employee_id' => 'nullable|exists:employees,id',
            'photo_front' => 'nullable|string',
            'ocr_data' => 'nullable|string',
            'status' => 'required|string|in:대기중,사용중,정비중',
            'quantity' => 'required|integer|min:1',
            'is_bulk' => 'nullable|string',
        ]);

        $user = auth()->user();
        $employee = $user->employee;

        $companyId = $employee?->company_id ?? $user->allowed_company_id;
        $siteId = $request->input('site_id') ?: ($employee?->site_id ?? $user->allowed_site_id);

        $ocrPayload = null;
        if ($request->input('ocr_data')) {
            $ocrPayload = json_decode($request->input('ocr_data'), true);
        }

        $quantity = (int) $request->input('quantity', 1);
        $isBulk = $request->input('is_bulk') === 'on' || $request->input('is_bulk') == 1 || $request->input('is_bulk') === 'true';

        if ($isBulk) {
            Equipment::create([
                'company_id' => $companyId,
                'site_id' => $siteId,
                'team_id' => $request->input('team_id'),
                'employee_id' => $request->input('employee_id'),
                'equipment_type' => $request->input('equipment_type'),
                'model' => $request->input('model'),
                'vendor' => $request->input('vendor'),
                'status' => $request->input('status'),
                'photo_front' => $request->input('photo_front'),
                'registration_method' => 'AI자동분석',
                'payload' => $ocrPayload,
                'quantity' => $quantity,
                'is_bulk' => true,
            ]);
        } else {
            for ($i = 0; $i < $quantity; $i++) {
                Equipment::create([
                    'company_id' => $companyId,
                    'site_id' => $siteId,
                    'team_id' => $request->input('team_id'),
                    'employee_id' => $request->input('employee_id'),
                    'equipment_type' => $request->input('equipment_type'),
                    'model' => $request->input('model'),
                    'vendor' => $request->input('vendor'),
                    'status' => $request->input('status'),
                    'photo_front' => $request->input('photo_front'),
                    'registration_method' => 'AI자동분석',
                    'payload' => $ocrPayload,
                    'quantity' => 1,
                    'is_bulk' => false,
                ]);
            }
        }

        return redirect()->route('mobile-equipment.index')
            ->with('success', '새 장비/자재가 정상 등록되었습니다.');
    }
}
