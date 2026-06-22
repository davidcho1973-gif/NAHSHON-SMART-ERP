<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
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
        Carbon::setTestNow(Carbon::now()->addMinutes(6));

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
}
