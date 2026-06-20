<?php

namespace App\Support;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\SmartRecord;
use App\Models\Site;
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

            'api_getFinanceStats' => self::financeStats(),
            'api_getExpenses' => self::expenses(),
            'api_getPayrollDashboard' => self::payrollDashboard($args[0] ?? null),

            'api_getEquipmentStats' => self::equipmentStats(),
            'api_getEquipmentList' => self::equipmentList(),
            'api_getToolStats' => self::toolStats(),
            'api_getToolList' => self::toolList(),
            'api_getToolTransactions' => self::toolTransactions(),
            'api_getInventoryDashboard' => self::inventoryDashboard(),
            'api_getInventoryAssetDetail' => self::inventoryAssetDetail((string) ($args[0] ?? '')),
            'api_processInventoryPhotos', 'setupInventorySheets', 'setupInventoryFolders' => ['success' => true, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'results' => []],

            'api_getAlerts' => self::alerts($args[0] ?? 'all'),
            'api_updateAlertStatus' => ['success' => true],
            'api_getSafetyStats' => self::safetyStats(),
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
            'api_getProjectWbsTree' => self::wbsTree((string) ($args[0] ?? 'HFF-02')),
            'api_getProjectProgressSummary' => self::projectProgressSummary((string) ($args[0] ?? 'HFF-02')),
            'api_updateWbsRow', 'api_markWbsStatus', 'api_processWbsManual' => ['success' => true],

            'api_getVehicleList' => self::vehicleList(),
            'api_getVehicleStats' => self::vehicleStats(),
            'api_getRentalList' => self::rentalList(),
            'api_getRentalStats' => self::rentalStats(),
            'api_createRental', 'api_returnRental', 'api_processRentalContracts', 'api_processEquipmentRentalContracts', 'setupRentalSheet', 'generateSampleRentalContracts', 'api_cleanEmptyRentalRows' => ['success' => true, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'results' => []],
            'api_getHousingList' => self::housingList(),
            'api_getHousingStats' => self::housingStats(),
            'api_getFlightList' => self::flightList(),
            'api_getOfficeSupplies' => self::officeSupplies(),
            'api_getVendorList' => self::vendors(),
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
            'api_universalAIScan' => ['success' => true, 'category' => ($args[0] ?? 'UNKNOWN')],
            'api_nfcAssignVehicle', 'api_nfcAssignHousing' => ['success' => true, 'message' => 'NFC assignment saved'],

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
        foreach (self::expenses() as $expense) {
            $records[] = self::record('finance', $expense['id'], $expense['vendor'], $expense['category'], $expense['site'] ?? 'HFF-02', $expense['status'], $expense['amount'], $expense);
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
            'occurred_on' => Carbon::now()->toDateString(),
            'payload' => $payload,
        ];
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

    public static function financeStats(): array
    {
        return ['mtdTotal' => 83420, 'mtdBudget' => 125000, 'pendingApproval' => 4, 'pendingAmount' => 12600, 'claimable' => 18400, 'byCategory' => [['name' => 'Rental', 'amount' => 31200, 'color' => '#2563eb'], ['name' => 'Materials', 'amount' => 42800, 'color' => '#10b981'], ['name' => 'Travel', 'amount' => 9420, 'color' => '#f59e0b']]];
    }

    public static function expenses(): array
    {
        $fromDb = self::smartRecords('finance');
        return $fromDb ?: [
            ['id' => 'EXP-2401', 'vendor' => 'United Rentals', 'category' => 'Rental', 'site' => 'HFF-02', 'amount' => 4200, 'status' => '승인대기', 'date' => '2026-06-18'],
            ['id' => 'EXP-2402', 'vendor' => 'Graybar', 'category' => 'Materials', 'site' => 'LGES-AZ', 'amount' => 8750, 'status' => '청구완료', 'date' => '2026-06-17'],
            ['id' => 'EXP-2403', 'vendor' => 'Delta', 'category' => 'Travel', 'site' => 'NV-05', 'amount' => 1350, 'status' => '처리중', 'date' => '2026-06-16'],
        ];
    }

    public static function equipmentStats(): array { return ['total' => count(self::equipmentList()), 'operable' => 4, 'inoperable' => 1, 'todayInspections' => 4]; }
    public static function equipmentList(): array
    {
        $fromDb = self::smartRecords('equipment');
        return $fromDb ?: [
            ['id' => 'EQ-001', 'name' => 'Genie S-65 Boom Lift', 'type' => 'Lift', 'site' => 'HFF-02', 'inspector' => 'Carlos', 'lastCheck' => '2026-06-19', 'checkStatus' => '완료', 'status' => '운행가능'],
            ['id' => 'EQ-002', 'name' => 'JLG 1930ES Scissor Lift', 'type' => 'Lift', 'site' => 'LGES-AZ', 'inspector' => 'Min Lee', 'lastCheck' => '2026-06-19', 'checkStatus' => '완료', 'status' => '점검중'],
            ['id' => 'EQ-003', 'name' => 'Ford F-250 Service Truck', 'type' => 'Vehicle', 'site' => 'NV-05', 'inspector' => 'Daniel', 'lastCheck' => '2026-06-18', 'checkStatus' => '주의', 'status' => '수리필요'],
            ['id' => 'EQ-004', 'name' => 'Greenlee Bender', 'type' => 'Tooling', 'site' => 'HFF-02', 'inspector' => 'James', 'lastCheck' => '2026-06-19', 'checkStatus' => '완료', 'status' => '운행가능'],
            ['id' => 'EQ-005', 'name' => 'Hilti Core Drill', 'type' => 'Tooling', 'site' => 'LGES-AZ', 'inspector' => 'Sophia', 'lastCheck' => '2026-06-19', 'checkStatus' => '완료', 'status' => '운행가능'],
        ];
    }
    public static function toolStats(): array { return ['total' => 18, 'available' => 12, 'checkedOut' => 6, 'damaged' => 2]; }
    public static function toolList(): array { return [['id' => 'TL-101', 'name' => 'Cordless Hammer Drill', 'category' => 'Power Tool', 'status' => '불출중', 'holder' => 'EMP-1002', 'checkoutDate' => '2026-06-18', 'condition' => '정상'], ['id' => 'TL-102', 'name' => 'Torque Wrench', 'category' => 'Hand Tool', 'status' => '보관중', 'holder' => null, 'checkoutDate' => null, 'condition' => '정상'], ['id' => 'TL-103', 'name' => 'Laser Level', 'category' => 'Survey', 'status' => '수리필요', 'holder' => null, 'checkoutDate' => null, 'condition' => '손상']]; }
    public static function toolTransactions(): array { return [['time' => '08:12', 'action' => '불출', 'toolName' => 'Cordless Hammer Drill', 'toolId' => 'TL-101', 'userId' => 'EMP-1002', 'condition' => '정상'], ['time' => '11:40', 'action' => '반납', 'toolName' => 'Laser Level', 'toolId' => 'TL-103', 'userId' => 'EMP-1005', 'condition' => '손상']]; }

    public static function safetyStats(): array { return ['daysNoIncident' => 47, 'unresolved' => 3, 'resolved' => 18, 'urgent' => 1, 'warning' => 2, 'normal' => 8]; }
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

    public static function payrollDashboard(mixed $periodStart): array { return ['success' => true, 'period' => ['start' => $periodStart ?: '2026-06-16', 'end' => '2026-06-22'], 'totals' => ['hours' => 212, 'regular' => 184, 'ot' => 28, 'gross' => 12480], 'companies' => [['name' => 'NAHSHON MEP', 'gross' => 7400], ['name' => 'Local Union', 'gross' => 5080]], 'anomalies' => [['employee' => 'Min Lee', 'issue' => 'Missing checkout']], 'employees' => self::personnel('ALL')]; }
    public static function inventoryDashboard(): array { return ['success' => true, 'totals' => ['assets' => 42, 'available' => 31, 'checkedOut' => 8, 'repair' => 3], 'matrix' => ['categories' => ['Lift', 'Tooling', 'Vehicle'], 'sites' => array_keys(self::sites()), 'cells' => []], 'recent' => self::equipmentList(), 'upcomingInspections' => []]; }
    public static function inventoryAssetDetail(string $assetId): array { return ['success' => true, 'asset' => collect(self::equipmentList())->firstWhere('id', $assetId) ?? self::equipmentList()[0], 'photos' => [], 'transactions' => self::toolTransactions()]; }

    public static function vehicleStats(): array { return ['total' => 6, 'active' => 4, 'available' => 1, 'maintenance' => 1]; }
    public static function vehicleList(): array { return [['id' => 'VH-001', 'name' => 'Ford F-250', 'driver' => 'James Kim', 'site' => 'HFF-02', 'status' => '운행중'], ['id' => 'VH-002', 'name' => 'Toyota Sienna', 'driver' => 'Admin', 'site' => 'LGES-AZ', 'status' => '정비중']]; }
    public static function rentalStats(): array { return ['total' => 8, 'active' => 5, 'overdue' => 1, 'returned' => 2, 'returningSoon' => 2, 'mtdCost' => 28600]; }
    public static function rentalList(): array { return [['id' => 'RN-001', 'vendor' => 'United Rentals', 'item' => 'Scissor Lift', 'site' => 'HFF-02', 'startDate' => '2026-06-10', 'endDate' => '2026-06-24', 'cost' => 4200, 'status' => '반납예정'], ['id' => 'RN-002', 'vendor' => 'Sunbelt', 'item' => 'Telehandler', 'site' => 'LGES-AZ', 'startDate' => '2026-06-01', 'endDate' => '2026-06-18', 'cost' => 7600, 'status' => '만료임박']]; }
    public static function housingStats(): array { return ['total' => 12, 'occupied' => 9, 'available' => 3, 'maintenance' => 1, 'occupancyRate' => 75]; }
    public static function housingList(): array { return [['id' => 'HS-101', 'name' => 'Mesa House A', 'beds' => 4, 'occupied' => 3, 'status' => '정상'], ['id' => 'HS-102', 'name' => 'Tempe Apt B', 'beds' => 3, 'occupied' => 3, 'status' => '수리필요']]; }
    public static function flightList(): array { return [['id' => 'FL-001', 'name' => 'Han Gildong', 'direction' => '입국', 'from' => 'ICN', 'to' => 'PHX', 'depDateTime' => '2026-06-28 10:30', 'airline' => 'Korean Air', 'pnr' => 'KXNV7T', 'price' => 1240, 'status' => '발권', 'needPickup' => true, 'pickupBy' => 'Lee', 'housingReady' => true]]; }
    public static function officeSupplies(): array { return [['id' => 'OF-001', 'category' => '소모품', 'name' => 'Copy Paper A4', 'qty' => 3, 'minQty' => 5, 'location' => 'Office cabinet', 'lastRestock' => '2026-06-01', 'unitPrice' => 45, 'reorder' => true], ['id' => 'OF-002', 'category' => 'Safety', 'name' => 'Safety Vest', 'qty' => 8, 'minQty' => 10, 'location' => 'Safety shelf', 'lastRestock' => '2026-05-24', 'unitPrice' => 35, 'reorder' => true]]; }
    public static function vendors(): array { $fromDb = self::smartRecords('vendors'); return $fromDb ?: [['id' => 'VEN-001', 'name' => 'Graybar', 'category' => 'Electrical Supply', 'manager' => 'Amy', 'phone' => '602-555-0111', 'email' => 'quotes@graybar.example', 'contractStatus' => '진행중', 'site' => 'ALL'], ['id' => 'VEN-002', 'name' => 'United Rentals', 'category' => 'Equipment Rental', 'manager' => 'Mark', 'phone' => '602-555-0122', 'email' => 'az@united.example', 'contractStatus' => '진행중', 'site' => 'ALL']]; }

    public static function wbsTree(string $projectId): array { return ['success' => true, 'projectId' => $projectId, 'stages' => [['name' => 'Rough-in', 'progress' => 72, 'items' => [['id' => 'WBS-001', 'name' => 'Conduit install Area A', 'status' => '완료', 'progress' => 100], ['id' => 'WBS-002', 'name' => 'Cable tray Area B', 'status' => '처리중', 'progress' => 55]]], ['name' => 'Trim-out', 'progress' => 18, 'items' => [['id' => 'WBS-003', 'name' => 'Panel labeling', 'status' => '미처리', 'progress' => 0]]]]]; }
    public static function projectProgressSummary(string $projectId): array { return ['success' => true, 'projectId' => $projectId, 'progress' => collect(self::projects())->firstWhere('code', $projectId)['progress'] ?? 0, 'risk' => 'medium']; }

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

    private static function defaultResponse(string $method): mixed
    {
        if (str_starts_with($method, 'api_get')) {
            return [];
        }
        return ['success' => true, 'method' => $method, 'message' => 'Endpoint stub is ready for implementation.'];
    }
}
