<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Communication\ChatAttachmentService;
use App\Services\Communication\ChatDocumentReplyConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 채팅방에 던진 파일이 문서함을 거쳐 제 모듈까지 간다.
 *
 * 현장 사람들은 영수증도 자재 라벨도 대화창에 사진으로 던진다. 그 파일이 메신저
 * 안에서만 살면 같은 내용을 누군가 문서함에 다시 올리고 재무에 또 입력해야 한다.
 * 여기서 지키는 것은 그 길이다 — 방에 올린 파일이 문서함으로 접수되고(같은 창구,
 * 같은 규칙), 분석이 끝나면 <b>그 방에 결과가 돌아온다</b>.
 */
class ChatAttachmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Site $site;
    private Employee $employee;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('document-intelligence.disk', 'local'));

        $this->company = Company::create(['code' => 'CHAT-CO', 'name' => 'Chat Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'CHAT-SITE', 'name' => '현장', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'Kim', 'last_name' => 'Banjang',
            'email' => 'kim@example.com', 'employment_status' => 'active',
        ]);
        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id, 'access_role' => 'site_manager', 'account_status' => 'active',
        ]);
    }

    private function room(string $type = CommunicationRoom::TYPE_SITE_CHAT): CommunicationRoom
    {
        return CommunicationRoom::query()->create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'type' => $type,
            'name' => '현장 채팅방',
            'status' => 'active',
        ]);
    }

    private function message(CommunicationRoom $room): CommunicationMessage
    {
        return CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'company_id' => $room->company_id,
            'site_id' => $room->site_id,
            'sender_user_id' => $this->user->id,
            'sender_employee_id' => $this->employee->id,
            'kind' => CommunicationMessage::KIND_MESSAGE,
            'body' => '선벨트 영수증입니다',
            'status' => 'active',
        ]);
    }

    // ── 방에 올린 파일이 문서함으로 들어가는가 ─────────────────────────

    public function test_a_photo_dropped_in_the_room_is_filed_into_the_document_hub(): void
    {
        Queue::fake(); // 분석은 큐로 넘어간다 — 여기서는 접수까지 확인한다.
        $message = $this->message($this->room());

        $attachment = app(ChatAttachmentService::class)->attach(
            $message,
            UploadedFile::fake()->image('receipt.jpg'),
            $this->user,
        );

        $this->assertNotNull($attachment);
        $this->assertSame(CommunicationMessageFile::KIND_IMAGE, $attachment->kind);

        $document = IntelligentDocument::query()->find($attachment->intelligent_document_id);
        $this->assertNotNull($document, '방에 올린 파일이 문서함으로 들어가지 않았습니다 — 같은 내용을 또 올려야 합니다.');
        $this->assertSame('chat', $document->source);
        $this->assertSame($this->site->id, $document->site_id, '문서가 그 방의 현장에 귀속되지 않았습니다.');
        $this->assertSame('queued', $document->ai_status);
        Storage::disk((string) $document->disk)->assertExists((string) $document->file_path);
    }

    public function test_the_same_file_twice_does_not_create_two_documents(): void
    {
        Queue::fake();
        $room = $this->room();

        $first = app(ChatAttachmentService::class)->attach($this->message($room), UploadedFile::fake()->image('same.jpg'), $this->user);
        $second = app(ChatAttachmentService::class)->attach($this->message($room), UploadedFile::fake()->image('same.jpg'), $this->user);

        $this->assertSame(2, CommunicationMessageFile::query()->count(), '첨부는 각각 남아야 합니다.');
        $this->assertSame(1, IntelligentDocument::query()->count(), '같은 파일이 문서함에 두 번 등록됐습니다.');
        $this->assertSame($first->intelligent_document_id, $second->intelligent_document_id);
    }

    public function test_a_private_conversation_never_reaches_the_company_hub(): void
    {
        // 1:1 대화는 회사 장부가 아니다. 이 선이 흐려지면 사람들이 메신저를 쓰지 않는다.
        Queue::fake();
        $message = $this->message($this->room(CommunicationRoom::TYPE_DIRECT));

        $attachment = app(ChatAttachmentService::class)->attach($message, UploadedFile::fake()->image('personal.jpg'), $this->user);

        $this->assertNotNull($attachment, '개인 대화에서도 첨부 자체는 남아야 합니다.');
        $this->assertNull($attachment->intelligent_document_id, '1:1 대화의 파일이 회사 문서함으로 넘어갔습니다.');
        $this->assertSame(0, IntelligentDocument::query()->count());
    }

    public function test_an_unsupported_file_still_stays_in_the_conversation(): void
    {
        Queue::fake();
        $message = $this->message($this->room());

        $attachment = app(ChatAttachmentService::class)->attach(
            $message,
            UploadedFile::fake()->create('malware.exe', 10),
            $this->user,
        );

        $this->assertNotNull($attachment, '문서함이 거부해도 대화의 첨부는 남아야 합니다.');
        $this->assertNull($attachment->intelligent_document_id);
    }

    // ── 화면(사람이 실제로 쓰는 길) ────────────────────────────────────

    public function test_a_member_can_post_a_photo_with_no_text_and_read_it_back(): void
    {
        Queue::fake();
        $room = $this->room();
        app(\App\Services\Communication\CommunicationService::class)->ensureRoomMember($room, $this->employee);

        $this->actingAs($this->user)
            ->post(route('communication.store', ['room' => $room]), [
                'files' => [UploadedFile::fake()->image('site.jpg')],
            ])
            ->assertRedirect(route('communication.show', ['room' => $room]));

        $attachment = CommunicationMessageFile::query()->firstOrFail();
        $this->assertSame('site.jpg', $attachment->original_name);

        // 방 사람은 열 수 있고, 남은 못 연다 — 영수증·급여 서류가 오가는 방이다.
        $this->actingAs($this->user)
            ->get(route('communication.file', ['room' => $room, 'file' => $attachment]))
            ->assertOk();

        $outsider = User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']);
        $this->actingAs($outsider)
            ->get(route('communication.file', ['room' => $room, 'file' => $attachment]))
            ->assertForbidden();
    }

    // ── 결과가 방으로 돌아오는가 ───────────────────────────────────────

    private function analyzedDocument(array $overrides = []): IntelligentDocument
    {
        return IntelligentDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'source' => 'chat',
            'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/'.Str::uuid().'/receipt.pdf',
            'original_file_name' => 'Sunbelt.pdf',
            'stored_file_name' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'title' => 'Sunbelt Rentals 186971353',
            'document_type' => 'receipt',
            'virtual_path' => '2026 / 자재·구매',
            'received_at' => now(),
            'ai_status' => 'ready',
        ], $overrides));
    }

    public function test_the_room_hears_back_where_the_document_went(): void
    {
        $room = $this->room();
        $parent = $this->message($room);
        $document = $this->analyzedDocument();

        CommunicationMessageFile::query()->create([
            'communication_message_id' => $parent->id,
            'intelligent_document_id' => $document->id,
            'original_name' => 'Sunbelt.pdf',
            'kind' => CommunicationMessageFile::KIND_DOCUMENT,
        ]);

        // 커넥터들이 이미 만들어 둔 결과 — 답글은 말이 아니라 이 기록을 읽고 쓴다.
        MobileExpense::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'employee_id' => $this->employee->id, 'payment_type' => 'corporate',
            'category' => '5401 Equipment Rental', 'accounting_account' => '5401 Equipment Rental',
            'description' => '[문서함] Sunbelt', 'amount' => 3185.77,
            'expense_date' => now()->toDateString(), 'status' => 'pending',
            'source_ref' => "document:{$document->id}",
        ]);
        Equipment::create([
            'equipment_code' => "DOC-{$document->id}",
            'equipment_type' => 'JCB 18Z Mini Excavator',
            'model' => 'JCB 18Z-1',
            'acquisition_type' => '임대',
            'status' => '사용중',
            'registration_method' => 'AI자동분석',
        ]);

        app(ChatDocumentReplyConnector::class)->sync($document);

        $reply = CommunicationMessage::query()
            ->where('parent_id', $parent->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->first();

        $this->assertNotNull($reply, '문서가 어디로 갔는지 방에 알려주지 않았습니다 — 사람들은 자동화를 믿지 않게 됩니다.');
        $this->assertStringContainsString('재무', $reply->body);
        $this->assertStringContainsString('3,185.77', $reply->body);
        $this->assertStringContainsString('승인대기', $reply->body);
        $this->assertStringContainsString('장비 대장', $reply->body);
        $this->assertSame(ChatDocumentReplyConnector::BOT_MARKER, $reply->payload['bot'] ?? null);
    }

    public function test_reanalysis_does_not_spam_the_room(): void
    {
        $room = $this->room();
        $parent = $this->message($room);
        $document = $this->analyzedDocument();
        CommunicationMessageFile::query()->create([
            'communication_message_id' => $parent->id,
            'intelligent_document_id' => $document->id,
            'original_name' => 'Sunbelt.pdf',
            'kind' => CommunicationMessageFile::KIND_DOCUMENT,
        ]);

        app(ChatDocumentReplyConnector::class)->sync($document);
        app(ChatDocumentReplyConnector::class)->sync($document);
        app(ChatDocumentReplyConnector::class)->sync($document);

        $this->assertSame(1, CommunicationMessage::query()
            ->where('parent_id', $parent->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->count(), '재분석할 때마다 방에 같은 답글이 쌓였습니다.');
    }

    public function test_a_document_that_did_not_come_from_chat_is_left_alone(): void
    {
        $document = $this->analyzedDocument(['source' => 'dropzone']);

        app(ChatDocumentReplyConnector::class)->sync($document);

        $this->assertSame(0, CommunicationMessage::query()->count());
    }

    public function test_a_disagreement_between_the_two_readings_is_said_out_loud(): void
    {
        $room = $this->room();
        $parent = $this->message($room);
        $document = $this->analyzedDocument([
            'ai_payload' => [
                'verification' => ['status' => 'disagreed', 'disagreements' => ['amount']],
            ],
        ]);
        CommunicationMessageFile::query()->create([
            'communication_message_id' => $parent->id,
            'intelligent_document_id' => $document->id,
            'original_name' => 'Sunbelt.pdf',
            'kind' => CommunicationMessageFile::KIND_DOCUMENT,
        ]);

        app(ChatDocumentReplyConnector::class)->sync($document);

        $body = (string) CommunicationMessage::query()->where('parent_id', $parent->id)->value('body');
        $this->assertStringContainsString('두 AI 판독이 다릅니다', $body);
        $this->assertStringContainsString('금액', $body);
    }

    // ── 창구가 하나인가 ────────────────────────────────────────────────

    public function test_chat_and_the_hub_share_one_intake(): void
    {
        // 접수 규칙이 두 벌이 되면 "문서함으로 올리면 중복이 걸러지는데 채팅으로
        // 올리면 두 번 등록되는" 상태가 된다.
        $chat = (string) file_get_contents(base_path('app/Services/Communication/ChatAttachmentService.php'));
        $this->assertStringContainsString('DocumentIntake', $chat);

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/DocumentIntelligenceController.php'));
        $this->assertStringContainsString('DocumentIntake', $controller,
            '문서함 업로드가 공용 접수 창구를 쓰지 않습니다.');

        $service = (string) file_get_contents(base_path('app/Services/Documents/DocumentIntelligenceService.php'));
        $this->assertStringContainsString('ChatDocumentReplyConnector', $service,
            '분석 완료 지점이 채팅 답글 커넥터를 부르지 않습니다 — 방은 결과를 영영 모릅니다.');
    }
}
