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

        // Load reference data for inline editing
        $employee = $user->employee;
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

        return view('mobile-equipment.index', [
            'equipments' => $equipments,
            'totalCount' => $totalCount,
            'operableCount' => $operableCount,
            'availableCount' => $availableCount,
            'currentSite' => $siteCode ?: 'ALL',
            'sites' => $sites,
            'teams' => $teams,
            'employees' => $employees,
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

    public function scanPhotosBatch(Request $request): JsonResponse
    {
        $request->validate([
            'photos' => 'required|array|min:1',
            'photos.*' => 'image|max:10240', // 10MB limit per photo
        ]);

        try {
            $files = $request->file('photos');
            if (empty($files)) {
                throw new RuntimeException('사진 파일이 존재하지 않습니다.');
            }

            $absolutePaths = [];
            $publicPaths = [];
            $mimeTypes = [];

            foreach ($files as $file) {
                $path = $file->store('equipments', 'public');
                $absolutePaths[] = Storage::disk('public')->path($path);
                $publicPaths[] = '/storage/' . $path;
                $mimeTypes[] = $file->getClientMimeType();
            }

            $analysis = $this->analyzer->analyzeCollection($absolutePaths, $mimeTypes);
            $items = $analysis['items'] ?? [];

            foreach ($items as &$item) {
                $idx = $item['photo_index'] ?? 0;
                $item['photo_path'] = $publicPaths[$idx] ?? ($publicPaths[0] ?? null);
            }
            unset($item);

            return response()->json([
                'success' => true,
                'items' => $items,
                'photos' => $publicPaths,
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

    public function storeBatch(Request $request)
    {
        $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'team_id' => 'nullable|exists:teams,id',
            'employee_id' => 'nullable|exists:employees,id',
            'items' => 'required|array|min:1',
            'items.*.equipment_type' => 'required|string|max:100',
            'items.*.model' => 'required|string|max:100',
            'items.*.vendor' => 'nullable|string|max:100',
            'items.*.photo_front' => 'nullable|string',
            'items.*.photo' => 'nullable|image|max:10240',
            'items.*.status' => 'required|string|in:대기중,사용중,정비중',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.is_bulk' => 'nullable|string',
        ]);

        $user = auth()->user();
        $employee = $user->employee;
        $companyId = $employee?->company_id ?? $user->allowed_company_id;

        $batchSiteId = $request->input('site_id') ?: ($employee?->site_id ?? $user->allowed_site_id);
        $batchTeamId = $request->input('team_id');
        $batchEmployeeId = $request->input('employee_id');

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $companyId, $batchSiteId, $batchTeamId, $batchEmployeeId): void {
            foreach ($request->input('items') as $key => $item) {
                $quantity = (int) ($item['quantity'] ?? 1);
                $isBulk = isset($item['is_bulk']) && ($item['is_bulk'] === 'on' || $item['is_bulk'] == 1 || $item['is_bulk'] === 'true');

                $photoPath = $item['photo_front'] ?? null;
                if ($request->hasFile("items.{$key}.photo")) {
                    $file = $request->file("items.{$key}.photo");
                    $path = $file->store('equipments', 'public');
                    $photoPath = '/storage/' . $path;
                }

                if ($isBulk) {
                    Equipment::create([
                        'company_id' => $companyId,
                        'site_id' => $batchSiteId,
                        'team_id' => $batchTeamId,
                        'employee_id' => $batchEmployeeId,
                        'equipment_type' => $item['equipment_type'],
                        'model' => $item['model'],
                        'vendor' => $item['vendor'] ?? null,
                        'status' => $item['status'] ?? '대기중',
                        'photo_front' => $photoPath,
                        'registration_method' => 'AI자동분석',
                        'payload' => null,
                        'quantity' => $quantity,
                        'is_bulk' => true,
                    ]);
                } else {
                    for ($i = 0; $i < $quantity; $i++) {
                        Equipment::create([
                            'company_id' => $companyId,
                            'site_id' => $batchSiteId,
                            'team_id' => $batchTeamId,
                            'employee_id' => $batchEmployeeId,
                            'equipment_type' => $item['equipment_type'],
                            'model' => $item['model'],
                            'vendor' => $item['vendor'] ?? null,
                            'status' => $item['status'] ?? '대기중',
                            'photo_front' => $photoPath,
                            'registration_method' => 'AI자동분석',
                            'payload' => null,
                            'quantity' => 1,
                            'is_bulk' => false,
                        ]);
                    }
                }
            }
        });

        return redirect()->route('mobile-equipment.index')
            ->with('success', '총 ' . count($request->input('items')) . '건의 장비/자재가 일괄 등록되었습니다.');
    }

    public function update(Request $request, Equipment $equipment)
    {
        $request->validate([
            'equipment_type' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'status' => 'required|string|in:대기중,사용중,정비중',
            'site_id' => 'nullable|exists:sites,id',
            'team_id' => 'nullable|exists:teams,id',
            'employee_id' => 'nullable|exists:employees,id',
            'photo' => 'nullable|image|max:10240', // 10MB limit
        ]);

        $data = [
            'equipment_type' => $request->input('equipment_type'),
            'model' => $request->input('model'),
            'vendor' => $request->input('vendor'),
            'status' => $request->input('status'),
            'site_id' => $request->input('site_id'),
            'team_id' => $request->input('team_id'),
            'employee_id' => $request->input('employee_id'),
        ];

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $path = $file->store('equipments', 'public');
            $data['photo_front'] = '/storage/' . $path;
        }

        $equipment->update($data);

        return redirect()->route('mobile-equipment.index')
            ->with('success', '장비 정보가 정상적으로 수정되었습니다.');
    }

    public function destroy(Equipment $equipment)
    {
        $equipment->delete();

        return redirect()->route('mobile-equipment.index')
            ->with('success', '장비가 정상적으로 삭제되었습니다.');
    }
}
