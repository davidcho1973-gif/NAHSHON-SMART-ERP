<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Communication\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommunicationMessengerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Employee $employee;

    private User $workerUser;

    private CommunicationService $communicationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->communicationService = app(CommunicationService::class);

        $this->company = Company::query()->create([
            'name' => 'ABC ENG Test',
            'code' => 'NAH-TEST',
        ]);

        $this->site = Site::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Arizona Site',
            'code' => 'AZ-01',
        ]);

        $this->employee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'John Worker',
            'employee_number' => 'EMP-COMM-001',
            'employment_status' => 'active',
        ]);

        $this->workerUser = User::factory()->create([
            'employee_id' => $this->employee->id,
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);
    }

    public function test_worker_only_sees_own_site_rooms(): void
    {
        $otherSite = Site::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Texas Site',
            'code' => 'TX-01',
        ]);

        $this->communicationService->ensureSiteRooms($this->site);
        $this->communicationService->ensureSiteRooms($otherSite);

        $response = $this->actingAs($this->workerUser)
            ->get(route('communication.index'));

        $response->assertOk();
        $response->assertSee('AZ-01', false);
        $response->assertDontSee('TX-01', false);
    }

    public function test_worker_can_post_message_and_room_read_is_tracked(): void
    {
        $room = $this->communicationService->ensureSiteRooms($this->site)['chat'];

        $response = $this->actingAs($this->workerUser)
            ->post(route('communication.store', ['room' => $room]), [
                'body' => 'Morning crew update',
            ]);

        $response->assertRedirect(route('communication.show', ['room' => $room]));

        $this->assertDatabaseHas('communication_messages', [
            'communication_room_id' => $room->id,
            'sender_employee_id' => $this->employee->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => 'Morning crew update',
        ]);

        $message = CommunicationMessage::query()->where('body', 'Morning crew update')->firstOrFail();

        $this->assertDatabaseHas('communication_message_reads', [
            'communication_message_id' => $message->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_attendance_log_creates_site_chat_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 08:03:00'));

        $log = AttendanceLog::query()->create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-07-05',
            'event_type' => 'clock_in',
            'event_at' => Carbon::now(),
            'source' => 'web_portal',
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('communication_messages', [
            'kind' => CommunicationMessage::KIND_ATTENDANCE_ALERT,
            'related_type' => AttendanceLog::class,
            'related_id' => $log->id,
            'site_id' => $this->site->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_only_manager_can_create_top_level_announcement(): void
    {
        $announcementRoom = $this->communicationService->ensureSiteRooms($this->site)['announcements'];

        $this->actingAs($this->workerUser)
            ->post(route('communication.store', ['room' => $announcementRoom]), [
                'title' => 'Safety meeting',
                'body' => 'Meet at the office at 9 AM.',
            ])
            ->assertForbidden();

        $managerEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Site Manager',
            'employee_number' => 'EMP-COMM-002',
            'employment_status' => 'active',
        ]);

        $managerUser = User::factory()->create([
            'employee_id' => $managerEmployee->id,
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $this->site->id,
            'account_status' => 'active',
        ]);

        $this->actingAs($managerUser)
            ->post(route('communication.store', ['room' => $announcementRoom]), [
                'title' => 'Safety meeting',
                'body' => 'Meet at the office at 9 AM.',
            ])
            ->assertRedirect(route('communication.show', ['room' => $announcementRoom]));

        $this->assertDatabaseHas('communication_messages', [
            'communication_room_id' => $announcementRoom->id,
            'kind' => CommunicationMessage::KIND_ANNOUNCEMENT,
            'title' => 'Safety meeting',
            'body' => 'Meet at the office at 9 AM.',
        ]);
    }

    public function test_worker_can_start_private_direct_message(): void
    {
        $coworkerEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Jane Coworker',
            'employee_number' => 'EMP-COMM-DM',
            'employment_status' => 'active',
        ]);

        $this->actingAs($this->workerUser)
            ->post(route('communication.direct.start'), ['employee_id' => $coworkerEmployee->id])
            ->assertRedirect();

        $ids = [$this->employee->id, $coworkerEmployee->id];
        sort($ids);
        $room = CommunicationRoom::query()->where('dm_key', $ids[0] . '-' . $ids[1])->firstOrFail();

        $this->assertSame(CommunicationRoom::TYPE_DIRECT, $room->type);
        $this->assertDatabaseHas('communication_room_members', [
            'communication_room_id' => $room->id,
            'employee_id' => $this->employee->id,
        ]);
        $this->assertDatabaseHas('communication_room_members', [
            'communication_room_id' => $room->id,
            'employee_id' => $coworkerEmployee->id,
        ]);
    }

    public function test_outsider_cannot_open_someone_elses_dm(): void
    {
        $coworkerEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Jane Coworker',
            'employee_number' => 'EMP-COMM-DM2',
            'employment_status' => 'active',
        ]);

        $room = $this->communicationService->directRoomFor($this->employee, $coworkerEmployee);

        $outsiderEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Nosy Neighbor',
            'employee_number' => 'EMP-COMM-DM3',
            'employment_status' => 'active',
        ]);
        $outsiderUser = User::factory()->create([
            'employee_id' => $outsiderEmployee->id,
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);

        $this->actingAs($outsiderUser)
            ->get(route('communication.show', ['room' => $room]))
            ->assertForbidden();
    }

    public function test_admin_cannot_see_others_dm_in_room_list(): void
    {
        $coworkerEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Jane Coworker',
            'employee_number' => 'EMP-COMM-DM4',
            'employment_status' => 'active',
        ]);
        $room = $this->communicationService->directRoomFor($this->employee, $coworkerEmployee);

        $adminUser = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $this->assertFalse(
            $this->communicationService->roomsForUser($adminUser)->contains('id', $room->id),
        );
    }

    public function test_announcement_fans_out_notifications_to_members(): void
    {
        $announcementRoom = $this->communicationService->ensureSiteRooms($this->site)['announcements'];

        $managerEmployee = Employee::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Site Manager',
            'employee_number' => 'EMP-COMM-MGR',
            'employment_status' => 'active',
        ]);
        $managerUser = User::factory()->create([
            'employee_id' => $managerEmployee->id,
            'access_role' => 'site_manager',
            'access_scope' => 'site',
            'allowed_site_id' => $this->site->id,
            'account_status' => 'active',
        ]);

        $this->actingAs($managerUser)
            ->post(route('communication.store', ['room' => $announcementRoom]), [
                'title' => 'Payday',
                'body' => 'Payday moved to Friday.',
            ])
            ->assertRedirect();

        // the worker (a member) is notified; the author is not
        $this->assertDatabaseHas('communication_notifications', [
            'employee_id' => $this->employee->id,
            'type' => CommunicationNotification::TYPE_ANNOUNCEMENT,
            'communication_room_id' => $announcementRoom->id,
        ]);
        $this->assertSame(
            0,
            CommunicationNotification::query()->where('employee_id', $managerEmployee->id)->count(),
        );
    }

    public function test_company_and_team_rooms_are_provisioned_for_member(): void
    {
        $team = Team::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'name' => 'Alpha Crew',
            'code' => 'ALPHA',
        ]);
        $this->employee->update(['team_id' => $team->id]);

        $rooms = $this->communicationService->roomsForUser($this->workerUser);

        $this->assertTrue($rooms->contains(fn (CommunicationRoom $room) => $room->type === CommunicationRoom::TYPE_COMPANY));
        $this->assertTrue($rooms->contains(fn (CommunicationRoom $room) => $room->type === CommunicationRoom::TYPE_TEAM && (int) $room->team_id === (int) $team->id));
    }
}
