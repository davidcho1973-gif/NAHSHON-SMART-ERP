<?php

namespace App\Support;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\ExpensePreApproval;
use App\Models\MobileExpense;
use App\Models\SmartRecord;
use App\Models\Site;
use App\Services\AttendanceQrService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SmartCompanyData
{
    public static function handle(string $method, array $args = [], string $siteId = 'ALL'): mixed
    {
        return match ($method) {
            'api_getHRData' => self::realHrData($siteId),
            'api_getPersonnelList' => self::realPersonnel($siteId),
            'api_getPersonnelStats' => self::realPersonnelStats($siteId),
            'api_getGlobalAttendance' => self::realGlobalAttendance(),
            'api_getAttendanceLive' => self::realAttendanceLive($siteId),
            'api_getDailyTeamMatrix' => self::realDailyTeamMatrix($siteId),
            'api_getDailyAttendanceDetail', 'api_getAttendanceDetailed' => self::realDailyAttendanceDetail($siteId, $args[1] ?? $args[0] ?? null),
            'api_getEmployeeDetail' => self::realEmployeeDetail((string) ($args[0] ?? ''), $siteId),
            'api_uploadEmployeePhoto' => ['success' => true, 'message' => 'Photo upload endpoint is ready. Configure filesystem disk for production.'],
            'api_getHrDirectory' => self::hrDirectory($siteId),
            'api_getHrAttendanceRecords' => self::hrAttendanceRecords($args[0] ?? null, $args[1] ?? null, $args[2] ?? null),
            'api_getHrAttendanceSummary' => self::hrAttendanceSummary($args[0] ?? null, $args[1] ?? null, $args[2] ?? null),
            'api_clockIn' => self::clockIn($args[0] ?? null),
            'api_clockOut' => self::clockOut($args[0] ?? null),
            'api_requestCorrection' => self::requestCorrection($args[0] ?? null, $args[1] ?? null, $args[2] ?? null),
            'api_clockInWithGps' => self::clockInWithGps($args[0] ?? null, $args[1] ?? null, $args[2] ?? null, $args[3] ?? null),
            'api_clockInWithTeamQr' => self::clockInWithTeamQr($args[0] ?? null, $args[1] ?? null),
            'api_submitNfcTag' => self::submitNfcTag($args[0] ?? null, $args[1] ?? null, $args[2] ?? null),
            'api_submitBatchPhotoScan' => self::submitBatchPhotoScan($args[0] ?? null, $args[1] ?? null, $args[2] ?? null, $args[3] ?? null, $args[4] ?? null),
            'api_getPendingAttendanceLogs' => self::getPendingAttendanceLogs($siteId),
            'api_approveAttendanceLog' => self::approveAttendanceLog($args[0] ?? null),
            'api_rejectAttendanceLog' => self::rejectAttendanceLog($args[0] ?? null),

            'api_getFinanceStats' => self::financeStats($siteId),
            'api_getExpenses' => self::expenses($siteId),
            'api_getPayrollDashboard' => self::payrollDashboard($args[1] ?? null, $siteId),
            'api_runPayroll' => self::runPayroll($args[1] ?? $args[0] ?? null, $siteId),

            'api_getEquipmentStats' => self::equipmentStats(),
            'api_getEquipmentList' => self::equipmentList(),
            'api_getToolStats' => self::toolStats(),
            'api_getToolList' => self::toolList(),
            'api_getToolTransactions' => self::toolTransactions(),
            'api_getInventoryDashboard' => self::inventoryDashboard($siteId),
            'api_getInventoryAssetDetail' => self::inventoryAssetDetail((string) ($args[0] ?? '')),
            'api_processInventoryPhotos', 'setupInventorySheets', 'setupInventoryFolders' => ['success' => true, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'results' => []],

            'api_getAlerts' => self::alerts($args[0] ?? 'all'),
            'api_updateAlertStatus' => ['success' => true],
            'api_getSafetyStats' => self::safetyStats(),
            'api_getSafetyWorkItems' => self::safetyWorkItems($siteId),
            'api_saveSafetyWorkItems' => self::saveSafetyWorkItems($args[0] ?? [], $siteId),
            'api_generateSafetyPlan' => self::generateSafetyPlan($args[0] ?? null, $siteId),
            'api_recommendSafetyProgress' => self::recommendSafetyProgress($args[0] ?? null, $siteId),
            'api_getPtwList' => self::ptwList(),
            'api_getPtwStats' => ['todayActive' => 4, 'pending' => 2, 'completed' => 18, 'rejected' => 1],
            'api_getInspections' => self::inspections(),
            'api_getInspectionStats' => ['totalItems' => 42, 'passed' => 37, 'failed' => 5, 'completionRate' => 88],
            'api_getTrainingRecords' => self::trainingRecords(),
            'api_getSafetyDocs' => self::safetyDocs(),
            'api_getOshaForm300' => self::oshaForm300(),
            'api_getOsha300AStats' => ['year' => (int) ($args[0] ?? 2026), 'totalCases' => 2, 'dartRate' => '0.41', 'trir' => '0.82'],
            'api_getCertMatrix' => self::certMatrix(),
            'api_getViolations' => self::violations(),
            'api_getTbmRecords' => self::tbmRecords(),

            'api_getProjectStatus' => self::projects(),
            'api_getActionItems' => self::actionItems(),
            'api_getConstructionCommandCenter' => self::commandCenter($siteId),
            'api_getProjectWbsTree' => self::wbsTree((string) ($args[0] ?? 'HFF-02'), $siteId),
            'api_getProjectProgressSummary' => self::projectProgressSummary((string) ($args[0] ?? 'HFF-02'), $siteId),
            'api_markWbsStatus' => app(\App\Services\Wbs\WbsService::class)->markStatus((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'api_updateWbsRow' => app(\App\Services\Wbs\WbsService::class)->updateRow((string) ($args[0] ?? ''), is_array($args[1] ?? null) ? $args[1] : []),
            'api_processWbsManual' => self::processWbsManual((string) ($args[0] ?? 'HFF-02'), $siteId),

            'api_getVehicleList' => self::vehicleList(),
            'api_getVehicleStats' => self::vehicleStats(),
            'api_getRentalList' => self::rentalList(),
            'api_getRentalStats' => self::rentalStats(),
            'api_createRental' => self::createRental($args[0] ?? []),
            'api_returnRental', 'api_processRentalContracts', 'api_processEquipmentRentalContracts', 'setupRentalSheet', 'generateSampleRentalContracts', 'api_cleanEmptyRentalRows' => ['success' => true, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'results' => []],
            'api_getHousingList' => self::housingList(),
            'api_getHousingStats' => self::housingStats(),
            'api_getFlightList' => self::flightList(),
            'api_getOfficeSupplies' => self::officeSupplies(),
            'api_getVendorList' => self::vendors(),
            'api_getCompanyList' => \App\Models\Company::query()->where('status', 'active')->orderBy('name')->get()->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'api_getTeamList' => \App\Models\Team::query()->where('status', 'active')->orderBy('name')->get()->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'site_id' => $t->site_id])->all(),
            'api_getEmployeeList' => \App\Models\Employee::query()->where('employment_status', 'active')->orderBy('name')->get()->map(fn($e) => ['id' => $e->id, 'name' => $e->name, 'company_id' => $e->company_id, 'team_id' => $e->team_id])->all(),
            'api_createVendor' => ['success' => true, 'id' => 'V-' . random_int(100, 999)],
            'api_generateVendorEmailPrompt' => ['success' => true, 'draft' => "Hello,\n\nPlease send the latest quote and availability for the requested materials.\n\nRegards,\nNAHSHON MEP"],
            'api_translateToEnglish' => ['success' => true, 'text' => (string) ($args[0] ?? '')],
            'api_sendVendorEmail' => ['success' => true],
            'api_getVendorReplies' => ['success' => true, 'replies' => []],

            'api_getAllFolderFiles' => json_encode(['success' => true, 'data' => ['NAHSHON RECEIPT' => ['pending' => 3, 'done' => 28, 'total' => 31], 'UTILITY RECEIPT' => ['pending' => 1, 'done' => 12, 'total' => 13]]]),
            'api_bulkProcessDriveFolder' => json_encode(['success' => true, 'log' => ['Scanned pending receipts', 'Updated finance records']]),
            'api_getFinanceExcelBase64' => '',
            'api_getPersonnelCard' => self::realPersonnelCard((string) ($args[0] ?? '')),
            'api_syncWorkerStatus' => ['success' => true, 'messages' => ['Worker status updated', 'Related vehicle/housing assignments checked']],
            'api_universalAIScan' => self::universalAIScan($args),
            'api_nfcAssignVehicle' => self::nfcAssignVehicle($args),
            'api_nfcAssignHousing' => ['success' => true, 'message' => 'NFC assignment saved'],

            default => self::defaultResponse($method),
        };
    }

    public static function seedRecords(): array
    {
        $records = [];
        foreach (self::projects() as $project) {
            $records[] = self::record('wbs', $project['code'], $project['name'], 'Project', $project['code'], $project['status'] ?? 'Active', null, $project);
        }
        foreach (self::equipmentList() as $item) {
            $records[] = self::record('equipment', $item['id'], $item['name'], $item['type'], $item['site'], $item['status'], null, $item);
        }
        foreach (self::expenses('ALL', false) as $expense) {
            $records[] = self::record(
                'finance',
                (string) ($expense['id'] ?? ('EXP-' . md5(json_encode($expense)))),
                (string) ($expense['vendor'] ?? $expense['vendor_name'] ?? $expense['detail'] ?? $expense['description'] ?? $expense['category'] ?? 'Expense'),
                $expense['category'] ?? $expense['account'] ?? 'Other',
                $expense['site'] ?? 'HFF-02',
                $expense['status'] ?? 'pending',
                $expense['amount'] ?? null,
                $expense
            );
        }
        foreach (self::alerts('all') as $alert) {
            $records[] = self::record('safety', $alert['id'], $alert['title'], $alert['type'], $alert['site'] ?? 'HFF-02', $alert['status'], null, $alert);
        }
        foreach (self::vendors() as $vendor) {
            $records[] = self::record('vendors', $vendor['id'], $vendor['name'], $vendor['category'], $vendor['site'] ?? 'ALL', $vendor['contractStatus'] ?? 'Active', null, $vendor);
        }
        return $records;
    }

    private static function record(string $module, string $key, string $name, ?string $category, ?string $site, ?string $status, mixed $amount, array $payload): array
    {
        return [
            'module' => $module,
            'record_key' => $key,
            'name' => $name,
            'category' => $category,
            'site' => $site,
            'status' => $status,
            'amount' => $amount,
            'occurred_on' => \Illuminate\Support\Carbon::now()->toDateString(),
            'payload' => $payload,
        ];
    }

    public static function nfcAssignVehicle(array $args): array
    {
        $uid = $args[0] ?? null;
        $vehicleCode = $args[1] ?? null;

        if (blank($uid) || blank($vehicleCode)) {
            return ['success' => false, 'error' => 'NFC 카드 UID 또는 차량 ID가 비어있습니다.'];
        }

        try {
            $normalizedNfc = \App\Models\MemberRegistration::normalizeNfcUid($uid);
            
            $employee = \App\Models\Employee::where('badge_number', $normalizedNfc)->first();
            if (! $employee) {
                return ['success' => false, 'error' => '해당 NFC 카드를 소지한 직원을 찾을 수 없습니다. (NFC ID: ' . $normalizedNfc . ')'];
            }

            $vehicle = \App\Models\Vehicle::where('vehicle_code', $vehicleCode)->first();
            if (! $vehicle) {
                return ['success' => false, 'error' => '차량을 찾을 수 없습니다.'];
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($vehicle, $employee): void {
                // Terminate active rentals for this vehicle
                \App\Models\VehicleRental::where('vehicle_id', $vehicle->id)
                    ->whereNull('returned_at')
                    ->update([
                        'returned_at' => now(),
                        'end_mileage' => $vehicle->current_mileage,
                        'status' => 'returned',
                    ]);

                // Terminate active rentals for this employee
                \App\Models\VehicleRental::where('employee_id', $employee->id)
                    ->whereNull('returned_at')
                    ->update([
                        'returned_at' => now(),
                        'status' => 'returned',
                    ]);

                // Create active rental
                \App\Models\VehicleRental::create([
                    'vehicle_id' => $vehicle->id,
                    'employee_id' => $employee->id,
                    'company_id' => $employee->company_id,
                    'site_id' => $employee->site_id,
                    'rented_at' => now(),
                    'start_mileage' => $vehicle->current_mileage,
                    'status' => 'active',
                    'notes' => 'NFC 태그 배정',
                ]);

                // Update vehicle
                $vehicle->update(['status' => '운행중']);
            });

            return [
                'success' => true, 
                'message' => "NFC 카드 매핑 성공: {$employee->name}님에게 차량({$vehicle->model})이 배정되었습니다."
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function smartRecords(string $module): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('smart_records')) {
                $rows = SmartRecord::query()->where('module', $module)->get();
                if ($rows->isNotEmpty()) {
                    return $rows->map(fn (SmartRecord $record): array => array_merge($record->payload ?? [], [
                        'id' => $record->record_key,
                        'name' => $record->name,
                        'category' => $record->category,
                        'site' => $record->site,
                        'status' => $record->status,
                        'amount' => $record->amount !== null ? (float) $record->amount : ($record->payload['amount'] ?? null),
                    ]))->all();
                }
            }
        } catch (\Throwable) {
            // Static fallback keeps the SPA usable before migrations run.
        }
        return [];
    }

    public static function sites(): array
    {
        return [
            'HFF-02' => 'HFF-02 — Hoffman',
            'LGES-AZ' => 'LGES-AZ — Battery Plant',
            'NV-05' => 'NV-05 — Nevada EV Plant',
        ];
    }

    public static function personnel(string $siteId = 'ALL'): array
    {
        $fromDb = self::smartRecords('hr');
        $people = $fromDb ?: [
            ['id' => 'EMP-1001', 'badgeId' => '1001', 'nameEn' => 'James Kim', 'nameKr' => '김제임스', 'company' => 'NAHSHON MEP', 'team' => 'Electrical A', 'role' => 'Foreman', 'site' => 'HFF-02', 'visa' => 'E-2', 'visaExpiry' => '2026-09-30', 'safety' => '정상', 'workerStatus' => '파견중', 'phone' => '480-555-0101'],
            ['id' => 'EMP-1002', 'badgeId' => '1002', 'nameEn' => 'Min Lee', 'nameKr' => '이민', 'company' => 'AI Korea', 'team' => 'Pipe Crew', 'role' => 'Pipefitter', 'site' => 'HFF-02', 'visa' => 'B-1', 'visaExpiry' => '2026-08-15', 'safety' => '만료임박', 'workerStatus' => '파견중', 'phone' => '480-555-0102'],
            ['id' => 'EMP-1003', 'badgeId' => '1003', 'nameEn' => 'Carlos Rivera', 'nameKr' => '', 'company' => 'Local Union', 'team' => 'Electrical B', 'role' => 'Journeyman', 'site' => 'LGES-AZ', 'visa' => '-', 'visaExpiry' => '-', 'safety' => '정상', 'workerStatus' => '파견중', 'phone' => '480-555-0103'],
            ['id' => 'EMP-1004', 'badgeId' => '1004', 'nameEn' => 'Sophia Park', 'nameKr' => '박소피아', 'company' => 'NAHSHON MEP', 'team' => 'Controls', 'role' => 'Engineer', 'site' => 'NV-05', 'visa' => 'H-1B', 'visaExpiry' => '2027-01-10', 'safety' => '정상', 'workerStatus' => '파견중', 'phone' => '480-555-0104'],
            ['id' => 'EMP-1005', 'badgeId' => '1005', 'nameEn' => 'Daniel Cho', 'nameKr' => '조다니엘', 'company' => 'M-SOL', 'team' => 'QA/QC', 'role' => 'Inspector', 'site' => 'LGES-AZ', 'visa' => 'E-2', 'visaExpiry' => '2026-07-20', 'safety' => '주의', 'workerStatus' => '파견중', 'phone' => '480-555-0105'],
        ];

        return array_values(array_filter($people, fn ($p) => $siteId === 'ALL' || ($p['site'] ?? null) === $siteId));
    }

    public static function personnelStats(string $siteId = 'ALL'): array
    {
        $people = self::personnel($siteId);
        $byCompany = [];
        foreach ($people as $person) {
            $company = $person['company'] ?? 'Unknown';
            $byCompany[$company] = ($byCompany[$company] ?? 0) + 1;
        }
        return [
            'total' => count($people),
            'active' => count($people),
            'onLeave' => 0,
            'visaExpiringSoon' => count(array_filter($people, fn ($p) => ($p['safety'] ?? '') !== '정상')),
            'safetyExpiring' => count(array_filter($people, fn ($p) => ($p['safety'] ?? '') !== '정상')),
            'byCompany' => array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($byCompany), $byCompany),
        ];
    }

    public static function hrData(string $siteId = 'ALL'): array
    {
        return [
            'success' => true,
            'stats' => self::personnelStats($siteId),
            'list' => self::personnel($siteId),
            'attendance' => self::attendanceLive($siteId === 'ALL' ? 'HFF-02' : $siteId),
        ];
    }

    public static function globalAttendance(): array
    {
        $people = self::personnel('ALL');
        $checkedIn = array_map(fn ($p) => [
            'name' => $p['nameEn'], 'company' => $p['company'], 'team' => $p['team'], 'site' => $p['site'], 'checkIn' => '07:' . random_int(5, 45), 'checkOut' => null, 'nfcUid' => $p['badgeId'],
        ], array_slice($people, 0, 4));
        $notCheckedIn = array_map(fn ($p) => ['name' => $p['nameEn'], 'company' => $p['company'], 'site' => $p['site'], 'nfcUid' => $p['badgeId']], array_slice($people, 4));
        $siteStats = [];
        foreach (self::sites() as $id => $name) {
            $sitePeople = self::personnel($id);
            $present = count(array_filter($checkedIn, fn ($p) => $p['site'] === $id));
            $siteStats[$id] = ['siteName' => $name, 'totalActive' => count($sitePeople), 'presentCount' => $present];
        }
        return [
            'success' => true, 'mode' => 'global', 'date' => Carbon::now()->toDateString(), 'checkedIn' => $checkedIn,
            'notCheckedIn' => $notCheckedIn, 'siteStats' => $siteStats, 'totalPresent' => count($checkedIn), 'totalWorkers' => count($people),
            'absentCount' => count($notCheckedIn), 'activeSiteCount' => count($siteStats),
        ];
    }

    public static function attendanceLive(string $siteId): array
    {
        $people = self::personnel($siteId);
        $checkedIn = array_map(fn ($p) => ['name' => $p['nameEn'], 'company' => $p['company'], 'team' => $p['team'], 'checkIn' => '07:2' . random_int(0, 9), 'checkOut' => null, 'nfcUid' => $p['badgeId']], array_slice($people, 0, max(1, count($people) - 1)));
        $notCheckedIn = array_map(fn ($p) => ['name' => $p['nameEn'], 'company' => $p['company'], 'nfcUid' => $p['badgeId']], array_slice($people, max(1, count($people) - 1)));
        $summary = [];
        foreach ($checkedIn as $row) {
            $summary[$row['team']] = ($summary[$row['team']] ?? 0) + 1;
        }
        return ['success' => true, 'checkedIn' => $checkedIn, 'notCheckedIn' => $notCheckedIn, 'teamSummary' => array_map(fn ($team, $count) => ['team' => $team, 'count' => $count], array_keys($summary), $summary), 'totalActive' => count($people), 'presentCount' => count($checkedIn), 'absentCount' => count($notCheckedIn), 'date' => Carbon::now()->toDateString()];
    }

    public static function dailyTeamMatrix(string $siteId): array
    {
        $people = self::personnel($siteId);
        $teams = array_values(array_unique(array_map(fn ($p) => $p['team'] ?? 'General', $people)));
        return ['success' => true, 'date' => Carbon::now()->toDateString(), 'teams' => $teams, 'matrix' => [], 'foremen' => [], 'subtotals' => [], 'totals' => ['total' => count($people)]];
    }

    public static function dailyAttendanceDetail(string $siteId, mixed $date): array
    {
        $people = self::personnel($siteId === 'ALL' ? 'HFF-02' : $siteId);
        $teams = [];
        foreach ($people as $person) {
            $teams[$person['team']][] = array_merge($person, ['isOpen' => true, 'todayIn' => '07:20', 'todayOut' => '미마감']);
        }
        return [
            'success' => true, 'date' => $date ?: Carbon::now()->toDateString(), 'availableDates' => [Carbon::now()->toDateString()],
            'companies' => [[
                'name' => 'NAHSHON MEP', 'total' => count($people), 'divide' => ['manager' => 1, 'korean' => 2, 'local' => max(0, count($people)-3)],
                'teams' => array_map(fn ($team, $members) => ['team' => $team, 'members' => array_values($members), 'count' => count($members)], array_keys($teams), $teams),
            ]],
            'teamStats' => array_map(fn ($team, $members) => ['team' => $team, 'count' => count($members)], array_keys($teams), $teams),
            'totalAttended' => count($people),
        ];
    }

    public static function employeeDetail(string $badgeId, string $siteId): array
    {
        $person = collect(self::personnel('ALL'))->first(fn ($p) => ($p['badgeId'] ?? $p['id']) === $badgeId || ($p['id'] ?? '') === $badgeId) ?: self::personnel($siteId)[0] ?? null;
        return ['success' => (bool) $person, 'employee' => $person ? array_merge($person, ['todayIn' => '07:20', 'todayOut' => '미마감', 'isOpen' => true]) : null];
    }

    public static function personnelCard(string $uid): array
    {
        $person = collect(self::personnel('ALL'))->first(fn ($p) => ($p['id'] ?? '') === $uid || ($p['badgeId'] ?? '') === $uid) ?? self::personnel('ALL')[0];
        return [
            'success' => true,
            'person' => array_merge($person, [
                'passport' => 'M12345678',
                'birthday' => '1988-04-12',
                'nationality' => $person['company'] === 'Local Union' ? 'USA' : 'Korea',
            ]),
            'vehicle' => ['model' => 'Ford F-250', 'plate' => 'AZ-MEP-102', 'rentEnd' => '2026-07-01', 'mileage' => 21450],
            'housing' => ['building' => 'Mesa House A', 'unit' => 'Room 2', 'address' => 'Mesa, AZ', 'rent' => 1200],
            'flights' => self::flightList(),
        ];
    }
    public static function projects(): array
    {
        $fromDb = self::smartRecords('wbs');
        return $fromDb ?: [
            ['code' => 'HFF-02', 'name' => 'Hoffman Logistics Hub', 'manager' => 'James Kim', 'progress' => 68, 'color' => '#2563eb', 'endDate' => '2026-09-15', 'status' => 'On Track', 'signal' => 'Schedule risk: low', 'action' => 'Close RFI-104'],
            ['code' => 'LGES-AZ', 'name' => 'Battery Plant AZ', 'manager' => 'Sophia Park', 'progress' => 42, 'color' => '#10b981', 'endDate' => '2026-11-30', 'status' => 'Watch', 'signal' => 'Material delivery', 'action' => 'Confirm conduit ETA'],
            ['code' => 'NV-05', 'name' => 'Nevada EV Plant', 'manager' => 'Daniel Cho', 'progress' => 24, 'color' => '#f59e0b', 'endDate' => '2027-02-20', 'status' => 'At Risk', 'signal' => 'Labor ramp-up', 'action' => 'Approve overtime plan'],
        ];
    }

    public static function actionItems(): array
    {
        return [
            ['id' => 'ACT-901', 'type' => 'Safety', 'summary' => 'Open trench barricade inspection required', 'assignee' => 'Safety', 'status' => 'critical', 'date' => 'Today'],
            ['id' => 'ACT-902', 'type' => 'Finance', 'summary' => 'Rental invoice approval over threshold', 'assignee' => 'Admin', 'status' => 'warning', 'date' => 'Today'],
            ['id' => 'ACT-903', 'type' => 'WBS', 'summary' => 'LGES-AZ pull plan progress update', 'assignee' => 'PM', 'status' => 'pending', 'date' => 'Tomorrow'],
        ];
    }

    public static function commandCenter(string $siteId): array
    {
        return [
            'success' => true,
            'generatedAt' => Carbon::now()->format('Y-m-d H:i'),
            'health' => ['decisionQueue' => 3, 'revenueAtRisk' => 18400, 'safetyBlockers' => 1, 'scheduleRisk' => 2],
            'decisions' => [
                ['title' => 'Approve rental extension', 'summary' => 'Scissor lift return date conflicts with ceiling rough-in.', 'priority' => 'warning', 'owner' => 'PM', 'action' => 'Extend 3 days'],
                ['title' => 'Capture change order evidence', 'summary' => 'Owner requested extra receptacles in Area B.', 'priority' => 'critical', 'owner' => 'Foreman', 'action' => 'Create CO packet'],
            ],
            'projects' => self::projects(),
            'billing' => [['label' => 'Unbilled CO candidates', 'amount' => 18400, 'status' => '주의', 'action' => 'Review'], ['label' => 'Pending rental invoice', 'amount' => 4200, 'status' => '승인대기', 'action' => 'Approve']],
            'brief' => ['Attendance is synced across active sites.', 'One safety blocker requires action before work starts.', 'Two WBS items are behind target progress.'],
        ];
    }

    public static function financeStats(string $siteId = 'ALL'): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('mobile_expenses')) {
                $startOfMonth = Carbon::now()->startOfMonth();
                $rows = self::financeExpenseQuery($siteId)
                    ->whereIn('status', ['pending', 'approved', 'paid'])
                    ->get();
                $mtdRows = $rows->filter(fn (MobileExpense $e): bool => $e->expense_date?->gte($startOfMonth) ?? false);
                $mtdTotal = (float) $mtdRows->sum(fn (MobileExpense $e): float => (float) $e->amount);
                $pending = $rows->where('status', 'pending');
                $paid = $rows->where('status', 'paid');
                $claimable = $rows
                    ->where('status', 'approved')
                    ->where('payment_type', 'personal');

                $preApprovals = collect();
                if (Schema::hasTable('expense_pre_approvals')) {
                    $preApprovals = self::financePreApprovalQuery($siteId)
                        ->whereIn('status', ['pending', 'approved'])
                        ->get();
                }

                $approvedPreApprovals = $preApprovals->where('status', 'approved');
                $pendingPreApprovals = $preApprovals->where('status', 'pending');
                $mtdBudget = (float) $approvedPreApprovals
                    ->filter(fn (ExpensePreApproval $approval): bool => $approval->planned_date?->gte($startOfMonth) ?? false)
                    ->sum(fn (ExpensePreApproval $approval): float => (float) $approval->estimated_amount);

                $palette = ['#2563eb', '#10b981', '#f59e0b', '#7c3aed', '#ef4444', '#06b6d4', '#eab308'];
                $grouped = $mtdRows
                    ->groupBy(fn (MobileExpense $e): string => $e->accounting_account ?: ($e->category ?: FinanceChartOfAccounts::FALLBACK_ACCOUNT))
                    ->map(fn ($group): float => (float) $group->sum(fn (MobileExpense $e): float => (float) $e->amount))
                    ->sortDesc();

                $byCategory = [];
                $i = 0;
                foreach ($grouped as $name => $amount) {
                    $byCategory[] = ['name' => $name, 'amount' => $amount, 'color' => $palette[$i % count($palette)]];
                    $i++;
                }

                return [
                    'mtdTotal' => $mtdTotal,
                    'mtdBudget' => $mtdBudget,
                    'pendingApproval' => $pending->count() + $pendingPreApprovals->count(),
                    'pendingAmount' => (float) $pending->sum(fn (MobileExpense $e): float => (float) $e->amount)
                        + (float) $pendingPreApprovals->sum(fn (ExpensePreApproval $approval): float => (float) $approval->estimated_amount),
                    'claimable' => (float) $claimable->sum(fn (MobileExpense $e): float => (float) $e->amount),
                    'paidAmount' => (float) $paid->sum(fn (MobileExpense $e): float => (float) $e->amount),
                    'approvedPreApprovalAmount' => (float) $approvedPreApprovals->sum(fn (ExpensePreApproval $approval): float => (float) $approval->estimated_amount),
                    'pendingPreApprovalAmount' => (float) $pendingPreApprovals->sum(fn (ExpensePreApproval $approval): float => (float) $approval->estimated_amount),
                    'approvedExpenseAmount' => (float) $rows->where('status', 'approved')->sum(fn (MobileExpense $e): float => (float) $e->amount),
                    'budgetBalance' => $mtdBudget - $mtdTotal,
                    'byCategory' => $byCategory,
                ];
            }
        } catch (\Throwable) {
            // Fall back to empty totals when the table is not ready.
        }

        return ['mtdTotal' => 0, 'mtdBudget' => 0, 'pendingApproval' => 0, 'pendingAmount' => 0, 'claimable' => 0, 'byCategory' => []];
    }

    public static function expenses(string $siteId = 'ALL', bool $applyUserScope = true): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('mobile_expenses')) {
                return self::financeExpenseQuery($siteId, $applyUserScope)
                    ->with(['site', 'employee', 'preApproval'])
                    ->orderByDesc('expense_date')
                    ->orderByDesc('id')
                    ->get()
                    ->map(function (MobileExpense $e): array {
                        $canModify = self::canModifyMobileExpense($e);
                        $employeeName = trim(($e->employee?->first_name ?? '') . ' ' . ($e->employee?->last_name ?? ''));

                        return [
                            'id' => 'EXP-' . $e->id,
                            'expenseId' => $e->id,
                            'date' => optional($e->expense_date)->toDateString() ?? '',
                            'site' => $e->site?->code ?: 'Global / Office',
                            'account' => $e->accounting_account ?: ($e->category ?: '-'),
                            'category' => $e->accounting_account ?: ($e->category ?: FinanceChartOfAccounts::FALLBACK_ACCOUNT),
                            'departmentClass' => $e->class ?: '',
                            'detail' => $e->description ?: '-',
                            'amount' => (float) $e->amount,
                            'method' => $e->payment_type,
                            'claimable' => $e->payment_type === 'personal' && $e->status === 'approved',
                            'status' => $e->status,
                            'employeeName' => $employeeName ?: ($e->employee?->email ?: ''),
                            'preApprovalId' => $e->expense_pre_approval_id,
                            'preApprovalTitle' => $e->preApproval?->title ?: '',
                            'preApprovalAmount' => $e->preApproval ? (float) $e->preApproval->estimated_amount : null,
                            'reviewedAt' => optional($e->reviewed_at)->toIso8601String(),
                            'paidAt' => optional($e->paid_at)->toIso8601String(),
                            'receiptUrl' => self::mobileExpenseReceiptUrl($e),
                            'canModify' => $canModify,
                            'editUrl' => $canModify ? route('mobile-expense.edit', $e, false) : '',
                            'deleteUrl' => $canModify ? route('mobile-expense.destroy', $e, false) : '',
                        ];
                    })
                    ->all();
            }
        } catch (\Throwable) {
            // Fall back to an empty list when the table is not ready.
        }

        return [];
    }

    private static function mobileExpenseReceiptUrl(\App\Models\MobileExpense $expense): string
    {
        if (! $expense->receipt_path && ! $expense->receipt_file) {
            return '';
        }

        try {
            return route('mobile-expense.receipt', $expense, false);
        } catch (\Throwable) {
            return $expense->receipt_path;
        }
    }

    private static function canModifyMobileExpense(\App\Models\MobileExpense $expense): bool
    {
        $user = auth()->user();
        $canManageAll = in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true);

        if ($canManageAll) {
            return true;
        }

        return (int) $expense->employee_id === (int) $user?->employee_id
            && in_array($expense->status, ['draft', 'pending', 'rejected'], true);
    }

    private static function financeExpenseQuery(string $siteId = 'ALL', bool $applyUserScope = true)
    {
        $query = MobileExpense::query();

        self::applyFinanceSiteScope($query, $siteId);
        if ($applyUserScope) {
            self::applyFinanceUserScope($query);
        }

        return $query;
    }

    private static function financePreApprovalQuery(string $siteId = 'ALL', bool $applyUserScope = true)
    {
        $query = ExpensePreApproval::query();

        self::applyFinanceSiteScope($query, $siteId);
        if ($applyUserScope) {
            self::applyFinanceUserScope($query);
        }

        return $query;
    }

    private static function applyFinanceSiteScope($query, string $siteId): void
    {
        $resolvedSiteId = self::resolveSiteId($siteId);

        if ($resolvedSiteId !== null) {
            $query->where(function ($siteQuery) use ($resolvedSiteId): void {
                $siteQuery
                    ->where('site_id', $resolvedSiteId)
                    ->orWhereNull('site_id');
            });
        }
    }

    private static function applyFinanceUserScope($query): void
    {
        $user = auth()->user();

        if (! $user) {
            $query->whereRaw('1 = 0');
            return;
        }

        if (
            in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)
            || $user->access_scope === 'all_sites'
        ) {
            return;
        }

        $employee = $user->employee;

        if ($user->access_scope === 'company' && ($user->allowed_company_id || $employee?->company_id)) {
            $query->where('company_id', $user->allowed_company_id ?: $employee?->company_id);
            return;
        }

        if ($user->access_scope === 'site' && ($user->allowed_site_id || $employee?->site_id)) {
            $query->where('site_id', $user->allowed_site_id ?: $employee?->site_id);
            return;
        }

        if ($user->access_scope === 'team' && $user->allowed_team_id) {
            $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('team_id', $user->allowed_team_id));
            return;
        }

        if ($user->employee_id) {
            $query->where('employee_id', $user->employee_id);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private static function resolveSiteId(string $siteId): ?int
    {
        $siteId = trim($siteId);

        if ($siteId === '' || in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            return null;
        }

        if (is_numeric($siteId)) {
            return (int) $siteId;
        }

        try {
            if (class_exists(Schema::class) && Schema::hasTable('sites')) {
                $siteCode = str_contains($siteId, ' - ') ? trim(strstr($siteId, ' - ', true)) : $siteId;

                return Site::query()
                    ->where('code', $siteId)
                    ->orWhere('code', $siteCode)
                    ->orWhere('name', $siteId)
                    ->value('id');
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public static function equipmentStats(): array
    {
        try {
            if (class_exists(\App\Models\Equipment::class) && Schema::hasTable('equipments')) {
                $user = auth()->user();
                $all = \App\Models\Equipment::query()->visibleTo($user)->get();
                return [
                    'total' => $all->count(),
                    'operable' => $all->where('status', '사용중')->count() + $all->where('status', '대기중')->count(),
                    'inoperable' => $all->where('status', '정비중')->count(),
                    'todayInspections' => 0,
                ];
            }
        } catch (\Throwable) {
        }
        return ['total' => 0, 'operable' => 0, 'inoperable' => 0, 'todayInspections' => 0];
    }

    public static function equipmentList(): array
    {
        try {
            if (class_exists(\App\Models\Equipment::class) && Schema::hasTable('equipments')) {
                $user = auth()->user();
                return \App\Models\Equipment::query()
                    ->visibleTo($user)
                    ->with('site')
                    ->orderByDesc('id')
                    ->get()
                    ->map(function ($eq) {
                        $photoUrl = null;
                        if ($eq->photo_front) {
                            $relativePath = ltrim(str_replace('/storage/', '', $eq->photo_front), '/');
                            $photoUrl = route('equipment.file', ['path' => $relativePath]);
                        }
                        return [
                            'id' => $eq->equipment_code,
                            'assetId' => $eq->equipment_code,
                            'name' => $eq->model,
                            'type' => $eq->equipment_type,
                            'category' => $eq->equipment_type,
                            'site' => $eq->site ? $eq->site->code : 'Global',
                            'inspector' => '-',
                            'lastCheck' => $eq->updated_at->toDateString(),
                            'checkStatus' => '완료',
                            'status' => $eq->status === '사용중' ? '운행가능' : ($eq->status === '정비중' ? '수리필요' : '대기중'),
                            'brand' => $eq->vendor ?: '-',
                            'photoUrl' => $photoUrl,
                        ];
                    })
                    ->all();
            }
        } catch (\Throwable) {
        }
        return [];
    }
    public static function toolStats(): array { return ['total' => 18, 'available' => 12, 'checkedOut' => 6, 'damaged' => 2]; }
    public static function toolList(): array { return [['id' => 'TL-101', 'name' => 'Cordless Hammer Drill', 'category' => 'Power Tool', 'status' => '불출중', 'holder' => 'EMP-1002', 'checkoutDate' => '2026-06-18', 'condition' => '정상'], ['id' => 'TL-102', 'name' => 'Torque Wrench', 'category' => 'Hand Tool', 'status' => '보관중', 'holder' => null, 'checkoutDate' => null, 'condition' => '정상'], ['id' => 'TL-103', 'name' => 'Laser Level', 'category' => 'Survey', 'status' => '수리필요', 'holder' => null, 'checkoutDate' => null, 'condition' => '손상']]; }
    public static function toolTransactions(): array { return [['time' => '08:12', 'action' => '불출', 'toolName' => 'Cordless Hammer Drill', 'toolId' => 'TL-101', 'userId' => 'EMP-1002', 'condition' => '정상'], ['time' => '11:40', 'action' => '반납', 'toolName' => 'Laser Level', 'toolId' => 'TL-103', 'userId' => 'EMP-1005', 'condition' => '손상']]; }

    public static function safetyStats(): array { return ['daysNoIncident' => 47, 'unresolved' => 3, 'resolved' => 18, 'urgent' => 1, 'warning' => 2, 'normal' => 8]; }

    public static function safetyWorkItems(string $siteId = 'ALL'): array
    {
        try {
            return ['success' => true, 'items' => app(\App\Services\Safety\SafetyWorkService::class)->items($siteId)];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage(), 'items' => []];
        }
    }

    public static function saveSafetyWorkItems(mixed $items, string $siteId = 'ALL'): array
    {
        if (! is_array($items)) {
            return ['success' => false, 'error' => 'Invalid work items payload.'];
        }

        try {
            $saved = app(\App\Services\Safety\SafetyWorkService::class)->save($items, $siteId, auth()->id());

            return ['success' => true, 'saved' => $saved];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function generateSafetyPlan(mixed $item, string $siteId = 'ALL'): array
    {
        if (! is_array($item) || blank($item['id'] ?? null)) {
            return ['success' => false, 'error' => '작업 정보가 올바르지 않습니다.'];
        }

        try {
            $result = app(\App\Services\Safety\SafetyWorkService::class)->generatePlan($item, $siteId, auth()->id());

            return ['success' => true, 'item' => $result['item'], 'plan' => $result['plan']];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function recommendSafetyProgress(mixed $item, string $siteId = 'ALL'): array
    {
        if (! is_array($item) || blank($item['id'] ?? null)) {
            return ['success' => false, 'error' => '작업 정보가 올바르지 않습니다.'];
        }

        try {
            $result = app(\App\Services\Safety\SafetyWorkService::class)->recommendProgress($item, $siteId, auth()->id());

            return ['success' => true, 'item' => $result['item'], 'recommendation' => $result['recommendation']];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public static function alerts(mixed $filter = 'all'): array
    {
        $fromDb = self::smartRecords('safety');
        return $fromDb ?: [
            ['id' => 'ALT-100', 'title' => 'Open edge protection missing', 'type' => 'Safety', 'site' => 'HFF-02', 'level' => 'critical', 'status' => '미처리', 'date' => '2026-06-19'],
            ['id' => 'ALT-101', 'title' => 'Visa expiry review', 'type' => 'HR', 'site' => 'LGES-AZ', 'level' => 'warning', 'status' => '처리중', 'date' => '2026-06-18'],
            ['id' => 'ALT-102', 'title' => 'Rental return due in 2 days', 'type' => 'Rental', 'site' => 'NV-05', 'level' => 'normal', 'status' => '미처리', 'date' => '2026-06-17'],
        ];
    }
    public static function ptwList(): array { return [['id' => 'PTW-01', 'job' => 'Hot work Area B', 'status' => '승인대기', 'owner' => 'Safety'], ['id' => 'PTW-02', 'job' => 'Lift work Level 3', 'status' => '완료', 'owner' => 'PM']]; }
    public static function inspections(): array { return [['id' => 'INS-01', 'area' => 'Area A', 'result' => 'Pass', 'inspector' => 'Carlos'], ['id' => 'INS-02', 'area' => 'Area C', 'result' => 'Fail', 'inspector' => 'Daniel']]; }
    public static function trainingRecords(): array { return [['id' => 'TR-01', 'name' => 'James Kim', 'course' => 'OSHA 30', 'expires' => '2027-04-01', 'status' => '정상']]; }
    public static function safetyDocs(): array { return [['id' => 'DOC-01', 'name' => 'Site Safety Plan', 'status' => '완료'], ['id' => 'DOC-02', 'name' => 'JHA Area B', 'status' => '승인대기']]; }
    public static function oshaForm300(): array { return [['caseNo' => 'OSHA-001', 'date' => '2026-03-12', 'type' => 'First Aid', 'status' => 'Closed']]; }
    public static function certMatrix(): array { return [['name' => 'James Kim', 'osha30' => 'OK', 'lift' => 'OK', 'firstAid' => 'Expiring']]; }
    public static function violations(): array { return [['id' => 'VIO-01', 'title' => 'PPE missing', 'status' => 'Corrected']]; }
    public static function tbmRecords(): array { return [['id' => 'TBM-01', 'topic' => 'Heat stress', 'attendees' => 18, 'date' => '2026-06-19']]; }

    public static function payrollDashboard(mixed $periodStart, string $siteId = 'ALL'): array
    {
        try {
            return app(\App\Services\Payroll\PayrollCalculator::class)
                ->dashboard(is_string($periodStart) && $periodStart !== '' ? $periodStart : null, $siteId);
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function runPayroll(mixed $periodStart, string $siteId = 'ALL'): array
    {
        $user = auth()->user();

        if (! in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)) {
            return ['success' => false, 'error' => '급여 정산 실행 권한이 없습니다.'];
        }

        try {
            $run = app(\App\Services\Payroll\PayrollCalculator::class)
                ->runPayroll(is_string($periodStart) && $periodStart !== '' ? $periodStart : null, $siteId, $user?->id);

            return [
                'success' => true,
                'runId' => $run->id,
                'code' => $run->code,
                'headcount' => $run->headcount,
                'totalGross' => (float) $run->total_gross,
                'totalNet' => (float) $run->total_net,
                'certifiedUrl' => route('payroll.certified', $run),
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public static function inventoryDashboard(string $siteId = 'ALL'): array
    {
        try {
            if (! Schema::hasTable('equipments')) {
                return ['success' => true, 'totals' => ['count' => 0, 'value' => 0, 'inUse' => 0, 'inStorage' => 0, 'repair' => 0, 'inspectionDue' => 0], 'matrix' => ['categories' => [], 'sites' => [], 'cells' => []], 'recent' => [], 'upcomingInspections' => []];
            }

            return app(\App\Services\Inventory\InventoryService::class)->dashboard($siteId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public static function inventoryAssetDetail(string $assetId): array
    {
        try {
            if (! Schema::hasTable('equipments')) {
                return ['success' => false, 'error' => '자산 테이블이 없습니다.'];
            }

            return app(\App\Services\Inventory\InventoryService::class)->assetDetail($assetId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function vehicleStats(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('vehicles')) {
                $query = \App\Models\Vehicle::query()->visibleTo(auth()->user());
                $all = $query->get();

                $total = $all->count();
                $active = $all->where('status', '운행중')->count();
                $maintenance = $all->where('status', '정비중')->count();
                $available = $all->where('status', '대기중')->count();

                // Rent expiring within 60 days
                $limit60 = now()->addDays(60)->toDateString();
                $today = now()->toDateString();
                $rentExpiringSoon = $all->filter(fn($v) => $v->rent_end && $v->rent_end->toDateString() >= $today && $v->rent_end->toDateString() <= $limit60)->count();

                return [
                    'total' => $total,
                    'active' => $active,
                    'maintenance' => $maintenance,
                    'available' => $available,
                    'rentExpiringSoon' => $rentExpiringSoon,
                ];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error in vehicleStats shim: ' . $e->getMessage());
        }

        return ['total' => 0, 'active' => 0, 'available' => 0, 'maintenance' => 0, 'rentExpiringSoon' => 0];
    }

    public static function vehicleList(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('vehicles')) {
                return \App\Models\Vehicle::query()
                    ->with(['activeRental.employee'])
                    ->visibleTo(auth()->user())
                    ->get()
                    ->map(fn (\App\Models\Vehicle $v): array => [
                        'id' => $v->vehicle_code,
                        'realId' => $v->id,
                        'plate' => $v->plate_number ?: '-',
                        'type' => $v->vehicle_type ?: '차량',
                        'model' => $v->model,
                        'company' => $v->vendor ?: '-',
                        'rentEnd' => $v->rent_end ? $v->rent_end->toDateString() : '-',
                        'insuranceExp' => $v->insurance_expiry ? $v->insurance_expiry->toDateString() : '-',
                        'assignee' => $v->activeRental?->employee?->name ?: '',
                        'mileage' => (int) $v->current_mileage,
                        'nextOil' => (int) $v->next_oil_change_mileage ?: ((int) $v->current_mileage + 5000),
                        'status' => $v->status ?: '대기중',
                        'registrationMethod' => $v->registration_method === 'AI자동분석' ? 'AI자동분석' : 'manual',
                        'photo_front' => $v->photo_front,
                        'photo_rear' => $v->photo_rear,
                        'photo_left' => $v->photo_left,
                        'photo_right' => $v->photo_right,
                        'contract_path' => $v->contract_path,
                    ])
                    ->all();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error in vehicleList shim: ' . $e->getMessage());
        }

        return [];
    }
    public static function rentalStats(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('equipments')) {
                $query = \App\Models\Equipment::query()->visibleTo(auth()->user());
                $all = $query->get();

                $total = $all->count();
                $active = $all->where('status', '사용중')->count();
                $available = $all->where('status', '대기중')->count();

                // Group by company dynamically
                $companies = \App\Models\Company::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get();

                $byCompany = [];
                foreach ($companies as $comp) {
                    $inUse = $all->where('company_id', $comp->id)->where('status', '사용중')->count();
                    $byCompany[] = [
                        'name' => $comp->name,
                        'count' => $inUse,
                    ];
                }

                return [
                    'total' => $total,
                    'active' => $active,
                    'available' => $available,
                    'byCompany' => $byCompany,
                ];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error in rentalStats shim: ' . $e->getMessage());
        }

        return ['total' => 0, 'active' => 0, 'available' => 0, 'byCompany' => []];
    }

    public static function rentalList(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('equipments')) {
                return \App\Models\Equipment::query()
                    ->with(['company', 'team', 'employee', 'site'])
                    ->visibleTo(auth()->user())
                    ->get()
                    ->map(fn (\App\Models\Equipment $e): array => [
                        'id' => $e->equipment_code,
                        'realId' => $e->id,
                        'equipType' => $e->equipment_type,
                        'model' => $e->model,
                        'vendor' => $e->vendor ?: '-',
                        'startDate' => $e->rent_start ? $e->rent_start->toDateString() : '-',
                        'endDate' => $e->rent_end ? $e->rent_end->toDateString() : '-',
                        'dailyRate' => (int) $e->daily_rate,
                        'deliveryFee' => (int) $e->delivery_fee,
                        'status' => $e->status ?: '대기중',
                        'company' => $e->company?->name ?: '-',
                        'companyId' => $e->company_id,
                        'team' => $e->team?->name ?: '-',
                        'teamId' => $e->team_id,
                        'siteId' => $e->site_id,
                        'siteCode' => $e->site?->code ?: '창고',
                        'operator' => $e->employee?->name ?: ($e->payload['custom_operator'] ?? ''),
                        'operatorId' => $e->employee_id,
                        'contract_path' => $e->contract_path,
                        'photo_front' => $e->photo_front,
                        'photo_rear' => $e->photo_rear,
                        'photo_left' => $e->photo_left,
                        'photo_right' => $e->photo_right,
                        'payload' => $e->payload,
                    ])
                    ->all();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error in rentalList shim: ' . $e->getMessage());
        }

        return [];
    }
    public static function housingStats(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('housings')) {
                $rows = \App\Models\Housing::query()->get();
                $total = $rows->count();
                $occupied = $rows->filter(fn (\App\Models\Housing $h): bool => (int) $h->beds > 0 && (int) $h->occupied >= (int) $h->beds)->count();
                $maintenance = $rows->where('status', 'maintenance')->count();

                return [
                    'total' => $total,
                    'occupied' => $occupied,
                    'available' => max(0, $total - $occupied),
                    'maintenance' => $maintenance,
                    'occupancyRate' => $total > 0 ? (int) round($occupied / $total * 100) : 0,
                ];
            }
        } catch (\Throwable) {
            // Fall back to empty stats when the table is not ready.
        }

        return ['total' => 0, 'occupied' => 0, 'available' => 0, 'maintenance' => 0, 'occupancyRate' => 0];
    }

    public static function housingList(): array
    {
        try {
            if (class_exists(Schema::class) && Schema::hasTable('housings')) {
                return \App\Models\Housing::query()
                    ->with('site')
                    ->orderBy('code')
                    ->get()
                    ->map(fn (\App\Models\Housing $h): array => [
                        'id' => $h->code,
                        'name' => $h->name,
                        'site' => $h->site?->code ?: '-',
                        'beds' => (int) $h->beds,
                        'occupied' => (int) $h->occupied,
                        'status' => self::housingStatusLabel($h->status),
                    ])
                    ->all();
            }
        } catch (\Throwable) {
            // Fall back to an empty list when the table is not ready.
        }

        return [];
    }

    private static function housingStatusLabel(?string $status): string
    {
        return match ($status) {
            'available' => '정상',
            'full' => '만실',
            'maintenance' => '수리필요',
            'inactive' => '미사용',
            default => (string) $status,
        };
    }

    public static function createRental(array $payload): array
    {
        try {
            $siteCode = $payload['siteId'] ?? 'HFF-02';
            $siteId = \App\Models\Site::where('code', $siteCode)->value('id');

            $equipment = \App\Models\Equipment::create([
                'site_id' => $siteId,
                'equipment_type' => $payload['equipType'] ?? 'Other',
                'model' => $payload['model'] ?? '',
                'vendor' => $payload['vendor'] ?? null,
                'rent_start' => $payload['startDate'] ?? null,
                'rent_end' => $payload['endDate'] ?? null,
                'daily_rate' => (int) ($payload['dailyRate'] ?? 0),
                'delivery_fee' => (int) ($payload['deliveryFee'] ?? 0),
                'status' => '대기중',
                'registration_method' => 'manual',
            ]);

            return ['success' => true, 'id' => $equipment->equipment_code];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public static function flightList(): array { return [['id' => 'FL-001', 'name' => 'Han Gildong', 'direction' => '입국', 'from' => 'ICN', 'to' => 'PHX', 'depDateTime' => '2026-06-28 10:30', 'airline' => 'Korean Air', 'pnr' => 'KXNV7T', 'price' => 1240, 'status' => '발권', 'needPickup' => true, 'pickupBy' => 'Lee', 'housingReady' => true]]; }
    public static function officeSupplies(): array { return [['id' => 'OF-001', 'category' => '소모품', 'name' => 'Copy Paper A4', 'qty' => 3, 'minQty' => 5, 'location' => 'Office cabinet', 'lastRestock' => '2026-06-01', 'unitPrice' => 45, 'reorder' => true], ['id' => 'OF-002', 'category' => 'Safety', 'name' => 'Safety Vest', 'qty' => 8, 'minQty' => 10, 'location' => 'Safety shelf', 'lastRestock' => '2026-05-24', 'unitPrice' => 35, 'reorder' => true]]; }
    public static function vendors(): array { $fromDb = self::smartRecords('vendors'); return $fromDb ?: [['id' => 'VEN-001', 'name' => 'Graybar', 'category' => 'Electrical Supply', 'manager' => 'Amy', 'phone' => '602-555-0111', 'email' => 'quotes@graybar.example', 'contractStatus' => '진행중', 'site' => 'ALL'], ['id' => 'VEN-002', 'name' => 'United Rentals', 'category' => 'Equipment Rental', 'manager' => 'Mark', 'phone' => '602-555-0122', 'email' => 'az@united.example', 'contractStatus' => '진행중', 'site' => 'ALL']]; }

    public static function wbsTree(string $projectId, string $siteId = 'ALL'): array
    {
        try {
            if (! Schema::hasTable('wbs_items')) {
                return ['success' => true, 'projectId' => $projectId, 'stages' => []];
            }

            return app(\App\Services\Wbs\WbsService::class)->tree($projectId, $siteId);
        } catch (\Throwable $e) {
            return ['success' => false, 'projectId' => $projectId, 'stages' => [], 'error' => $e->getMessage()];
        }
    }

    public static function projectProgressSummary(string $projectId, string $siteId = 'ALL'): array
    {
        try {
            if (! Schema::hasTable('wbs_items')) {
                return ['success' => true, 'projectId' => $projectId, 'progress' => 0, 'totalWbsCount' => 0, 'completedCount' => 0, 'inProgressCount' => 0, 'stages' => []];
            }

            return app(\App\Services\Wbs\WbsService::class)->progressSummary($projectId, $siteId);
        } catch (\Throwable $e) {
            return ['success' => false, 'projectId' => $projectId, 'progress' => 0, 'stages' => [], 'error' => $e->getMessage()];
        }
    }

    public static function processWbsManual(string $projectId, string $siteId = 'ALL'): array
    {
        try {
            return app(\App\Services\Wbs\GeminiWbsAnalyzer::class)->processManual($projectId, $siteId);
        } catch (\Throwable $e) {
            return ['success' => false, 'processed' => 0, 'results' => [], 'error' => $e->getMessage()];
        }
    }

    public static function realSites(): array
    {
        try {
            if (! Schema::hasTable('sites')) {
                return [];
            }

            return Site::query()
                ->whereHas('employees', fn ($query) => $query->where('employment_status', 'active'))
                ->orderBy('code')
                ->get()
                ->mapWithKeys(fn (Site $site): array => [$site->code => trim($site->code . ' - ' . $site->name)])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function realPersonnel(string $siteId = 'ALL'): array
    {
        return self::employeeRows($siteId)
            ->map(fn (Employee $employee): array => self::formatEmployee($employee))
            ->values()
            ->all();
    }

    public static function realPersonnelStats(string $siteId = 'ALL'): array
    {
        $people = self::realPersonnel($siteId);
        $byCompany = [];

        foreach ($people as $person) {
            $company = $person['company'] ?: 'Unassigned';
            $byCompany[$company] = ($byCompany[$company] ?? 0) + 1;
        }

        return [
            'total' => count($people),
            'active' => count(array_filter($people, fn ($p) => ($p['workerStatus'] ?? '') === 'active')),
            'onLeave' => count(array_filter($people, fn ($p) => ($p['workerStatus'] ?? '') === 'on_leave')),
            'visaExpiringSoon' => count(array_filter($people, fn ($p) => self::dateWithinDays($p['visaExpiry'] ?? null, 60))),
            'safetyExpiring' => count(array_filter($people, fn ($p) => self::dateWithinDays($p['safetyTrainingExpiresOn'] ?? null, 60))),
            'byCompany' => array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($byCompany), $byCompany),
        ];
    }

    public static function realHrData(string $siteId = 'ALL'): array
    {
        return [
            'success' => true,
            'stats' => self::realPersonnelStats($siteId),
            'list' => self::realPersonnel($siteId),
            'attendance' => self::realAttendanceLive($siteId),
        ];
    }

    public static function realGlobalAttendance(): array
    {
        $people = self::activeRealPersonnel('ALL');
        [$checkedIn, $notCheckedIn] = self::realAttendanceRows($people);
        $siteStats = [];

        foreach (self::realSites() as $id => $name) {
            $sitePeople = self::activeRealPersonnel($id);
            if (count($sitePeople) === 0) {
                continue;
            }

            $present = count(array_filter($checkedIn, fn ($p) => $p['site'] === $id));
            $siteStats[$id] = ['siteName' => $name, 'totalActive' => count($sitePeople), 'presentCount' => $present];
        }

        return [
            'success' => true,
            'mode' => 'global',
            'date' => Carbon::now()->toDateString(),
            'checkedIn' => $checkedIn,
            'notCheckedIn' => $notCheckedIn,
            'siteStats' => $siteStats,
            'totalPresent' => count($checkedIn),
            'totalWorkers' => count($people),
            'absentCount' => count($notCheckedIn),
            'activeSiteCount' => count($siteStats),
        ];
    }

    public static function realAttendanceLive(string $siteId): array
    {
        $people = self::activeRealPersonnel($siteId);
        [$checkedIn, $notCheckedIn] = self::realAttendanceRows($people);
        $summary = [];

        foreach ($checkedIn as $row) {
            $team = $row['team'] ?: 'Unassigned';
            $summary[$team] = ($summary[$team] ?? 0) + 1;
        }

        return [
            'success' => true,
            'checkedIn' => $checkedIn,
            'notCheckedIn' => $notCheckedIn,
            'teamSummary' => array_map(fn ($team, $count) => ['team' => $team, 'count' => $count], array_keys($summary), $summary),
            'totalActive' => count($people),
            'presentCount' => count($checkedIn),
            'absentCount' => count($notCheckedIn),
            'date' => Carbon::now()->toDateString(),
        ];
    }

    public static function realDailyTeamMatrix(string $siteId): array
    {
        $people = self::activeRealPersonnel($siteId);
        $teams = array_values(array_unique(array_map(fn ($p) => $p['team'] ?: 'Unassigned', $people)));

        return [
            'success' => true,
            'date' => Carbon::now()->toDateString(),
            'teams' => $teams,
            'matrix' => [],
            'foremen' => [],
            'subtotals' => [],
            'totals' => ['total' => count($people)],
        ];
    }

    public static function realDailyAttendanceDetail(string $siteId, mixed $date): array
    {
        $people = self::activeRealPersonnel($siteId);
        $targetDate = $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();
        $attendance = self::realAttendanceMap($people, $targetDate);
        $companies = [];

        foreach ($people as $person) {
            $company = $person['company'] ?: 'Unassigned';
            $team = $person['team'] ?: 'Unassigned';
            $state = $attendance[$person['employeeDbId']] ?? ['isPresent' => false, 'checkIn' => null, 'checkOut' => null];

            $companies[$company]['name'] = $company;
            $companies[$company]['teams'][$team][] = array_merge($person, [
                'isOpen' => $state['isPresent'],
                'todayIn' => $state['checkIn'],
                'todayOut' => $state['checkOut'],
            ]);
        }

        $companyRows = [];
        foreach ($companies as $company) {
            $teamRows = [];
            $total = 0;
            foreach ($company['teams'] as $team => $members) {
                $count = count($members);
                $total += $count;
                $teamRows[] = ['team' => $team, 'members' => array_values($members), 'count' => $count];
            }

            $companyRows[] = [
                'name' => $company['name'],
                'total' => $total,
                'divide' => ['manager' => 0, 'korean' => 0, 'local' => $total],
                'teams' => $teamRows,
            ];
        }

        return [
            'success' => true,
            'date' => $targetDate,
            'availableDates' => [$targetDate],
            'companies' => $companyRows,
            'teamStats' => collect($companyRows)->flatMap(fn ($company) => $company['teams'])->map(fn ($team) => ['team' => $team['team'], 'count' => $team['count']])->values()->all(),
            'totalAttended' => count($people),
        ];
    }

    public static function realEmployeeDetail(string $badgeId, string $siteId): array
    {
        $person = collect(self::realPersonnel('ALL'))->first(fn ($p) => ($p['badgeId'] ?? $p['id']) === $badgeId || ($p['id'] ?? '') === $badgeId);
        if (! $person) {
            return ['success' => false, 'employee' => null];
        }

        $state = self::realAttendanceMap([$person])[$person['employeeDbId']] ?? ['isPresent' => false, 'checkIn' => null, 'checkOut' => null];

        return [
            'success' => true,
            'employee' => array_merge($person, [
                'todayIn' => $state['checkIn'],
                'todayOut' => $state['checkOut'],
                'isOpen' => $state['isPresent'],
            ]),
        ];
    }

    public static function realPersonnelCard(string $uid): array
    {
        $person = collect(self::realPersonnel('ALL'))->first(fn ($p) => ($p['id'] ?? '') === $uid || ($p['badgeId'] ?? '') === $uid);
        if (! $person) {
            return ['success' => false, 'person' => null];
        }

        return [
            'success' => true,
            'person' => array_merge($person, [
                'passport' => null,
                'birthday' => null,
                'nationality' => $person['nationality'] ?? null,
            ]),
            'vehicle' => null,
            'housing' => null,
            'flights' => [],
        ];
    }

    private static function employeeRows(string $siteId = 'ALL')
    {
        try {
            if (! Schema::hasTable('employees')) {
                return collect();
            }

            return Employee::query()
                ->with(['company', 'site', 'team'])
                ->when($siteId !== 'ALL', fn ($query) => $query->whereHas('site', fn ($siteQuery) => $siteQuery->where('code', $siteId)))
                ->orderBy('name')
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    private static function formatEmployee(Employee $employee): array
    {
        $payload = is_array($employee->payload) ? $employee->payload : [];
        $siteCode = $employee->site?->code ?? ($payload['site'] ?? '');
        $companyName = $employee->company?->name ?? ($payload['company'] ?? '');
        $teamName = $employee->team?->name ?? ($payload['team'] ?? '');

        return [
            'employeeDbId' => $employee->id,
            'id' => $employee->employee_number,
            'badgeId' => $employee->badge_number ?: $employee->employee_number,
            'nameEn' => $employee->name,
            'nameKr' => $payload['nameKr'] ?? '',
            'company' => $companyName,
            'team' => $teamName,
            'role' => $employee->role,
            'site' => $siteCode,
            'visa' => $payload['visa'] ?? null,
            'visaExpiry' => $employee->visa_expires_on?->toDateString(),
            'safety' => self::expiryLabel($employee->safety_training_expires_on),
            'safetyTrainingExpiresOn' => $employee->safety_training_expires_on?->toDateString(),
            'workerStatus' => $employee->employment_status,
            'phone' => $payload['phone'] ?? null,
            'email' => $employee->email,
            'nationality' => $employee->nationality,
        ];
    }

    private static function activeRealPersonnel(string $siteId = 'ALL'): array
    {
        return array_values(array_filter(self::realPersonnel($siteId), fn ($person) => ($person['workerStatus'] ?? null) === 'active'));
    }

    private static function realAttendanceRows(array $people, ?string $date = null): array
    {
        $attendance = self::realAttendanceMap($people, $date);
        $checkedIn = [];
        $notCheckedIn = [];

        foreach ($people as $person) {
            $state = $attendance[$person['employeeDbId']] ?? ['isPresent' => false, 'checkIn' => null, 'checkOut' => null];
            $row = [
                'name' => $person['nameEn'],
                'company' => $person['company'],
                'team' => $person['team'],
                'site' => $person['site'],
                'checkIn' => $state['checkIn'],
                'checkOut' => $state['checkOut'],
                'nfcUid' => $person['badgeId'],
            ];

            if ($state['isPresent']) {
                $checkedIn[] = $row;
            } else {
                $notCheckedIn[] = $row;
            }
        }

        return [$checkedIn, $notCheckedIn];
    }

    private static function realAttendanceMap(array $people, ?string $date = null): array
    {
        $ids = array_values(array_filter(array_map(fn ($person) => $person['employeeDbId'] ?? null, $people)));
        if ($ids === []) {
            return [];
        }

        $targetDate = $date ?: Carbon::now()->toDateString();

        try {
            if (! Schema::hasTable('attendance_logs')) {
                return [];
            }

            $logs = AttendanceLog::query()
                ->whereIn('employee_id', $ids)
                ->whereDate('attendance_date', $targetDate)
                ->where('status', '!=', 'rejected')
                ->orderBy('event_at')
                ->get()
                ->groupBy('employee_id');
        } catch (\Throwable) {
            return [];
        }

        $states = [];
        foreach ($logs as $employeeId => $employeeLogs) {
            $checkIn = null;
            $checkOut = null;

            foreach ($employeeLogs as $log) {
                $type = strtolower((string) $log->event_type);
                if (str_contains($type, 'out')) {
                    $checkOut = $log->event_at;
                    continue;
                }

                if (str_contains($type, 'in') || str_contains($type, 'check')) {
                    $checkIn = $log->event_at;
                }
            }

            $states[$employeeId] = [
                'isPresent' => $checkIn !== null && ($checkOut === null || $checkOut->lessThan($checkIn)),
                'checkIn' => $checkIn?->format('H:i'),
                'checkOut' => $checkOut?->format('H:i'),
            ];
        }

        return $states;
    }

    private static function dateWithinDays(?string $date, int $days): bool
    {
        if (! $date) {
            return false;
        }

        $target = Carbon::parse($date);

        return $target->isFuture() && $target->lte(now()->addDays($days));
    }

    private static function expiryLabel(mixed $date): string
    {
        if (! $date) {
            return 'missing';
        }

        $target = Carbon::parse($date);
        if ($target->isPast()) {
            return 'expired';
        }

        return $target->lte(now()->addDays(60)) ? 'expiring_soon' : 'valid';
    }

    public static function hrDirectory(string $siteId = 'ALL'): array
    {
        try {
            if (! Schema::hasTable('employees')) {
                return ['success' => false, 'message' => 'Employees table not found.'];
            }

            $employees = Employee::query()
                ->with(['company', 'site', 'team'])
                ->where('employment_status', 'active')
                ->when($siteId !== 'ALL', fn ($query) => $query->whereHas('site', fn ($q) => $q->where('code', $siteId)))
                ->get();

            $grouped = [];
            foreach ($employees as $emp) {
                $siteCode = $emp->site?->code ?: 'Office';
                $siteName = $emp->site?->name ?: 'Office Location';
                $siteLabel = "{$siteCode} — {$siteName}";

                $payload = is_array($emp->payload) ? $emp->payload : [];
                $grouped[$siteLabel][] = [
                    'id' => $emp->id,
                    'employee_number' => $emp->employee_number,
                    'name' => $emp->name,
                    'preferred_name' => $payload['preferred_name'] ?? $emp->name,
                    'role' => $emp->role ?: 'Staff',
                    'department' => $emp->team?->name ?: 'Operation',
                    'email' => $emp->email ?: '-',
                    'direct_number' => $payload['direct_number'] ?? '-',
                    'phone' => $payload['phone'] ?? '-',
                    'manager' => $payload['manager'] ?? ($emp->team?->payload['manager'] ?? 'Unassigned'),
                    'type' => 'Regular Employee',
                ];
            }

            return [
                'success' => true,
                'sites' => $grouped,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function clockIn(mixed $employeeId = null): array
    {
        try {
            $employeeId = $employeeId ?: auth()->user()?->employee_id;
            if (! $employeeId) {
                return ['success' => false, 'message' => '현재 계정에 연결된 직원(Employee) 정보가 없습니다. 관리자 패널에서 연동을 확인하세요.'];
            }

            $employee = Employee::find($employeeId);
            if (! $employee) {
                return ['success' => false, 'message' => '해당 직원을 찾을 수 없습니다.'];
            }

            $today = Carbon::today()->toDateString();
            $lastLog = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->where('attendance_date', $today)
                ->orderBy('event_at', 'desc')
                ->first();

            if ($lastLog && $lastLog->event_type === 'clock_in') {
                return ['success' => false, 'message' => '이미 출근(Clock In) 처리가 완료되었습니다.'];
            }

            AttendanceLog::create([
                'employee_id' => $employeeId,
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'team_id' => $employee->team_id,
                'attendance_date' => $today,
                'event_type' => 'clock_in',
                'event_at' => Carbon::now(),
                'source' => 'web_portal',
                'status' => 'approved',
            ]);

            return ['success' => true, 'message' => '출근(Clock In)이 성공적으로 기록되었습니다.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function clockOut(mixed $employeeId = null): array
    {
        try {
            $employeeId = $employeeId ?: auth()->user()?->employee_id;
            if (! $employeeId) {
                return ['success' => false, 'message' => '현재 계정에 연결된 직원(Employee) 정보가 없습니다.'];
            }

            $employee = Employee::find($employeeId);
            if (! $employee) {
                return ['success' => false, 'message' => '해당 직원을 찾을 수 없습니다.'];
            }

            $today = Carbon::today()->toDateString();
            $lastLog = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->where('attendance_date', $today)
                ->orderBy('event_at', 'desc')
                ->first();

            if (! $lastLog || $lastLog->event_type !== 'clock_in') {
                return ['success' => false, 'message' => '출근(Clock In) 기록이 없습니다. 먼저 출근해 주세요.'];
            }

            AttendanceLog::create([
                'employee_id' => $employeeId,
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'team_id' => $employee->team_id,
                'attendance_date' => $today,
                'event_type' => 'clock_out',
                'event_at' => Carbon::now(),
                'source' => 'web_portal',
                'status' => 'approved',
            ]);

            return ['success' => true, 'message' => '퇴근(Clock Out)이 성공적으로 기록되었습니다.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function clockInWithGps(mixed $eventType = null, mixed $lat = null, mixed $lng = null, mixed $accuracy = null): array
    {
        try {
            $employeeId = auth()->user()?->employee_id;
            if (! $employeeId) {
                return ['success' => false, 'message' => '현재 계정에 연결된 직원(Employee) 정보가 없습니다.'];
            }

            $employee = Employee::find($employeeId);
            if (! $employee) {
                return ['success' => false, 'message' => '해당 직원을 찾을 수 없습니다.'];
            }

            if (! $employee->site_id) {
                return ['success' => false, 'message' => '배정된 현장 정보가 없습니다.'];
            }

            $site = Site::find($employee->site_id);
            if (! $site) {
                return ['success' => false, 'message' => '배정된 현장 모델을 찾을 수 없습니다.'];
            }

            // GPS 정보 획득
            $siteLat = null;
            $siteLng = null;
            $radius = 150; // 기본 반경 150미터

            if ($site->payload && is_array($site->payload)) {
                $siteLat = $site->payload['latitude'] ?? null;
                $siteLng = $site->payload['longitude'] ?? null;
                $radius = $site->payload['radius'] ?? 150;
            }

            // 현장별 대표 GPS Fallback (Hoffman, LGES-AZ, NV-05)
            if (is_null($siteLat) || is_null($siteLng)) {
                $code = strtoupper($site->code);
                if ($code === 'HFF-02') {
                    $siteLat = 33.4255;
                    $siteLng = -111.9400;
                } elseif ($code === 'LGES-AZ') {
                    $siteLat = 32.8410;
                    $siteLng = -111.7580;
                } elseif ($code === 'NV-05') {
                    $siteLat = 39.5296;
                    $siteLng = -119.8138;
                }
            }

            // 만약 지오펜싱 위치가 설정되어 있다면 거리 계산 수행
            $distance = null;
            if (!is_null($siteLat) && !is_null($siteLng) && !is_null($lat) && !is_null($lng)) {
                $earthRadius = 6371000; // meters
                $latFrom = deg2rad($lat);
                $lonFrom = deg2rad($lng);
                $latTo = deg2rad($siteLat);
                $lonTo = deg2rad($siteLng);

                $latDelta = $latTo - $latFrom;
                $lonDelta = $lonTo - $lonFrom;

                $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                    cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
                $distance = $angle * $earthRadius;

                if ($distance > $radius) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            '현장 반경을 벗어났습니다. (현재 거리: %.1fm, 허용 반경: %dm)',
                            $distance,
                            $radius
                        )
                    ];
                }
            } else {
                // GPS 좌표가 넘어오지 않았거나 현장 GPS가 없는 경우
                if (is_null($lat) || is_null($lng)) {
                    return ['success' => false, 'message' => '기기의 GPS 정보가 전송되지 않았습니다.'];
                }
                // 현장 GPS 정보 자체가 셋팅 안되어 있다면 일단 허용
            }

            $today = Carbon::today()->toDateString();
            $eventTime = Carbon::now();

            // 5분 중복 태그 방지
            $fiveMinutesAgo = (clone $eventTime)->subMinutes(5);
            $recentLog = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->where('event_at', '>=', $fiveMinutesAgo)
                ->where('event_at', '<=', $eventTime)
                ->orderBy('event_at', 'desc')
                ->first();

            if ($recentLog) {
                return [
                    'success' => false,
                    'message' => "태깅이 너무 빠릅니다. 잠시 후 다시 시도해 주세요. (최근 태깅: " . $recentLog->event_at->toTimeString() . ")"
                ];
            }

            // 이벤트 타입 결정
            $resolvedType = strtolower($eventType ?: '');
            if ($resolvedType !== 'clock_in' && $resolvedType !== 'clock_out') {
                // 수동 토글 처리
                $lastLog = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('status', '!=', 'rejected')
                    ->orderBy('event_at', 'desc')
                    ->first();
                $resolvedType = ($lastLog && $lastLog->event_type === 'clock_in') ? 'clock_out' : 'clock_in';
            }

            // 이전 상태 검증
            if ($resolvedType === 'clock_in') {
                $lastTodayIn = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_in')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if ($lastTodayIn) {
                    return ['success' => false, 'message' => '이미 오늘 출근 처리가 되어 있습니다.'];
                }
            } else {
                // clock_out
                $lastTodayIn = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_in')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if (! $lastTodayIn) {
                    return ['success' => false, 'message' => '오늘 출근 기록이 존재하지 않습니다.'];
                }
                $lastTodayOut = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_out')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if ($lastTodayOut) {
                    return ['success' => false, 'message' => '이미 오늘 퇴근 처리가 되어 있습니다.'];
                }
            }

            // AttendanceLog 생성
            AttendanceLog::create([
                'employee_id' => $employeeId,
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'team_id' => $employee->team_id,
                'attendance_date' => $today,
                'event_type' => $resolvedType,
                'event_at' => $eventTime,
                'source' => 'mobile_gps',
                'status' => 'approved',
                'notes' => '모바일 GPS 기반 셀프 등록 완료.',
                'payload' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'accuracy' => $accuracy,
                    'distance_meters' => $distance,
                    'geofence_lat' => $siteLat,
                    'geofence_lng' => $siteLng,
                    'geofence_radius' => $radius
                ]
            ]);

            $eventTypeName = $resolvedType === 'clock_in' ? '출근 (Clock In)' : '퇴근 (Clock Out)';

            return [
                'success' => true,
                'message' => "{$eventTypeName}이 성공적으로 등록되었습니다."
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function clockInWithTeamQr(?string $teamCode, ?string $eventType = null): array
    {
        try {
            if ($teamCode) {
                $team = \App\Models\Team::query()
                    ->with(['site', 'company'])
                    ->whereRaw('lower(code) = ?', [strtolower(trim($teamCode))])
                    ->first();

                if (! $team) {
                    return ['success' => false, 'message' => "Team QR code {$teamCode} was not found."];
                }

                $qrCode = \App\Models\AttendanceQrCode::forTeam($team, auth()->id());
                $result = app(AttendanceQrService::class)->recordSelfScan(auth()->user(), $qrCode, $eventType ?: 'auto');

                return [
                    'success' => true,
                    'ignored' => $result['ignored'] ?? false,
                    'event_type' => $result['event_type'] ?? null,
                    'status' => $result['status'] ?? null,
                    'message' => $result['message'] ?? 'QR attendance recorded.',
                ];
            }

            if (!$teamCode) {
                return ['success' => false, 'message' => '스캔한 팀 코드 정보가 비어있습니다.'];
            }

            $employeeId = auth()->user()?->employee_id;
            if (! $employeeId) {
                return ['success' => false, 'message' => '현재 계정에 연결된 직원(Employee) 정보가 없습니다.'];
            }

            $employee = Employee::query()->with('team')->find($employeeId);
            if (! $employee) {
                return ['success' => false, 'message' => '해당 직원을 찾을 수 없습니다.'];
            }

            if (! $employee->team) {
                return ['success' => false, 'message' => '소속 팀이 배정되어 있지 않습니다. 관리자에게 문의하세요.'];
            }

            $myTeamCode = $employee->team->code;
            if (strcasecmp(trim($myTeamCode ?? ''), trim($teamCode)) !== 0) {
                return [
                    'success' => false,
                    'message' => "소속 팀의 QR 코드가 아닙니다. (본인 팀: {$employee->team->name})"
                ];
            }

            $today = Carbon::today()->toDateString();
            $eventTime = Carbon::now();

            // 5분 중복 태그 방지
            $fiveMinutesAgo = (clone $eventTime)->subMinutes(5);
            $recentLog = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->where('event_at', '>=', $fiveMinutesAgo)
                ->where('event_at', '<=', $eventTime)
                ->orderBy('event_at', 'desc')
                ->first();

            if ($recentLog) {
                return [
                    'success' => false,
                    'message' => "태깅이 너무 빠릅니다. 잠시 후 다시 시도해 주세요. (최근 태깅: " . $recentLog->event_at->toTimeString() . ")"
                ];
            }

            // 이벤트 타입 결정
            $resolvedType = strtolower($eventType ?: '');
            if ($resolvedType !== 'clock_in' && $resolvedType !== 'clock_out') {
                $lastLog = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('status', '!=', 'rejected')
                    ->orderBy('event_at', 'desc')
                    ->first();
                $resolvedType = ($lastLog && $lastLog->event_type === 'clock_in') ? 'clock_out' : 'clock_in';
            }

            // 이전 상태 검증
            if ($resolvedType === 'clock_in') {
                $lastTodayIn = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_in')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if ($lastTodayIn) {
                    return ['success' => false, 'message' => '이미 오늘 출근 처리가 되어 있습니다.'];
                }
            } else {
                $lastTodayIn = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_in')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if (! $lastTodayIn) {
                    return ['success' => false, 'message' => '오늘 출근 기록이 존재하지 않습니다.'];
                }
                $lastTodayOut = AttendanceLog::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_date', $today)
                    ->where('event_type', 'clock_out')
                    ->where('status', '!=', 'rejected')
                    ->first();
                if ($lastTodayOut) {
                    return ['success' => false, 'message' => '이미 오늘 퇴근 처리가 되어 있습니다.'];
                }
            }

            // AttendanceLog 생성
            AttendanceLog::create([
                'employee_id' => $employeeId,
                'company_id' => $employee->company_id,
                'site_id' => $employee->site_id,
                'team_id' => $employee->team_id,
                'attendance_date' => $today,
                'event_type' => $resolvedType,
                'event_at' => $eventTime,
                'source' => 'team_qr',
                'status' => 'approved',
                'notes' => '팀 QR 코드 스캔을 통해 자동 기록됨.',
                'payload' => [
                    'scanned_team_code' => $teamCode,
                    'employee_team_code' => $myTeamCode,
                    'team_name' => $employee->team->name
                ]
            ]);

            $eventTypeName = $resolvedType === 'clock_in' ? '출근 (Clock In)' : '퇴근 (Clock Out)';

            return [
                'success' => true,
                'message' => "{$eventTypeName}이 성공적으로 등록되었습니다."
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function hrAttendanceRecords(mixed $employeeId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $employeeId = $employeeId ?: auth()->user()?->employee_id;
            if (! $employeeId) {
                return ['success' => false, 'message' => '직원 정보가 없습니다.', 'records' => []];
            }

            $start = $startDate ?: Carbon::now()->startOfMonth()->toDateString();
            $end = $endDate ?: Carbon::now()->toDateString();

            if (! Schema::hasTable('attendance_logs')) {
                return ['success' => true, 'records' => []];
            }

            $logs = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->whereBetween('attendance_date', [$start, $end])
                ->orderBy('attendance_date', 'desc')
                ->orderBy('event_at', 'asc')
                ->get()
                ->groupBy('attendance_date');

            $records = [];
            foreach ($logs as $dateStr => $dailyLogs) {
                $checkIn = null;
                $checkOut = null;

                foreach ($dailyLogs as $log) {
                    $type = strtolower((string) $log->event_type);
                    if (str_contains($type, 'out')) {
                        $checkOut = Carbon::parse($log->event_at);
                    } elseif (str_contains($type, 'in') || str_contains($type, 'check')) {
                        if (! $checkIn) {
                            $checkIn = Carbon::parse($log->event_at);
                        }
                    }
                }

                $workHours = 0.0;
                if ($checkIn && $checkOut) {
                    $minutes = $checkIn->diffInMinutes($checkOut);
                    $workHours = round($minutes / 60, 2);
                    // 점심시간 1시간 공제 (4시간 이상 근무 시)
                    if ($workHours > 4.0) {
                        $workHours -= 1.0;
                    }
                }

                $carbonDate = Carbon::parse($dateStr);
                $dayOfWeek = $carbonDate->format('N');
                $isWeekend = $dayOfWeek >= 6;
                $status = $isWeekend ? 'Weekend Work' : 'Regular Work';

                $records[] = [
                    'date' => $carbonDate->format('M d, Y'),
                    'raw_date' => $dateStr,
                    'clock_in' => $checkIn ? $checkIn->format('H:i:s') : '-',
                    'clock_out' => $checkOut ? $checkOut->format('H:i:s') : '-',
                    'work_hours' => $workHours > 0 ? "{$workHours} hrs" : '-',
                    'status' => $status,
                    'notes' => $dailyLogs->first()?->notes ?: '',
                ];
            }

            return [
                'success' => true,
                'records' => $records,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'records' => []];
        }
    }

    public static function hrAttendanceSummary(mixed $employeeId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $employeeId = $employeeId ?: auth()->user()?->employee_id;
            if (! $employeeId) {
                return [
                    'success' => false,
                    'message' => '직원 정보가 없습니다.',
                    'kpis' => ['work_hours' => 0, 'lates' => 0, 'early_outs' => 0, 'absences' => 0],
                    'records' => [],
                ];
            }

            $start = $startDate ?: Carbon::now()->startOfMonth()->toDateString();
            $end = $endDate ?: Carbon::now()->toDateString();

            $recordsResult = self::hrAttendanceRecords($employeeId, $start, $end);
            $records = $recordsResult['records'] ?? [];

            // Calculate KPIs
            $totalHours = 0.0;
            $lates = 0;
            $earlyOuts = 0;
            $presentDates = [];

            foreach ($records as $rec) {
                $hoursStr = str_replace(' hrs', '', $rec['work_hours']);
                if ($hoursStr !== '-') {
                    $totalHours += (float) $hoursStr;
                }

                if ($rec['clock_in'] !== '-') {
                    $checkInTime = Carbon::parse($rec['clock_in']);
                    // 지각 기준: 07:05 이후 출근 시 지각 처리
                    if ($checkInTime->format('H:i:s') > '07:05:00') {
                        $lates++;
                    }
                    $presentDates[$rec['raw_date']] = true;
                }

                if ($rec['clock_out'] !== '-') {
                    $checkOutTime = Carbon::parse($rec['clock_out']);
                    // 조기 퇴근 기준: 16:00 이전 퇴근 시 조기 퇴근 처리
                    if ($checkOutTime->format('H:i:s') < '16:00:00') {
                        $earlyOuts++;
                    }
                }
            }

            // Calculate absences for weekdays in range
            $absences = 0;
            $period = new \DatePeriod(
                new \DateTime($start),
                new \DateInterval('P1D'),
                (new \DateTime($end))->modify('+1 day')
            );
            foreach ($period as $date) {
                $dayOfWeek = (int) $date->format('N');
                if ($dayOfWeek <= 5) { // Mon - Fri
                    $dateStr = $date->format('Y-m-d');
                    if (! isset($presentDates[$dateStr])) {
                        $absences++;
                    }
                }
            }

            return [
                'success' => true,
                'kpis' => [
                    'work_hours' => round($totalHours, 1),
                    'lates' => $lates,
                    'early_outs' => $earlyOuts,
                    'absences' => $absences,
                ],
                'records' => $records,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'kpis' => ['work_hours' => 0, 'lates' => 0, 'early_outs' => 0, 'absences' => 0],
                'records' => [],
            ];
        }
    }

    public static function requestCorrection(mixed $employeeId, ?string $date, ?string $notes): array
    {
        try {
            $employeeId = $employeeId ?: auth()->user()?->employee_id;
            if (!$employeeId) {
                return ['success' => false, 'message' => '직원 정보가 없습니다.'];
            }
            if (!$date || !$notes) {
                return ['success' => false, 'message' => '날짜와 사유를 모두 입력하세요.'];
            }

            // Find or create a log for this employee on this date to attach the correction notes to
            $log = AttendanceLog::query()
                ->where('employee_id', $employeeId)
                ->where('attendance_date', $date)
                ->first();

            if (!$log) {
                // Create a stub log with type 'correction_requested' so they have a placeholder
                $employee = Employee::find($employeeId);
                $log = AttendanceLog::create([
                    'employee_id' => $employeeId,
                    'company_id' => $employee?->company_id,
                    'site_id' => $employee?->site_id,
                    'team_id' => $employee?->team_id,
                    'attendance_date' => $date,
                    'event_type' => 'correction_requested',
                    'event_at' => Carbon::parse($date)->startOfDay(),
                    'source' => 'web_portal',
                    'status' => 'pending',
                    'notes' => "[수정요청] " . $notes,
                ]);
            } else {
                $log->update([
                    'notes' => "[수정요청] " . $notes,
                    'status' => 'pending', // Mark as pending review
                ]);
            }

            return ['success' => true, 'message' => '근태 수정 요청이 성공적으로 접수되었습니다.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function submitNfcTag(?string $badgeNumber, ?string $siteCode, ?string $timestamp = null): array
    {
        try {
            if (!$badgeNumber) {
                return ['success' => false, 'message' => 'Badge/NFC number is required.'];
            }

            // Attendance is only valid after HR activates the employee.
            $employee = Employee::query()
                ->where('employment_status', 'active')
                ->where(fn ($query) => $query
                    ->where('badge_number', $badgeNumber)
                    ->orWhere('employee_number', $badgeNumber))
                ->first();

            if (!$employee) {
                return ['success' => false, 'message' => "Active employee with badge/NFC number {$badgeNumber} not found."];
            }

            $site = null;
            if ($siteCode) {
                $site = Site::where('code', $siteCode)->first();
            }
            // Use employee's site if siteCode is not provided or not found
            $siteId = $site ? $site->id : $employee->site_id;

            $eventTime = $timestamp ? Carbon::parse($timestamp) : Carbon::now();
            $today = $eventTime->toDateString();

            // Double tag prevention: check if there's a tag from the same employee within the last 5 minutes
            $fiveMinutesAgo = (clone $eventTime)->subMinutes(5);
            $recentLog = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('event_at', '>=', $fiveMinutesAgo)
                ->where('event_at', '<=', $eventTime)
                ->orderBy('event_at', 'desc')
                ->first();

            if ($recentLog) {
                return [
                    'success' => true,
                    'ignored' => true,
                    'message' => "태깅이 너무 빠릅니다. 중복 태깅 방지를 위해 무시되었습니다. (최근 태깅: {$recentLog->event_at->toTimeString()})",
                    'employee_name' => $employee->name,
                ];
            }

            // Determine event_type (Clock In vs Clock Out)
            $lastLog = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_date', $today)
                ->where('status', '!=', 'rejected')
                ->orderBy('event_at', 'desc')
                ->first();

            $eventType = 'clock_in';
            if ($lastLog && $lastLog->event_type === 'clock_in') {
                $eventType = 'clock_out';
            }

            $log = AttendanceLog::create([
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'site_id' => $siteId,
                'team_id' => $employee->team_id,
                'attendance_date' => $today,
                'event_type' => $eventType,
                'event_at' => $eventTime,
                'source' => 'nfc_reader',
                'status' => 'approved', // Auto-approved
                'notes' => 'NFC 태그 리더기를 통해 자동 기록됨.',
            ]);

            app(AttendanceQrService::class)->syncTimesheetFor($employee, $today);

            $eventTypeName = $eventType === 'clock_in' ? '출근 (Clock In)' : '퇴근 (Clock Out)';

            return [
                'success' => true,
                'ignored' => false,
                'event_type' => $eventType,
                'employee_name' => $employee->name,
                'message' => "{$employee->name}님의 {$eventTypeName}이 성공적으로 기록되었습니다.",
                'timestamp' => $eventTime->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function submitBatchPhotoScan(?array $scannedBadges, ?string $siteCode, ?string $eventType = 'clock_in', ?string $photoUrl = null, ?string $timestamp = null): array
    {
        try {
            if (empty($scannedBadges)) {
                return ['success' => false, 'message' => 'Scanned badges list is empty.'];
            }

            $site = null;
            if ($siteCode) {
                $site = Site::where('code', $siteCode)->first();
            }

            $eventTime = $timestamp ? Carbon::parse($timestamp) : Carbon::now();
            $today = $eventTime->toDateString();
            $eventType = strtolower($eventType ?: 'clock_in');

            $successCount = 0;
            $failedBadges = [];

            foreach ($scannedBadges as $badgeNumber) {
                $employee = Employee::query()
                    ->where('employment_status', 'active')
                    ->where(fn ($query) => $query
                        ->where('badge_number', $badgeNumber)
                        ->orWhere('employee_number', $badgeNumber))
                    ->first();

                if (!$employee) {
                    $failedBadges[] = $badgeNumber;
                    continue;
                }

                $siteId = $site ? $site->id : $employee->site_id;

                // Check for duplicate in the last 15 minutes to prevent registering same scan multiple times
                $fifteenMinutesAgo = (clone $eventTime)->subMinutes(15);
                $duplicate = AttendanceLog::query()
                    ->where('employee_id', $employee->id)
                    ->where('event_type', $eventType)
                    ->where('attendance_date', $today)
                    ->where('event_at', '>=', $fifteenMinutesAgo)
                    ->first();

                if ($duplicate) {
                    continue; // Skip duplicates
                }

                AttendanceLog::create([
                    'employee_id' => $employee->id,
                    'company_id' => $employee->company_id,
                    'site_id' => $siteId,
                    'team_id' => $employee->team_id,
                    'attendance_date' => $today,
                    'event_type' => $eventType,
                    'event_at' => $eventTime,
                    'source' => 'batch_photo_scan',
                    'status' => 'pending', // Pending approval by manager
                    'notes' => '팀장 사진 인식을 통한 자동 등록 (승인 대기 중).',
                    'payload' => [
                        'photo_url' => $photoUrl,
                        'original_badge_scanned' => $badgeNumber,
                    ],
                ]);

                $successCount++;
            }

            $msg = "총 {$successCount}명의 출퇴근 기록이 승인 대기 상태로 접수되었습니다.";
            if (!empty($failedBadges)) {
                $failedStr = implode(', ', $failedBadges);
                $msg .= " 단, 등록되지 않은 배지 번호({$failedStr})는 스킵되었습니다.";
            }

            return [
                'success' => true,
                'processed_count' => $successCount,
                'failed_badges' => $failedBadges,
                'message' => $msg,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function getPendingAttendanceLogs(string $siteId = 'ALL'): array
    {
        try {
            $logs = AttendanceLog::query()
                ->with(['employee.company', 'employee.site', 'employee.team'])
                ->where('status', 'pending')
                ->when($siteId !== 'ALL', function ($query) use ($siteId) {
                    $query->whereHas('employee.site', function ($q) use ($siteId) {
                        $q->where('code', $siteId);
                    });
                })
                ->orderBy('event_at', 'desc')
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'employee_id' => $log->employee_id,
                        'name' => $log->employee?->name ?: 'Unknown',
                        'company' => $log->employee?->company?->name ?: 'None',
                        'site' => $log->employee?->site?->name ?: 'None',
                        'team' => $log->employee?->team?->name ?: 'None',
                        'attendance_date' => $log->attendance_date?->toDateString(),
                        'event_type' => $log->event_type,
                        'event_at' => $log->event_at?->toIso8601String(),
                        'source' => $log->source,
                        'notes' => $log->notes,
                        'payload' => $log->payload,
                    ];
                });

            return [
                'success' => true,
                'logs' => $logs,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function approveAttendanceLog(mixed $logId): array
    {
        try {
            $log = AttendanceLog::find($logId);
            if (!$log) {
                return ['success' => false, 'message' => 'Attendance log not found.'];
            }

            $user = auth()->user();
            app(AttendanceQrService::class)->approveLog($log, $user);

            return ['success' => true, 'message' => 'Attendance log approved successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function rejectAttendanceLog(mixed $logId): array
    {
        try {
            $log = AttendanceLog::find($logId);
            if (!$log) {
                return ['success' => false, 'message' => 'Attendance log not found.'];
            }

            app(AttendanceQrService::class)->rejectLog($log);

            return ['success' => true, 'message' => 'Attendance log rejected successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function universalAIScan(array $args): array
    {
        $category = (string) ($args[0] ?? 'UNKNOWN');
        $base64Data = (string) ($args[1] ?? '');
        $mimeType = (string) ($args[2] ?? 'image/jpeg');

        if ($category !== 'EXPENSE' && $category !== 'OFFICE') {
            return ['success' => true, 'category' => $category, 'mock' => true];
        }

        try {
            if ($base64Data === '') {
                throw new \RuntimeException('No image data received.');
            }

            if (str_contains($base64Data, ',')) {
                $base64Data = explode(',', $base64Data)[1];
            }

            $imageBytes = base64_decode($base64Data);
            if ($imageBytes === false) {
                throw new \RuntimeException('Invalid base64 data.');
            }

            // Save to temporary file for analysis
            $tempPath = tempnam(sys_get_temp_dir(), 'universal-scan-');
            file_put_contents($tempPath, $imageBytes);

            // Run analysis
            $analyzer = app(\App\Services\GeminiReceiptAnalyzer::class);
            $result = $analyzer->analyze($tempPath, $mimeType);
            @unlink($tempPath);

            // Save to public storage/receipts
            $filename = 'receipts/' . md5(uniqid('', true)) . '.' . ($mimeType === 'image/png' ? 'png' : 'jpg');
            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $imageBytes);
            $receiptPath = '/storage/' . $filename;

            // Fetch authenticated user
            $user = auth()->user();
            if (!$user) {
                throw new \RuntimeException('Unauthenticated.');
            }

            $employee = $user->employee;
            $companyId = $employee?->company_id ?? $user->allowed_company_id;
            $siteId = $employee?->site_id ?? $user->allowed_site_id;
            $employeeId = $user->employee_id;
            $handwrittenNotes = trim((string) ($result['handwritten_notes'] ?? ''));
            $description = '[Desktop AI Scan] ' . ($result['vendor_name'] ?? 'Receipt') . (! empty($result['description']) ? ' - ' . $result['description'] : '');
            if ($handwrittenNotes !== '') {
                $description .= "\nHandwritten note: " . $handwrittenNotes;
            }
            $accountingAccount = FinanceChartOfAccounts::normalize(
                $result['accounting_account'] ?? $result['category'] ?? '',
                $description
            );

            // Save to mobile_expenses table
            \App\Models\MobileExpense::create(self::mobileExpensePayload([
                'company_id' => $companyId,
                'site_id' => $siteId,
                'employee_id' => $employeeId,
                'payment_type' => 'personal',
                'category' => $accountingAccount,
                'accounting_account' => $accountingAccount,
                'class' => null,
                'description' => $description,
                'amount' => $result['amount'] ?? 0.0,
                'expense_date' => $result['date'] ?: now()->format('Y-m-d'),
                'receipt_path' => $receiptPath,
                'receipt_mime_type' => $mimeType,
                'receipt_original_name' => basename($filename),
                'receipt_file' => ReceiptFilePayload::encode($imageBytes),
                'ocr_data' => $result,
                'status' => 'pending',
            ]));

            return [
                'success' => true,
                'category' => $category,
                'data' => $result,
                'receipt_path' => $receiptPath,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Universal AI Scan failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function defaultResponse(string $method): mixed
    {
        if (str_starts_with($method, 'api_get')) {
            return [];
        }
        return ['success' => true, 'method' => $method, 'message' => 'Endpoint stub is ready for implementation.'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function mobileExpensePayload(array $attributes): array
    {
        if (! class_exists(Schema::class) || ! Schema::hasTable('mobile_expenses')) {
            return $attributes;
        }

        $columns = array_flip(Schema::getColumnListing('mobile_expenses'));

        return array_intersect_key($attributes, $columns);
    }
}
