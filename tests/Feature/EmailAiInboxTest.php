<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeIntelligentDocumentJob;
use App\Jobs\SyncMailboxJob;
use App\Models\Company;
use App\Models\EmailThread;
use App\Models\IntelligentDocument;
use App\Models\MailboxConnection;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Mail\EmailMessageIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailAiInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'document-intelligence.disk' => 'local',
            'documents.disk' => 'local',
            'services.microsoft_mail.client_id' => 'client-id',
            'services.microsoft_mail.client_secret' => 'secret',
            'services.microsoft_mail.tenant' => 'organizations',
            'services.microsoft_mail.redirect' => 'https://erp.example.test/email-ai/microsoft/callback',
        ]);
    }

    public function test_user_connects_with_read_only_microsoft_oauth_instead_of_typing_a_mailbox_password(): void
    {
        $user = $this->user('admin');
        $response = $this->actingAs($user)->get(route('email-ai.microsoft.connect'));
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('login.microsoftonline.com/organizations/oauth2/v2.0/authorize', $location);
        $this->assertStringContainsString('Mail.Read', urldecode($location));
        $this->assertStringNotContainsString('Mail.ReadWrite', urldecode($location));
        $this->assertStringNotContainsString('Mail.Send', urldecode($location));
    }

    public function test_private_mail_is_owner_only_even_for_another_super_admin(): void
    {
        [$company] = $this->projectFixture();
        $owner = $this->user('admin');
        $otherAdmin = $this->user('super_admin');
        $connection = $this->connection($owner, $company);
        Bus::fake();

        app(EmailMessageIngestor::class)->ingest($connection, $this->remote(), $this->mime());

        $doc = IntelligentDocument::query()->sole();
        $this->assertSame('private', $doc->access_level);
        $this->assertSame($owner->id, $doc->owner_user_id);
        $this->assertTrue(IntelligentDocument::query()->visibleTo($owner)->whereKey($doc)->exists());
        $this->assertFalse(IntelligentDocument::query()->visibleTo($otherAdmin)->whereKey($doc)->exists());
        Bus::assertDispatched(AnalyzeIntelligentDocumentJob::class);
    }

    public function test_same_private_attachment_can_be_owned_by_two_users_without_cross_mailbox_deduplication(): void
    {
        [$company] = $this->projectFixture();
        $one = $this->user('admin');
        $two = $this->user('admin');
        Bus::fake();
        $attachment = [[
            'id' => 'att-1', 'name' => 'Door Schedule.txt', 'contentType' => 'text/plain',
            'isInline' => false, 'bytes' => 'same private attachment bytes for both users',
        ]];

        app(EmailMessageIngestor::class)->ingest($this->connection($one, $company), $this->remote('message-one'), $this->mime('one'), $attachment);
        app(EmailMessageIngestor::class)->ingest($this->connection($two, $company), $this->remote('message-two'), $this->mime('two'), $attachment);

        $this->assertSame(2, IntelligentDocument::query()->where('source', 'email_attachment')->count());
        $this->assertCount(2, IntelligentDocument::query()->where('source', 'email_attachment')->pluck('owner_user_id')->unique());
    }

    public function test_owner_can_publish_email_to_project_and_project_manager_can_then_open_it(): void
    {
        [$company, $site, $project] = $this->projectFixture();
        $owner = $this->user('admin');
        $manager = $this->user('site_manager', 'site', $site->id, $company->id);
        Bus::fake();
        app(EmailMessageIngestor::class)->ingest($this->connection($owner, $company), $this->remote(), $this->mime());
        $thread = EmailThread::query()->sole();
        $document = IntelligentDocument::query()->sole();

        $this->actingAs($owner)->patchJson(route('email-ai.share', $thread), [
            'visibility' => 'project', 'project_id' => $project->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('project', $thread->fresh()->visibility);
        $this->assertSame('project', $document->fresh()->access_level);
        $this->assertTrue(IntelligentDocument::query()->visibleTo($manager)->whereKey($document)->exists());
        $this->actingAs($manager)->getJson(route('email-ai.thread', $thread))->assertOk();
    }

    public function test_user_cannot_sync_or_share_another_users_mailbox(): void
    {
        [$company] = $this->projectFixture();
        $owner = $this->user('admin');
        $other = $this->user('admin');
        $connection = $this->connection($owner, $company);
        Bus::fake();
        app(EmailMessageIngestor::class)->ingest($connection, $this->remote(), $this->mime());
        $thread = EmailThread::query()->sole();

        $this->actingAs($other)->postJson(route('email-ai.sync', $connection))->assertForbidden();
        $this->actingAs($other)->patchJson(route('email-ai.share', $thread), ['visibility' => 'private'])->assertForbidden();
        Bus::assertNotDispatched(SyncMailboxJob::class);
    }

    public function test_email_ai_screen_is_in_the_erp_document_menu(): void
    {
        $user = $this->user('admin');
        $this->actingAs($user)->get('/')->assertOk()->assertSee('회사 이메일 분석함');
        $this->actingAs($user)->get('/email-ai?embed=1')->assertOk()->assertSee('Outlook 연결');
    }

    private function remote(string $id = 'message-1'): array
    {
        return [
            'id' => $id, 'conversationId' => 'thread-'.$id, 'internetMessageId' => '<'.$id.'@example.test>',
            'subject' => 'RE: 703K Door Delivery Schedule',
            'from' => ['emailAddress' => ['address' => 'john@turner.example']],
            'toRecipients' => [['emailAddress' => ['address' => 'office@example.test']]],
            'ccRecipients' => [], 'sentDateTime' => '2026-09-20T10:32:00Z',
            'receivedDateTime' => '2026-09-20T10:32:01Z', 'bodyPreview' => 'Delivery is confirmed for September 24.',
            'hasAttachments' => false,
        ];
    }

    private function mime(string $suffix = ''): string
    {
        return "From: john@turner.example\r\nTo: office@example.test\r\nSubject: Door Delivery {$suffix}\r\n\r\nDelivery is confirmed for September 24.";
    }

    private function connection(User $user, Company $company): MailboxConnection
    {
        return MailboxConnection::query()->create([
            'user_id' => $user->id, 'company_id' => $company->id, 'provider' => 'microsoft',
            'provider_user_id' => 'ms-'.$user->id, 'email' => 'user'.$user->id.'@example.test',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'selected_folders' => ['inbox', 'sentitems'], 'status' => 'active',
        ]);
    }

    private function projectFixture(): array
    {
        $company = Company::query()->create(['code' => 'EXAMPLE', 'name' => 'Example Company', 'status' => 'active']);
        $site = Site::query()->create(['company_id' => $company->id, 'code' => '703K', 'name' => '703K', 'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active']);
        $project = Project::query()->create(['company_id' => $company->id, 'site_id' => $site->id, 'project_code' => '703K', 'name' => '703K Kitchen', 'construction_type' => 'commercial', 'project_stage' => 'awarded']);
        return [$company, $site, $project];
    }

    private function user(string $role, string $scope = 'all_sites', ?int $siteId = null, ?int $companyId = null): User
    {
        return User::query()->create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.test', 'password' => bcrypt('password'),
            'access_role' => $role, 'access_scope' => $scope, 'allowed_site_id' => $siteId,
            'allowed_company_id' => $companyId, 'account_status' => 'active',
        ]);
    }
}
