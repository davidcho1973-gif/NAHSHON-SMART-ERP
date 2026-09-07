<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\DailyTradeReport;
use App\Models\Employee;
use App\Models\KakaoDelivery;
use App\Models\KakaoRecipient;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\KakaoReminderAdminService;
use App\Services\Kakao\SolapiAlimtalk;
use App\Services\Kakao\WorkReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KakaoWorkReminderTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $worker;

    private KakaoRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['kakao.enabled' => true, 'kakao.api_key' => 'test-key', 'kakao.api_secret' => 'test-secret',
            'kakao.channel_id' => 'test-channel', 'kakao.base_url' => 'https://erp.example.com',
            'kakao.confirmed_countries' => ['1'], 'kakao.templates' => ['clock_in' => 'tpl-in', 'clock_out' => 'tpl-out', 'daily_report' => 'tpl-report']]);
        $company = Company::create(['code' => 'KAKAO', 'name' => 'Test Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'SAV', 'name' => 'Savannah', 'status' => 'active', 'timezone' => 'America/New_York']);
        $this->employee = Employee::create(['company_id' => $company->id, 'site_id' => $site->id, 'name' => 'Test Worker', 'role' => 'Plumbing', 'employment_status' => 'active']);
        $this->worker = User::factory()->create(['employee_id' => $this->employee->id, 'account_status' => 'active', 'access_role' => 'worker']);
        $this->recipient = KakaoRecipient::create(['employee_id' => $this->employee->id, 'site_id' => $site->id, 'phone' => '+14805550123',
            'enabled' => true, 'consented_at' => now(), 'weekdays' => [1, 2, 3, 4, 5], 'clock_in' => '07:00', 'clock_out' => '17:00', 'daily_report' => '17:30']);
        Http::fake(['api.solapi.com/*' => Http::response(['messageList' => [['messageId' => 'M-test', 'statusCode' => '2000']], 'groupInfo' => ['groupId' => 'G-test']])]);
    }

    private function runAt(string $utc, bool $dry = false): array
    {
        return app(WorkReminderService::class)->run($dry, Carbon::parse($utc, 'UTC'));
    }

    private function attendance(string $event = 'clock_in', string $status = 'approved'): void
    {
        AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['employee_id' => $this->employee->id, 'site_id' => $this->employee->site_id,
            'attendance_date' => '2026-09-07', 'event_type' => $event, 'event_at' => '2026-09-07 11:00:00', 'status' => $status]));
    }

    public function test_us_payload_signature_and_single_daily_claim(): void
    {
        $this->assertSame(1, $this->runAt('2026-09-07 11:10:00')['attempted']);
        $this->runAt('2026-09-07 11:20:00');
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('4805550123', $request['messages'][0]['to']);
            $this->assertSame('1', $request['messages'][0]['country']);
            $this->assertSame('ATA', $request['messages'][0]['type']);
            $this->assertTrue($request['messages'][0]['kakaoOptions']['disableSms']);
            $this->assertSame('https://erp.example.com/attendance-app', $request['messages'][0]['kakaoOptions']['variables']['#{링크}']);
            preg_match('/date=([^,]+), salt=([^,]+), signature=(.+)/', $request->header('Authorization')[0], $parts);
            $this->assertSame(hash_hmac('sha256', $parts[1].$parts[2], 'test-secret'), $parts[3]);

            return true;
        });
        $this->assertDatabaseHas('kakao_deliveries', ['status' => 'accepted', 'work_date' => '2026-09-07', 'message_id' => 'M-test']);
    }

    public function test_disabled_missing_keys_or_unconfirmed_country_cannot_send(): void
    {
        config(['kakao.enabled' => false]);
        $this->runAt('2026-09-07 11:10:00');
        config(['kakao.enabled' => true, 'kakao.api_secret' => '']);
        $this->runAt('2026-09-07 11:10:00');
        config(['kakao.api_secret' => 'test-secret', 'kakao.confirmed_countries' => []]);
        $this->runAt('2026-09-07 11:10:00');
        Http::assertNothingSent();
        $this->assertDatabaseCount('kakao_deliveries', 0);
    }

    public function test_read_only_preview_works_before_activation(): void
    {
        config(['kakao.enabled' => false]);
        $this->assertSame(1, $this->runAt('2026-09-07 11:10:00', true)['due']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('kakao_deliveries', 0);
    }

    public function test_weekend_early_and_stale_reminders_are_not_sent(): void
    {
        $this->runAt('2026-09-06 11:10:00');
        $this->runAt('2026-09-07 10:59:59');
        $this->runAt('2026-09-07 12:00:00');
        Http::assertNothingSent();
    }

    public function test_inactive_recipient_account_or_moved_site_is_excluded(): void
    {
        $this->recipient->update(['enabled' => false]);
        $this->runAt('2026-09-07 11:10:00');
        $this->recipient->update(['enabled' => true, 'consented_at' => null]);
        $this->runAt('2026-09-07 11:10:00');
        $this->recipient->update(['consented_at' => now()]);
        $this->worker->update(['account_status' => 'suspended']);
        $this->runAt('2026-09-07 11:10:00');
        $this->worker->update(['account_status' => 'active']);
        $this->employee->update(['site_id' => null]);
        $this->runAt('2026-09-07 11:10:00');
        Http::assertNothingSent();
    }

    public function test_clocked_in_and_finalized_session_do_not_get_reminded(): void
    {
        AttendanceSession::create(['employee_id' => $this->employee->id, 'site_id' => $this->employee->site_id, 'work_date' => '2026-09-07',
            'first_enter_at' => '2026-09-07 11:00:00', 'finalized_at' => '2026-09-07 21:00:00', 'status' => 'closed']);
        $this->runAt('2026-09-07 11:10:00');
        $this->runAt('2026-09-07 21:10:00');
        Http::assertNothingSent();
        $this->assertDatabaseHas('kakao_deliveries', ['reason' => 'already_clocked_in']);
        $this->assertDatabaseHas('kakao_deliveries', ['reason' => 'already_clocked_out']);
    }

    public function test_no_attendance_excludes_evening_reminders(): void
    {
        $this->runAt('2026-09-07 21:35:00');
        Http::assertNothingSent();
        $this->assertDatabaseCount('kakao_deliveries', 2);
    }

    public function test_rejected_attendance_does_not_suppress_clock_in(): void
    {
        $this->attendance('clock_in', 'rejected');
        $this->runAt('2026-09-07 11:10:00');
        Http::assertSentCount(1);
    }

    public function test_clock_out_log_and_submitted_trade_report_are_excluded(): void
    {
        $this->attendance();
        $this->attendance('clock_out');
        DailyTradeReport::create(['site_id' => $this->employee->site_id, 'work_date' => '2026-09-07', 'trade' => 'Plumbing', 'status' => 'submitted']);
        $this->runAt('2026-09-07 21:35:00');
        Http::assertNothingSent();
        $this->assertDatabaseHas('kakao_deliveries', ['reason' => 'report_already_submitted']);
    }

    public function test_evening_links_only_prompt_for_existing_work(): void
    {
        $this->attendance();
        $this->runAt('2026-09-07 21:35:00');
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['messages'][0]['kakaoOptions']['variables']['#{링크}'] === 'https://erp.example.com/attendance-app/ops-room');
        $this->assertDatabaseCount('daily_trade_reports', 0);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_timeout_is_unknown_and_never_automatically_retried(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.solapi.com/*' => Http::failedConnection()]);
        $this->runAt('2026-09-07 11:10:00');
        $this->runAt('2026-09-07 11:20:00');
        $this->assertDatabaseHas('kakao_deliveries', ['status' => 'unknown']);
        $this->assertDatabaseCount('kakao_deliveries', 1);
    }

    public function test_provider_rejection_is_not_reported_as_success(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.solapi.com/*' => Http::response(['failedMessageList' => [['statusCode' => '3040', 'to' => 'secret-number']]])]);
        $this->runAt('2026-09-07 11:10:00');
        $this->assertDatabaseHas('kakao_deliveries', ['status' => 'failed', 'reason' => 'provider_rejected']);
        $this->assertStringNotContainsString('secret-number', KakaoDelivery::first()->toJson());
    }

    public function test_http_failure_and_malformed_success_are_not_accepted(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $provider = app(SolapiAlimtalk::class);
        Http::fake(['api.solapi.com/*' => Http::sequence()->push([], 401)->push([], 500)->push([], 200)->push(['messageList' => [['messageId' => 'M', 'statusCode' => '4000']]])]);
        $this->assertSame('failed', $provider->send('+14805550123', 'clock_in', [])['status']);
        $this->assertSame('unknown', $provider->send('+14805550123', 'clock_in', [])['status']);
        $this->assertSame('unknown', $provider->send('+14805550123', 'clock_in', [])['status']);
        $this->assertSame('delivered', $provider->send('+14805550123', 'clock_in', [])['status']);
    }

    public function test_another_process_claim_or_interruption_cannot_double_send(): void
    {
        $delivery = KakaoDelivery::create(['employee_id' => $this->employee->id, 'site_id' => $this->employee->site_id,
            'work_date' => '2026-09-07', 'kind' => 'clock_in', 'status' => 'sending', 'updated_at' => '2026-09-07 10:00:00']);
        $this->runAt('2026-09-07 11:10:00');
        Http::assertNothingSent();
        $this->assertSame('unknown', $delivery->fresh()->status);
    }

    public function test_dst_fall_back_hour_is_only_sent_once(): void
    {
        $this->recipient->update(['weekdays' => [7], 'clock_in' => '01:15']);
        $this->runAt('2026-11-01 05:20:00');
        $this->runAt('2026-11-01 06:20:00');
        Http::assertSentCount(1);
    }

    public function test_worker_and_foreman_cannot_read_or_change_recipients(): void
    {
        foreach (['worker', 'foreman', 'site_manager', 'hr_manager'] as $role) {
            $this->worker->update(['access_role' => $role]);
            $this->actingAs($this->worker);
            $this->postJson('/smart-company-api/api_getKakaoReminders', ['args' => []])->assertOk()->assertJson(['success' => false]);
            $this->postJson('/smart-company-api/api_saveKakaoReminder', ['args' => [[]]])->assertForbidden();
        }
    }

    public function test_admin_can_save_us_recipient_but_never_sees_credentials(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $service = app(KakaoReminderAdminService::class);
        $result = $service->save(['employeeId' => $this->employee->id, 'phone' => '+14805550124', 'enabled' => true, 'consented' => true,
            'weekdays' => [1, 2], 'clock_in' => '07:00']);
        $this->assertTrue($result['success']);
        $this->assertSame('+14805550124', $this->recipient->fresh()->phone);
        $this->assertNotSame('+14805550124', DB::table('kakao_recipients')->value('phone'));
        $this->assertStringNotContainsString('test-secret', json_encode($service->overview()));
        $this->assertStringNotContainsString('test-key', json_encode($service->overview()));
        Http::assertNothingSent();
    }

    public function test_validation_requires_international_number_and_consent(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $base = ['employeeId' => $this->employee->id, 'phone' => '+14805550123', 'enabled' => true, 'consented' => true, 'weekdays' => [1], 'clock_in' => '07:00'];
        foreach ([['phone' => '4805550123'], ['consented' => false], ['clock_in' => '25:00'], ['weekdays' => [8]], ['clock_in' => null]] as $bad) {
            $this->assertFalse(app(KakaoReminderAdminService::class)->save(array_merge($base, $bad))['success']);
        }
        Http::assertNothingSent();
    }

    public function test_real_admin_api_dispatches_without_sending(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $this->postJson('/smart-company-api/api_getKakaoReminders', ['args' => []])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('employees.0.phone', '+14805550123');
        $this->postJson('/smart-company-api/api_saveKakaoReminder', ['args' => [[
            'employeeId' => $this->employee->id, 'phone' => '+14805550123', 'enabled' => false, 'consented' => false,
            'weekdays' => [1], 'clock_in' => '07:00',
        ]]])->assertOk()->assertJsonPath('success', true);
        $this->assertFalse($this->recipient->fresh()->enabled);
        Http::assertNothingSent();
    }

    public function test_invalid_app_url_blocks_outbound_requests(): void
    {
        foreach (['http://erp.example.com', 'https://erp.example.com/?token=secret', 'https://user:pass@erp.example.com', 'https://erp.example.com/other'] as $url) {
            config(['kakao.base_url' => $url]);
            $this->runAt('2026-09-07 11:10:00');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('kakao_deliveries', 0);
    }
}
