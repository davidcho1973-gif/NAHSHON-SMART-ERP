<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceQrCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBadgeQrToken;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HrAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Employee $employee1;
    private Employee $employee2;
    private Site $site;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC-01',
        ]);

        $this->site = Site::create([
            'name' => 'Arizona Site',
            'code' => 'AZ-01',
        ]);

        $this->employee1 = Employee::create([
            'name' => 'John Doe',
            'employee_number' => 'EMP-001',
            'badge_number' => 'NFC-1001',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $this->employee2 = Employee::create([
            'name' => 'Jane Smith',
            'employee_number' => 'EMP-002',
            'badge_number' => 'NFC-1002',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
        ]);

        $this->adminUser = User::factory()->create([
            'access_role' => 'admin',
            'employee_id' => $this->employee1->id,
        ]);
    }

    public function test_nfc_tagging_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 08:00:00'));

        // 1. Clock in John Doe
        $response = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_submitNfcTag', [
                'args' => ['NFC-1001', 'AZ-01'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('event_type', 'clock_in');
        $response->assertJsonPath('ignored', false);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee1->id,
            'event_type' => 'clock_in',
            'source' => 'nfc_reader',
            'status' => 'approved',
        ]);

        // 2. Immediate tag should be ignored (Debounce)
        $response2 = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_submitNfcTag', [
                'args' => ['NFC-1001', 'AZ-01'],
            ]);

        $response2->assertStatus(200);
        $response2->assertJsonPath('ignored', true);

        // 3. 6 minutes later tag should register as Clock Out
        Carbon::setTestNow(Carbon::parse('2026-06-22 08:06:00'));

        $response3 = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_submitNfcTag', [
                'args' => ['NFC-1001', 'AZ-01'],
            ]);

        $response3->assertStatus(200);
        $response3->assertJsonPath('event_type', 'clock_out');
        $response3->assertJsonPath('ignored', false);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee1->id,
            'event_type' => 'clock_out',
            'source' => 'nfc_reader',
            'status' => 'approved',
        ]);

        Carbon::setTestNow(); // Reset test time mock
    }

    public function test_nfc_tagging_requires_active_employee(): void
    {
        $pendingEmployee = Employee::create([
            'name' => 'Pending Worker',
            'employee_number' => 'EMP-PENDING',
            'badge_number' => 'NFC-PENDING',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'pending',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_submitNfcTag', [
                'args' => ['NFC-PENDING', 'AZ-01'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseMissing('attendance_logs', [
            'employee_id' => $pendingEmployee->id,
        ]);
    }

    public function test_batch_photo_scan_flow_and_approval(): void
    {
        // 1. Send batch scan for both employees
        $response = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_submitBatchPhotoScan', [
                'args' => [
                    ['NFC-1001', 'NFC-1002'], // badges
                    'AZ-01',                  // site
                    'clock_in',               // type
                    'https://example.com/badge.jpg' // photo url
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('processed_count', 2);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee1->id,
            'event_type' => 'clock_in',
            'source' => 'batch_photo_scan',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee2->id,
            'event_type' => 'clock_in',
            'source' => 'batch_photo_scan',
            'status' => 'pending',
        ]);

        // 2. Fetch pending reviews list
        $logsResponse = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_getPendingAttendanceLogs', [
                'siteId' => 'ALL',
            ]);

        $logsResponse->assertStatus(200);
        $logsResponse->assertJsonPath('success', true);
        $logs = $logsResponse->json('logs');
        $this->assertCount(2, $logs);

        $log1Id = $logs[0]['id'];
        $log2Id = $logs[1]['id'];

        // 3. Approve first log
        $approveResponse = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_approveAttendanceLog', [
                'args' => [$log1Id],
            ]);

        $approveResponse->assertStatus(200);
        $approveResponse->assertJsonPath('success', true);

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log1Id,
            'status' => 'approved',
        ]);

        // 4. Reject second log
        $rejectResponse = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_rejectAttendanceLog', [
                'args' => [$log2Id],
            ]);

        $rejectResponse->assertStatus(200);
        $rejectResponse->assertJsonPath('success', true);

        $this->assertDatabaseHas('attendance_logs', [
            'id' => $log2Id,
            'status' => 'rejected',
        ]);
    }

    public function test_clock_in_with_team_qr_flow(): void
    {
        // 1. Create a Team and assign it to the employee
        $team = Team::create([
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'code' => 'T-ELECT-A',
            'name' => 'Electrical Crew A',
            'status' => 'active',
        ]);

        $this->employee1->update([
            'team_id' => $team->id,
        ]);

        // 2. Perform successful clock in with matching team QR code
        $response = $this->actingAs($this->adminUser)
            ->postJson('/smart-company-api/api_clockInWithTeamQr', [
                'args' => ['T-ELECT-A', 'clock_in'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee1->id,
            'event_type' => 'clock_in',
            'source' => 'self_team_qr',
            'status' => 'approved',
            'team_id' => $team->id,
        ]);

        $this->assertDatabaseHas('daily_work_assignments', [
            'employee_id' => $this->employee1->id,
            'team_id' => $team->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('payroll_timesheets', [
            'employee_id' => $this->employee1->id,
            'team_id' => $team->id,
            'status' => 'open',
        ]);

        // 3. Perform failed clock in with another team's QR code
        $otherTeam = Team::create([
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'code' => 'T-PIPE-B',
            'name' => 'Pipe Crew B',
            'status' => 'active',
        ]);

        $user2 = User::factory()->create([
            'access_role' => 'worker',
            'employee_id' => $this->employee2->id,
        ]);

        $this->employee2->update([
            'team_id' => $otherTeam->id,
        ]);

        // Scan the wrong team's QR code (T-ELECT-A) for employee2
        $response2 = $this->actingAs($user2)
            ->postJson('/smart-company-api/api_clockInWithTeamQr', [
                'args' => ['T-ELECT-A', 'clock_in'],
            ]);

        $response2->assertStatus(200);
        $response2->assertJsonPath('success', true);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee2->id,
            'event_type' => 'clock_in',
            'source' => 'self_team_qr',
            'team_id' => $team->id,
        ]);
    }

    public function test_foreman_can_record_worker_attendance_with_badge_qr(): void
    {
        $team = Team::create([
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'code' => 'T-RIG-A',
            'name' => 'Rigging Crew A',
            'status' => 'active',
        ]);

        $qrCode = AttendanceQrCode::forTeam($team, $this->adminUser->id);
        $badgeToken = EmployeeBadgeQrToken::activeForEmployee($this->employee2, $this->adminUser->id);

        $foremanEmployee = Employee::create([
            'name' => 'Foreman User',
            'employee_number' => 'EMP-FOR',
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employment_status' => 'active',
            'attendance_app_role' => 'foreman',
            'attendance_app_scope' => 'site',
        ]);

        $foremanUser = User::factory()->create([
            'access_role' => 'worker',
            'employee_id' => $foremanEmployee->id,
        ]);

        $response = $this->actingAs($foremanUser)
            ->post(route('attendance-app.crew.record', ['token' => $qrCode->token]), [
                'badge_token' => route('attendance-app.badge', ['token' => $badgeToken->token]),
                'mode' => 'clock_in',
                'reason' => 'worker_no_phone',
            ]);

        $response->assertRedirect(route('attendance-app.crew', ['token' => $qrCode->token]));

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $this->employee2->id,
            'recorded_by_id' => $foremanUser->id,
            'event_type' => 'clock_in',
            'source' => 'foreman_badge_qr',
            'team_id' => $team->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('daily_work_assignments', [
            'employee_id' => $this->employee2->id,
            'team_id' => $team->id,
            'status' => 'approved',
        ]);
    }

    public function test_team_qr_printable_page(): void
    {
        // 1. Create a Team
        $team = Team::create([
            'site_id' => $this->site->id,
            'company_id' => $this->company->id,
            'code' => 'T-ELECT-A',
            'name' => 'Electrical Crew A',
            'status' => 'active',
        ]);

        // 2. Access without authentication -> should redirect to login
        $unauthenticatedResponse = $this->get(route('team.qr', ['team' => $team]));
        $unauthenticatedResponse->assertStatus(302);
        $unauthenticatedResponse->assertRedirect(route('login'));

        // 3. Access with authentication -> should render 200 with correct content
        $authenticatedResponse = $this->actingAs($this->adminUser)
            ->get(route('team.qr', ['team' => $team]));

        $authenticatedResponse->assertStatus(200);
        $authenticatedResponse->assertSee('T-ELECT-A');
        $authenticatedResponse->assertSee('Electrical Crew A');
        $authenticatedResponse->assertSee('/attendance-app/team/', false);
        $authenticatedResponse->assertSee('https://api.qrserver.com/v1/create-qr-code/');
    }
}
