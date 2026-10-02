<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkerDevice;
use App\Services\Attendance\AttendanceNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceCompletionNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Employee $worker;

    private CommunicationRoom $room;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Test', 'code' => 'TEST', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'name' => 'Test site', 'code' => 'T', 'status' => 'active']);
        $this->worker = Employee::create(['company_id' => $company->id, 'site_id' => $this->site->id, 'name' => 'Worker', 'employment_status' => 'active']);
        $this->token = WorkerDevice::issueFor($this->worker, 'test', verified: true);
        $this->room = CommunicationRoom::create(['company_id' => $company->id, 'site_id' => $this->site->id, 'name' => 'Notices', 'type' => CommunicationRoom::TYPE_SITE_ANNOUNCEMENT, 'status' => 'active', 'is_read_only' => true]);
    }

    private function notice(array $settings = [], ?CommunicationRoom $room = null): CommunicationMessage
    {
        return CommunicationMessage::create(['communication_room_id' => ($room ?? $this->room)->id,
            'kind' => 'announcement', 'title' => 'Safety', 'body' => 'Use the east gate.', 'status' => 'active',
            'payload' => ['attendance_notice' => array_merge(['event' => 'both', 'required' => false, 'expires_at' => now()->addDay()->toIso8601String()], $settings)]]);
    }

    private function punch(): void
    {
        $this->postJson(route('gate.punch', $this->site), ['device_token' => $this->token])->assertOk()->assertJsonPath('event', 'clock_in');
    }

    private function notices()
    {
        return $this->postJson(route('gate.notices', $this->site), ['device_token' => $this->token]);
    }

    public function test_notices_require_own_active_device_and_a_saved_punch(): void
    {
        $this->notice();
        $this->notices()->assertForbidden();
        $this->punch();
        $this->postJson(route('gate.notices', $this->site), ['device_token' => 'invalid'])->assertForbidden();
        $this->notices()->assertOk()->assertJsonCount(1, 'notices');
        $this->worker->update(['employment_status' => 'terminated']);
        $this->notices()->assertForbidden();
    }

    public function test_only_current_site_company_event_and_unexpired_announcements_are_visible(): void
    {
        $wanted = $this->notice();
        $this->notice(['event' => 'clock_out']);
        $this->notice(['expires_at' => now()->subMinute()->toIso8601String()]);
        $this->notice()->update(['removed_at' => now()]);
        $this->notice()->update(['status' => 'inactive']);
        $other = $this->room->replicate();
        $other->site_id = null;
        $other->company_id = null;
        $other->save();
        $hidden = $this->notice([], $other);
        $this->punch();
        $this->notices()->assertOk()->assertJsonCount(1, 'notices')->assertJsonPath('notices.0.id', $wanted->id);
        $this->postJson(route('gate.notice-ack', $this->site), ['device_token' => $this->token, 'message_id' => $hidden->id])->assertNotFound();
    }

    public function test_required_acknowledgement_is_explicit_idempotent_and_does_not_punch_again(): void
    {
        $notice = $this->notice(['required' => true]);
        $this->punch();
        $this->notices()->assertJsonPath('notices.0.required', true);
        $this->assertDatabaseCount('attendance_notice_receipts', 0);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('gate.notice-ack', $this->site), ['device_token' => $this->token, 'message_id' => $notice->id])->assertOk();
        }
        $this->assertDatabaseCount('attendance_notice_receipts', 1);
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->notices()->assertJsonCount(0, 'notices');
        $this->travel(2)->seconds();
        $notice->update(['body' => 'Changed instructions', 'edited_at' => now()]);
        $this->notices()->assertJsonCount(1, 'notices');
    }

    public function test_notice_failure_does_not_rollback_attendance(): void
    {
        $this->mock(AttendanceNoticeService::class)->shouldReceive('unread')->andThrow(new \RuntimeException('temporary'));
        $this->punch();
        $this->notices()->assertStatus(500);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_private_attachment_cannot_be_read_from_another_notice_or_site(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('notice.txt', 'safe test');
        $notice = $this->notice();
        $file = CommunicationMessageFile::create(['communication_message_id' => $notice->id, 'disk' => 'local', 'path' => 'notice.txt', 'original_name' => 'notice.txt', 'mime_type' => 'text/plain', 'extension' => 'txt', 'kind' => 'document', 'file_size' => 9]);
        $this->punch();
        $this->post(route('gate.notice-file', $this->site), ['device_token' => $this->token, 'file_id' => $file->id])->assertOk();
        $notice->update(['removed_at' => now()]);
        $this->post(route('gate.notice-file', $this->site), ['device_token' => $this->token, 'file_id' => $file->id])->assertNotFound();
    }

    public function test_authorized_manager_can_publish_and_view_confirmation_but_worker_cannot(): void
    {
        $admin = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($admin)->postJson(route('communication.store', $this->room), [
            'body' => 'Tomorrow schedule', 'attendance_event' => 'clock_out', 'attendance_required' => true,
            'attendance_expires' => now()->addDays(2)->toDateString(),
        ])->assertOk();
        $m = CommunicationMessage::where('body', 'Tomorrow schedule')->firstOrFail();
        $this->assertSame('clock_out', $m->payload['attendance_notice']['event']);
        $this->get(route('communication.attendance-report', $this->room))->assertOk()->assertSee('clock_out');
        $worker = User::factory()->create(['employee_id' => $this->worker->id, 'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
        $this->actingAs($worker)->get(route('communication.attendance-report', $this->room))->assertForbidden();
    }
}
