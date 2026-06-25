<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Team;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Services\GeminiEquipmentAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EquipmentApiController extends Controller
{
    public function __construct(private readonly GeminiEquipmentAnalyzer $analyzer)
    {
    }

    public function scanRental(Request $request): JsonResponse
    {
        $request->validate([
            'contract' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'photo_front' => 'nullable|image|max:10240',
            'photo_rear' => 'nullable|image|max:10240',
            'photo_left' => 'nullable|image|max:10240',
            'photo_right' => 'nullable|image|max:10240',
        ]);

        try {
            $filesToAnalyze = [];
            $savedPaths = [];

            // Helper to store files in public disk under equipments
            $storeFile = function ($fileKey) use ($request, &$filesToAnalyze, &$savedPaths): void {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $path = $file->store('equipments', 'public');
                    $absolutePath = Storage::disk('public')->path($path);
                    
                    $filesToAnalyze[] = [
                        'path' => $absolutePath,
                        'mime_type' => $file->getClientMimeType(),
                    ];
                    $savedPaths[$fileKey] = '/storage/' . $path;
                }
            };

            $storeFile('contract');
            $storeFile('photo_front');
            $storeFile('photo_rear');
            $storeFile('photo_left');
            $storeFile('photo_right');

            // Analyze via Gemini
            $analysis = $this->analyzer->analyze($filesToAnalyze);

            return response()->json([
                'success' => true,
                'data' => $analysis,
                'files' => $savedPaths,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function saveEquipment(Request $request): JsonResponse
    {
        $request->validate([
            'equipment_type' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'quantity' => 'nullable|integer|min:1|max:100',
            'rent_start' => 'nullable|date',
            'rent_end' => 'nullable|date',
            'daily_rate' => 'nullable|integer|min:0',
            'delivery_fee' => 'nullable|integer|min:0',
            'return_fee' => 'nullable|integer|min:0',
            'rate_period' => 'nullable|string|max:40',
            'details' => 'nullable|array',
            'photo_front' => 'nullable|string',
            'photo_rear' => 'nullable|string',
            'photo_left' => 'nullable|string',
            'photo_right' => 'nullable|string',
            'contract_path' => 'nullable|string',
        ]);

        $user = auth()->user();
        $employee = $user->employee;
        $companyId = $employee?->company_id ?? $user->allowed_company_id;
        $siteId = $employee?->site_id ?? $user->allowed_site_id;
        $teamId = $employee?->team_id ?? $user->allowed_team_id;

        $quantity = (int) ($request->input('quantity') ?: 1);

        // The full extracted contract metadata is kept on each unit's payload so nothing
        // the AI read is lost, even though the quick-edit form only surfaces a few fields.
        $payload = array_filter([
            'rate_period' => $request->input('rate_period'),
            'return_fee' => $request->input('return_fee'),
            'details' => $request->input('details'),
            'order_quantity' => $quantity,
        ], fn ($v) => $v !== null && $v !== '');

        $created = DB::transaction(function () use ($request, $siteId, $quantity, $payload): array {
            $rows = [];

            // One Equipment row per unit on the order (a "2 containers" order = 2 assets).
            for ($i = 0; $i < $quantity; $i++) {
                $rows[] = Equipment::create([
                    'company_id' => null, // Stays available/unassigned initially
                    'site_id' => $siteId,
                    'team_id' => null,
                    'employee_id' => null,
                    'equipment_type' => $request->input('equipment_type'),
                    'model' => $request->input('model'),
                    'vendor' => $request->input('vendor'),
                    'rent_start' => $request->input('rent_start'),
                    'rent_end' => $request->input('rent_end'),
                    'daily_rate' => $request->input('daily_rate') ?? 0,
                    'delivery_fee' => $request->input('delivery_fee') ?? 0,
                    'status' => '대기중',
                    'photo_front' => $request->input('photo_front'),
                    'photo_rear' => $request->input('photo_rear'),
                    'photo_left' => $request->input('photo_left'),
                    'photo_right' => $request->input('photo_right'),
                    'contract_path' => $request->input('contract_path'),
                    'registration_method' => 'AI자동분석',
                    'payload' => $payload ?: null,
                ]);
            }

            return $rows;
        });

        return response()->json([
            'success' => true,
            'count' => count($created),
            'equipment' => $created[0] ?? null,
        ]);
    }

    public function assignEquipment(Request $request): JsonResponse
    {
        $request->validate([
            'equipment_id' => 'required|exists:equipments,id',
            'company_id' => 'required|exists:companies,id',
            'team_id' => 'nullable|exists:teams,id',
            'employee_id' => 'nullable|exists:employees,id',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($request): void {
                $equipment = Equipment::findOrFail($request->input('equipment_id'));
                $companyId = $request->input('company_id');
                $teamId = $request->input('team_id');
                $employeeId = $request->input('employee_id');

                // Terminate any active rentals for this equipment
                EquipmentRental::where('equipment_id', $equipment->id)
                    ->whereNull('returned_at')
                    ->update([
                        'returned_at' => now(),
                        'status' => 'returned',
                    ]);

                // Create a new rental
                EquipmentRental::create([
                    'equipment_id' => $equipment->id,
                    'company_id' => $companyId,
                    'team_id' => $teamId,
                    'employee_id' => $employeeId,
                    'site_id' => $equipment->site_id,
                    'rented_at' => now(),
                    'status' => 'active',
                    'notes' => $request->input('notes'),
                ]);

                // Update equipment
                $equipment->update([
                    'company_id' => $companyId,
                    'team_id' => $teamId,
                    'employee_id' => $employeeId,
                    'status' => '사용중'
                ]);
            });

            return response()->json(['success' => true, 'message' => '장비가 성공적으로 배정되었습니다.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function returnEquipment(Request $request): JsonResponse
    {
        $request->validate([
            'equipment_id' => 'required|exists:equipments,id',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($request): void {
                $equipment = Equipment::findOrFail($request->input('equipment_id'));

                // Update active rental
                $activeRental = EquipmentRental::where('equipment_id', $equipment->id)
                    ->whereNull('returned_at')
                    ->first();

                if ($activeRental) {
                    $activeRental->update([
                        'returned_at' => now(),
                        'status' => 'returned',
                        'notes' => trim(($activeRental->notes ? $activeRental->notes . "\n" : '') . '반납시 메모: ' . $request->input('notes')),
                    ]);
                }

                // Update equipment to available/unassigned status
                $equipment->update([
                    'company_id' => null,
                    'team_id' => null,
                    'employee_id' => null,
                    'status' => '대기중',
                ]);
            });

            return response()->json(['success' => true, 'message' => '장비가 성공적으로 반납되었습니다.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function getRentalHistory(Equipment $equipment): JsonResponse
    {
        $history = EquipmentRental::query()
            ->where('equipment_id', $equipment->id)
            ->with(['company', 'team', 'employee'])
            ->orderByDesc('rented_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'company' => $r->company?->name ?: '-',
                'team' => $r->team?->name ?: '-',
                'operator' => $r->employee?->name ?: '-',
                'rented_at' => $r->rented_at->toDateTimeString(),
                'returned_at' => $r->returned_at ? $r->returned_at->toDateTimeString() : null,
                'status' => $r->status === 'active' ? '대여중' : '반납완료',
                'notes' => $r->notes,
            ]);

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }

    public function serveFile(Request $request)
    {
        $path = $request->query('path');
        abort_unless($path && is_string($path), 400);

        if (str_contains($path, '..') || str_contains($path, '\\')) {
            abort(403, 'Path traversal detected.');
        }

        $path = ltrim($path, '/');

        // Only allow serving from the 'equipments' directory
        abort_unless(str_starts_with($path, 'equipments/'), 403);

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function updateEquipment(Request $request, Equipment $equipment): JsonResponse
    {
        $request->validate([
            'equipment_type' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'rent_start' => 'nullable|date',
            'rent_end' => 'nullable|date',
            'daily_rate' => 'nullable|integer|min:0',
            'delivery_fee' => 'nullable|integer|min:0',
            'status' => 'required|string|max:40',
            'company_id' => 'nullable|exists:companies,id',
            'team_id' => 'nullable|exists:teams,id',
            'employee_id' => 'nullable|exists:employees,id',
            'custom_operator' => 'nullable|string|max:100',
            'photo_front_file' => 'nullable|image|max:10240',
            'photo_rear_file' => 'nullable|image|max:10240',
            'photo_left_file' => 'nullable|image|max:10240',
            'photo_right_file' => 'nullable|image|max:10240',
            'contract_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $companyId = $request->input('company_id');
        $teamId = $request->input('team_id');
        $employeeId = $request->input('employee_id');

        $data = [
            'equipment_type' => $request->input('equipment_type'),
            'model' => $request->input('model'),
            'vendor' => $request->input('vendor'),
            'rent_start' => $request->input('rent_start'),
            'rent_end' => $request->input('rent_end'),
            'daily_rate' => $request->input('daily_rate') ?? 0,
            'delivery_fee' => $request->input('delivery_fee') ?? 0,
            'status' => $request->input('status'),
            'company_id' => filled($companyId) ? $companyId : null,
            'team_id' => filled($teamId) ? $teamId : null,
        ];

        // Maintain custom operator in payload
        $payload = is_array($equipment->payload) ? $equipment->payload : [];
        if (filled($employeeId)) {
            $data['employee_id'] = $employeeId;
            unset($payload['custom_operator']);
        } elseif (filled($request->input('custom_operator'))) {
            $data['employee_id'] = null;
            $payload['custom_operator'] = $request->input('custom_operator');
        } else {
            $data['employee_id'] = null;
            unset($payload['custom_operator']);
        }
        $data['payload'] = $payload;

        // Store photo files if present
        $storePhoto = function ($key) use ($request, &$data): void {
            if ($request->hasFile("{$key}_file")) {
                $file = $request->file("{$key}_file");
                $path = $file->store('equipments', 'public');
                $data[$key] = '/storage/' . $path;
            }
        };

        $storePhoto('photo_front');
        $storePhoto('photo_rear');
        $storePhoto('photo_left');
        $storePhoto('photo_right');

        if ($request->hasFile('contract_file')) {
            $file = $request->file('contract_file');
            $path = $file->store('equipments', 'public');
            $data['contract_path'] = '/storage/' . $path;
        }

        $equipment->update($data);

        return response()->json([
            'success' => true,
            'message' => '장비 정보가 수정되었습니다.',
            'equipment' => $equipment,
        ]);
    }

    public function deleteEquipment(Equipment $equipment): JsonResponse
    {
        try {
            $equipment->delete();
            return response()->json([
                'success' => true,
                'message' => '장비가 성공적으로 삭제되었습니다.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

