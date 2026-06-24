<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleRental;
use App\Services\GeminiVehicleAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VehicleApiController extends Controller
{
    public function __construct(private readonly GeminiVehicleAnalyzer $analyzer)
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

            // Helper to store files in public disk
            $storeFile = function ($fileKey) use ($request, &$filesToAnalyze, &$savedPaths): void {
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $path = $file->store('vehicles', 'public');
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

    public function saveVehicle(Request $request): JsonResponse
    {
        $request->validate([
            'plate_number' => 'nullable|string|max:40',
            'model' => 'required|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'rent_start' => 'nullable|date',
            'rent_end' => 'nullable|date',
            'insurance_expiry' => 'nullable|date',
            'current_mileage' => 'nullable|integer|min:0',
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

        $vehicle = Vehicle::create([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'team_id' => $teamId,
            'plate_number' => $request->input('plate_number'),
            'model' => $request->input('model'),
            'vendor' => $request->input('vendor'),
            'rent_start' => $request->input('rent_start'),
            'rent_end' => $request->input('rent_end'),
            'insurance_expiry' => $request->input('insurance_expiry'),
            'current_mileage' => $request->input('current_mileage') ?? 0,
            'status' => '대기중',
            'photo_front' => $request->input('photo_front'),
            'photo_rear' => $request->input('photo_rear'),
            'photo_left' => $request->input('photo_left'),
            'photo_right' => $request->input('photo_right'),
            'contract_path' => $request->input('contract_path'),
            'registration_method' => 'AI자동분석',
        ]);

        return response()->json([
            'success' => true,
            'vehicle' => $vehicle,
        ]);
    }

    public function assignVehicle(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'employee_id' => 'required|exists:employees,id',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($request): void {
                $vehicle = Vehicle::findOrFail($request->input('vehicle_id'));
                $employee = Employee::findOrFail($request->input('employee_id'));

                // Terminate any active rentals for this vehicle
                VehicleRental::where('vehicle_id', $vehicle->id)
                    ->whereNull('returned_at')
                    ->update([
                        'returned_at' => now(),
                        'end_mileage' => $vehicle->current_mileage,
                        'status' => 'returned',
                    ]);

                // Terminate any active rentals for this employee
                VehicleRental::where('employee_id', $employee->id)
                    ->whereNull('returned_at')
                    ->update([
                        'returned_at' => now(),
                        'status' => 'returned',
                    ]);

                // Create a new rental
                VehicleRental::create([
                    'vehicle_id' => $vehicle->id,
                    'employee_id' => $employee->id,
                    'company_id' => $employee->company_id,
                    'site_id' => $employee->site_id,
                    'rented_at' => now(),
                    'start_mileage' => $vehicle->current_mileage,
                    'status' => 'active',
                    'notes' => $request->input('notes'),
                ]);

                // Update vehicle
                $vehicle->update(['status' => '운행중']);
            });

            return response()->json(['success' => true, 'message' => '차량이 성공적으로 배정되었습니다.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function returnVehicle(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'current_mileage' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($request): void {
                $vehicle = Vehicle::findOrFail($request->input('vehicle_id'));
                $currentMileage = (int) $request->input('current_mileage');

                if ($currentMileage < $vehicle->current_mileage) {
                    throw new RuntimeException("반납 마일리지는 현재 마일리지({$vehicle->current_mileage})보다 작을 수 없습니다.");
                }

                // Update active rental
                $activeRental = VehicleRental::where('vehicle_id', $vehicle->id)
                    ->whereNull('returned_at')
                    ->first();

                if ($activeRental) {
                    $activeRental->update([
                        'returned_at' => now(),
                        'end_mileage' => $currentMileage,
                        'status' => 'returned',
                        'notes' => trim(($activeRental->notes ? $activeRental->notes . "\n" : '') . '반납시 메모: ' . $request->input('notes')),
                    ]);
                }

                // Update vehicle
                $vehicle->update([
                    'current_mileage' => $currentMileage,
                    'status' => '대기중',
                ]);
            });

            return response()->json(['success' => true, 'message' => '차량이 성공적으로 반납되었습니다.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function getRentalHistory(Vehicle $vehicle): JsonResponse
    {
        $history = VehicleRental::query()
            ->where('vehicle_id', $vehicle->id)
            ->with('employee')
            ->orderByDesc('rented_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'driver' => $r->employee?->name ?: '퇴사한 직원',
                'rented_at' => $r->rented_at->toDateTimeString(),
                'returned_at' => $r->returned_at ? $r->returned_at->toDateTimeString() : null,
                'start_mileage' => $r->start_mileage,
                'end_mileage' => $r->end_mileage,
                'status' => $r->status === 'active' ? '대여중' : '반납완료',
                'notes' => $r->notes,
            ]);

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }
}
