<?php

namespace Tests\Feature;

use App\Mail\SubmittalMail;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\Site;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
    }

    public function test_admin_reads_original_and_worker_reads_only_assigned_technical_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fixture.txt', 'technical source');
        $site = Site::create(['code' => 'LEGACY-TEST', 'name' => 'Site', 'status' => 'active']);
        $doc = IntegratedDocument::create(['site_id' => $site->id, 'title' => 'Plan', 'document_type' => 'drawing', 'disk' => 'local', 'path' => 'fixture.txt', 'status' => 'confirmed']);
        $this->admin();
        $this->get('/docs-api/file/'.$doc->id)->assertOk();
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'account_status' => 'active', 'access_scope' => 'site', 'allowed_site_id' => $site->id]));
        $this->get('/docs-api/file/'.$doc->id)->assertOk();
    }

    public function test_personnel_status_is_persisted_without_claiming_asset_return(): void
    {
        $this->admin();
        $employee = Employee::create(['employee_number' => 'LEGACY-EMP', 'first_name' => 'Test', 'employment_status' => 'active']);
        $result = SmartCompanyData::handle('api_syncWorkerStatus', ['LEGACY-EMP', 'terminated']);
        $this->assertTrue($result['success']);
        $this->assertSame('terminated', $employee->fresh()->employment_status);
    }

    public function test_translation_uses_provider_response_instead_of_echoing_input(): void
    {
        $this->admin();
        config(['services.anthropic.api_key' => 'test-only']);
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'Please quote 98 studs.']]])]);
        $result = SmartCompanyData::handle('api_translateToEnglish', ['스터드 98개 견적 부탁합니다.']);
        $this->assertSame('Please quote 98 studs.', $result['english']);
        Http::assertSentCount(1);
    }

    public function test_excel_export_is_an_actual_xlsx_archive(): void
    {
        $this->admin();
        $result = SmartCompanyData::handle('api_getFinanceExcelBase64', []);
        $this->assertStringStartsWith('PK', base64_decode($result, true));
    }

    public function test_unconfigured_vendor_mail_is_not_reported_as_sent(): void
    {
        $this->admin();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null, 'mail.mailers.smtp.username' => null]);
        $result = SmartCompanyData::handle('api_sendVendorEmail', ['test@example.invalid', 'Quote', 'Please quote 98 studs.', 'Vendor']);
        $this->assertFalse($result['success']);
        $this->assertSame(0, (int) ($result['sent'] ?? 0));
    }

    public function test_configured_vendor_mail_uses_delivery_and_records_the_result(): void
    {
        $this->admin();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.invalid', 'mail.from.address' => 'sender@example.invalid']);
        Mail::fake();
        $result = SmartCompanyData::handle('api_sendVendorEmail', ['vendor@example.invalid', 'Quote', '98 studs please.', 'Vendor']);
        $this->assertTrue($result['success']);
        Mail::assertSent(SubmittalMail::class, fn ($mail) => $mail->hasTo('vendor@example.invalid'));
        $this->assertDatabaseHas('mail_messages', ['subject' => 'Quote', 'status' => 'sent']);
    }

    public function test_vendor_replies_are_scoped_to_the_managers_site(): void
    {
        $a = Site::create(['code' => 'REPLY-A', 'name' => 'A']);
        $b = Site::create(['code' => 'REPLY-B', 'name' => 'B']);
        foreach ([$a, $b] as $site) {
            $thread = MailThread::open(['site_id' => $site->id, 'counterparty_email' => 'vendor@example.invalid', 'subject' => 'Quote']);
            MailMessage::create(['mail_thread_id' => $thread->id, 'site_id' => $site->id, 'direction' => 'incoming', 'status' => 'received', 'channel' => 'mail', 'subject' => 'Reply', 'body_text' => $site->code, 'occurred_at' => now()]);
        }
        $this->actingAs(User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active', 'access_scope' => 'site', 'allowed_site_id' => $a->id]));
        $result = SmartCompanyData::handle('api_getVendorReplies', ['vendor@example.invalid']);
        $this->assertSame(['REPLY-A'], array_column($result['replies'], 'body'));
    }
}
