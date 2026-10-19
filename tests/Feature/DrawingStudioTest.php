<?php

namespace Tests\Feature;

use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\Push\ChatPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DrawingStudioTest extends TestCase
{
    use RefreshDatabase;

    private DrawingSheet $sheet;

    private User $admin;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'CAD', 'name' => 'CAD Test', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'ST', 'name' => 'Studio', 'status' => 'active']);
        $this->admin = User::factory()->create(['account_status' => 'active', 'access_role' => 'admin', 'access_scope' => 'all_sites']);
        $document = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'site_id' => $site->id,
            'uploaded_by' => $this->admin->id, 'title' => 'Plan', 'disk' => 'local', 'file_path' => 'plan.pdf', 'original_file_name' => 'plan.pdf', 'stored_file_name' => 'plan.pdf',
            'sha256' => hash('sha256', 'studio-fixture'), 'file_size' => 1, 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'category' => 'drawing_spec', 'document_type' => 'drawing', 'access_level' => 'site']);
        $this->sheet = DrawingSheet::create(['intelligent_document_id' => $document->id, 'site_id' => $site->id, 'page_no' => 1, 'sheet_no' => 'A1']);
        $this->url = '/admin-api/drawing-sheets/'.$this->sheet->id.'/studio';
        $this->actingAs($this->admin);
    }

    private function drawing(): array
    {
        return ['version' => 1, 'unit' => 'm', 'precision' => 2,
            'layers' => [['id' => 'default', 'name' => 'Markup', 'visible' => true, 'locked' => false]],
            'entities' => [['id' => 'a', 'layer' => 'default', 'type' => 'dimension', 'points' => [[.1, .2], [.5, .2]],
                'text' => '', 'color' => '#ff0000', 'fill' => 'none', 'width' => 1.5, 'opacity' => 1, 'dash' => 'solid', 'fontSize' => 12]]];
    }

    public function test_save_reload_history_and_conflict_do_not_lose_other_edits(): void
    {
        $this->getJson($this->url)->assertOk()->assertJsonPath('revision', 0);
        $this->putJson($this->url, ['revision' => 0, 'document' => $this->drawing()])->assertOk()->assertJsonPath('revision', 1);
        $this->getJson($this->url)->assertOk()->assertJsonPath('document.entities.0.type', 'dimension')->assertJsonPath('document.entities.0.text', '');
        $this->putJson($this->url, ['revision' => 0, 'document' => $this->drawing()])->assertStatus(409);
        $this->getJson($this->url.'/history?revision=1')->assertOk()->assertJsonPath('document.entities.0.id', 'a');
        $this->assertDatabaseCount('drawing_studio_revisions', 1);
    }

    public function test_scope_and_read_only_permissions_apply_to_every_route(): void
    {
        $otherSite = Site::create(['company_id' => $this->sheet->site->company_id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $other = User::factory()->create(['account_status' => 'active', 'access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $otherSite->id]);
        $this->actingAs($other)->getJson($this->url)->assertNotFound();
        $this->actingAs($other)->getJson($this->url.'/history')->assertNotFound();
        $this->actingAs($other)->postJson($this->url.'/share', [])->assertNotFound();
        $viewer = User::factory()->create(['account_status' => 'active', 'access_role' => 'payroll', 'access_scope' => 'all_sites']);
        $this->actingAs($viewer)->getJson($this->url)->assertOk()->assertJsonPath('canManage', false);
        $this->actingAs($viewer)->putJson($this->url, ['revision' => 0, 'document' => $this->drawing()])->assertForbidden();
        $this->actingAs($viewer)->getJson($this->url.'/share-options')->assertForbidden();
    }

    public function test_invalid_geometry_and_svg_style_are_rejected(): void
    {
        $d = $this->drawing();
        $d['entities'][0]['points'][1][0] = 1.2;
        $this->putJson($this->url, ['revision' => 0, 'document' => $d])->assertUnprocessable();
        $d = $this->drawing();
        $d['entities'][0]['color'] = 'url(https://example.com)';
        $this->putJson($this->url, ['revision' => 0, 'document' => $d])->assertUnprocessable();
        $d = $this->drawing();
        $d['entities'][0]['points'] = [[.2, .2]];
        $this->putJson($this->url, ['revision' => 0, 'document' => $d])->assertUnprocessable();
        $d = $this->drawing();
        $d['layers'] = 'invalid';
        $this->putJson($this->url, ['revision' => 0, 'document' => $d])->assertUnprocessable();
        $this->assertDatabaseCount('drawing_studios', 0);
    }

    public function test_mail_needs_real_configuration_and_duplicate_dispatch_is_not_sent_again(): void
    {
        Mail::fake();
        $payload = ['requestId' => (string) Str::uuid(), 'channel' => 'mail', 'to' => 'review@example.org', 'subject' => 'Plan review', 'body' => '',
            'attachment' => UploadedFile::fake()->image('drawing.png')];
        config(['mail.default' => 'log']);
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertUnprocessable();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org', 'mail.from.address' => 'office@example.org']);
        DB::table('drawing_share_dispatches')->insert(['id' => $payload['requestId'], 'drawing_sheet_id' => $this->sheet->id, 'user_id' => $this->admin->id,
            'channel' => 'mail', 'destination' => 'review@example.org', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('duplicate', true);
        Mail::assertNothingSent();
    }

    public function test_mail_contains_the_exact_preview_and_transport_failure_is_not_retried(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org', 'mail.from.address' => 'office@example.org']);
        $file = UploadedFile::fake()->image('drawing.png');
        $bytes = file_get_contents($file->getRealPath());
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use ($bytes) {
            $email = new Email;
            $callback(new Message($email));
            $this->assertStringContainsString('A1', $body);
            $this->assertSame('review@example.org', $email->getTo()[0]->getAddress());
            $this->assertSame($bytes, $email->getAttachments()[0]->getBody());
            throw new \RuntimeException('Simulated uncertain transport result');
        });
        $payload = ['requestId' => (string) Str::uuid(), 'channel' => 'mail', 'to' => 'review@example.org', 'subject' => 'Review', 'body' => 'Check this', 'attachment' => $file];
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertStatus(502);
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertDatabaseHas('drawing_share_dispatches', ['id' => $payload['requestId'], 'status' => 'uncertain']);
    }

    public function test_private_chat_keeps_downloadable_attachment_without_creating_source_documents(): void
    {
        Storage::fake('local');
        config(['document-intelligence.disk' => 'local']);
        $this->mock(ChatPushNotifier::class)->shouldReceive('notify')->once();
        $room = CommunicationRoom::create(['company_id' => $this->sheet->site->company_id, 'site_id' => $this->sheet->site_id,
            'type' => CommunicationRoom::TYPE_GROUP, 'name' => 'Private review', 'status' => 'active']);
        $file = UploadedFile::fake()->image('drawing.png');
        $bytes = file_get_contents($file->getRealPath());
        $payload = ['requestId' => (string) Str::uuid(), 'channel' => 'chat', 'room' => $room->id, 'subject' => 'Review', 'body' => '', 'attachment' => $file];
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertForbidden();
        CommunicationRoomMember::create(['communication_room_id' => $room->id, 'user_id' => $this->admin->id, 'role' => 'member', 'status' => 'active']);
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post($this->url.'/share', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('duplicate', true);
        $attached = CommunicationMessageFile::firstOrFail();
        $this->assertSame($bytes, Storage::disk($attached->disk)->get($attached->path));
        $this->actingAsPersonalAppUser($this->admin)->get(route('communication.file', ['room' => $room, 'file' => $attached]))
            ->assertOk()->assertStreamedContent($bytes);
        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertDatabaseCount('intelligent_documents', 1);
        $this->getJson($this->url.'/messages?room='.$room->id)->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_managed_viewer_does_not_gain_edit_access_from_the_get_route(): void
    {
        $this->admin->forceFill(['job_role' => 'office', 'access_scope' => 'company', 'allowed_company_id' => $this->sheet->site->company_id,
            'job_permissions' => ['progress' => ['view'], 'documents' => ['view', 'export']]])->save();
        $this->actingAsPersonalAppUser($this->admin);
        $this->getJson($this->url)->assertOk()->assertJsonPath('canManage', false);
        $this->putJson($this->url, ['revision' => 0, 'document' => $this->drawing()])->assertForbidden();
        $this->postJson($this->url.'/share', [])->assertForbidden();
    }
}
