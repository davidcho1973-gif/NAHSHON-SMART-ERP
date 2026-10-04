<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\CommunicationNotification;
use App\Models\Company;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\PurchaseReceiptAllocation;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestAttachment;
use App\Models\PurchaseRequestEvent;
use App\Models\PurchaseRequestOrder;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Support\PurchaseAccess;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    private Site $otherSite;

    private User $requester;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        config(['filesystems.wbs_photos_disk' => 'local']);
        $this->company = Company::create(['code' => 'PR-CO', 'name' => 'Purchase Co', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'PR-1', 'name' => 'Purchase site', 'status' => 'active']);
        $this->otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'PR-2', 'name' => 'Other site', 'status' => 'active']);
        $this->requester = $this->user();
        $this->buyer = $this->user(['purchase_request_enabled' => false, 'purchase_buy_enabled' => true, 'access_role' => 'admin']);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => 'site_manager', 'account_status' => 'active', 'access_scope' => 'site',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id,
            'purchase_request_enabled' => true, 'purchase_buy_enabled' => false,
        ], $attributes));
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['site_id' => $this->site->id, 'need_by' => '2026-10-03', 'request_key' => (string) Str::uuid(),
            'lines' => [['name' => 'Copper elbow', 'specification' => '3/4 inch', 'quantity' => 10, 'unit' => 'EA', 'product_url' => 'https://example.com/elbow']]], $extra);
    }

    public function test_unknown_product_quantity_can_be_requested_but_must_be_resolved_before_ordering(): void
    {
        $row = $this->createRequest(['lines' => [['name' => '배관 연결 작업용 자재', 'quantity' => null, 'unit' => null]]]);
        $this->assertNull($row['lines'][0]['quantity']);
        $this->assertSame('submitted', $row['status']);
        $this->actingAsPurchaseUser($this->buyer)->getJson('/purchase-requests?desk=1')->assertOk()->assertJsonPath('rows.0.status', 'submitted');
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'order', 'version' => $row['version']])->assertUnprocessable();
        $confirmed = $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'resolve', 'version' => $row['version'],
            'lines' => [['name' => '엘보', 'quantity' => 20, 'unit' => 'EA', 'specification' => '승인 규격 확인']]])
            ->assertOk()->json('request');
        $this->assertEquals(20, $confirmed['lines'][0]['quantity']);
        $this->assertSame('submitted', $confirmed['status']);
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests/'.$row['id'].'/action',
            ['action' => 'resolve', 'version' => $confirmed['version'], 'lines' => [['name' => 'x', 'quantity' => 1, 'unit' => 'EA']]])->assertForbidden();
    }

    public function test_supplier_confirmation_is_explicit_and_a_changed_delivery_date_requires_reconfirmation(): void
    {
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action',
            ['action' => 'supplier_confirm', 'version' => $row['version'], 'eta' => '2026-10-05', 'note' => 'Confirmed'])->assertUnprocessable();
        $ordered = $this->order($this->evidence($row));
        $this->assertSame('ordered', $ordered['status']);
        $confirmed = $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'supplier_confirm',
            'version' => $ordered['version'], 'eta' => '2026-10-05', 'note' => 'Supplier confirmed first truck at 07:00'])->assertOk()->json('request');
        $this->assertSame('supplier_confirmed', $confirmed['status']);
        $this->getJson('/purchase-requests?desk=1&status=supplier_confirmed')->assertOk()->assertJsonPath('rows.0.status', 'supplier_confirmed');
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'eta', 'version' => $confirmed['version'],
            'eta' => '2026-10-06'])->assertOk()->assertJsonPath('request.status', 'ordered');
    }

    public function test_inquiry_email_is_scoped_selective_and_idempotent(): void
    {
        config(['mail.default' => 'smtp']);
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->buyer);
        $payload = ['to' => 'vendor@example.test', 'subject' => 'Quote request', 'body' => 'Please quote',
            'attachment_ids' => [], 'request_key' => (string) Str::uuid()];
        $this->postJson('/purchase-requests/'.$row['id'].'/email', $payload)->assertOk()->assertJsonPath('delivery', 'queued');
        $this->postJson('/purchase-requests/'.$row['id'].'/email', $payload)->assertOk()->assertJsonPath('replayed', true);
        Bus::assertDispatched(\App\Jobs\SendPurchaseInquiry::class, 1);
        Bus::assertDispatched(\App\Jobs\SendPurchaseInquiry::class, fn ($job) => $job->connection === 'document-analysis' && $job->queue === 'purchases');
        $this->postJson('/purchase-requests/'.$row['id'].'/email', array_merge($payload, ['body' => 'Changed']))->assertConflict();
        $this->postJson('/purchase-requests/'.$row['id'].'/email', array_merge($payload,
            ['request_key' => (string) Str::uuid(), 'attachment_ids' => [999999]]))->assertUnprocessable();
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests/'.$row['id'].'/email', $payload)->assertForbidden();
    }

    public function test_inquiry_worker_sends_selected_materials_once_and_rechecks_permissions(): void
    {
        config(['mail.default' => 'smtp']);
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->buyer);
        $file = $this->postJson('/purchase-requests/'.$row['id'].'/attachments', ['purpose' => 'request',
            'file' => UploadedFile::fake()->createWithContent('drawing.pdf', "%PDF-1.4\nselected drawing")])->assertOk()->json('attachment');
        $payload = ['to' => 'vendor@example.test', 'subject' => 'Quote request', 'body' => 'Please quote',
            'attachment_ids' => [$file['id']], 'request_key' => (string) Str::uuid()];
        $this->postJson('/purchase-requests/'.$row['id'].'/email', $payload)->assertOk();
        $event = PurchaseRequestEvent::where('purchase_request_id', $row['id'])->where('action', 'email')->firstOrFail();
        \Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback): void {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email);
            $callback($message);
            $this->assertSame('Please quote', $body);
            $this->assertSame('vendor@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertCount(1, $message->getSymfonyMessage()->getAttachments());
        });
        $job = new \App\Jobs\SendPurchaseInquiry($event->id);
        $job->handle(app(\App\Services\Procurement\PurchaseRequestService::class));
        $this->assertSame('sent', $event->fresh()->data['delivery']);
        $job->handle(app(\App\Services\Procurement\PurchaseRequestService::class));
        $this->postJson('/purchase-requests/'.$row['id'].'/email', array_merge($payload, ['request_key' => (string) Str::uuid()]))->assertOk();
        $second = PurchaseRequestEvent::where('purchase_request_id', $row['id'])->where('action', 'email')->latest('id')->firstOrFail();
        $this->buyer->forceFill(['purchase_buy_enabled' => false])->save();
        (new \App\Jobs\SendPurchaseInquiry($second->id))->handle(app(\App\Services\Procurement\PurchaseRequestService::class));
        $this->assertSame('failed', $second->fresh()->data['delivery']);
    }

    private function createRequest(array $extra = []): array
    {
        return $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests', $this->payload($extra))
            ->assertOk()->assertJsonPath('success', true)->json('request');
    }

    private function evidence(array $row): array
    {
        return $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/attachments', [
            'purpose' => 'order', 'file' => UploadedFile::fake()->createWithContent('order.pdf', "%PDF-1.4\norder proof"),
        ])->assertOk()->json('request');
    }

    private function order(array $row, array $extra = []): array
    {
        $row = $this->evidence($row);

        return $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', array_merge([
            'action' => 'order', 'version' => $row['version'], 'request_key' => (string) Str::uuid(),
            'vendor' => 'Supplier', 'order_number' => 'PO-'.Str::random(8), 'amount' => 125.50, 'currency' => 'USD',
        ], $extra))->assertOk()->json('request');
    }

    private function receipt(float $qty = 10, array $extra = []): MaterialReceipt
    {
        $receipt = MaterialReceipt::create(array_merge(['company_id' => $this->company->id, 'site_id' => $this->site->id,
            'status' => 'confirmed', 'received_on' => '2026-10-03', 'vendor' => 'Supplier', 'confirmed_by_id' => $this->buyer->id, 'confirmed_at' => now()], $extra));
        $receipt->lines()->create(['name' => 'Copper elbow', 'quantity' => $qty, 'unit' => 'EA']);

        return $receipt;
    }

    private function receive(array $row, MaterialReceipt $receipt, float $qty)
    {
        return $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', [
            'action' => 'receive', 'version' => $row['version'], 'receipt_id' => $receipt->id,
            'allocations' => [['request_line_id' => $row['lines'][0]['id'], 'receipt_line_id' => $receipt->lines()->first()->id, 'quantity' => $qty]],
        ]);
    }

    public function test_explicit_grant_is_required_and_revocation_is_immediate(): void
    {
        foreach (['admin', 'site_manager', 'worker', 'foreman', 'vendor_admin', 'viewer'] as $role) {
            $user = $this->user(['access_role' => $role, 'purchase_request_enabled' => in_array($role, ['worker', 'foreman', 'vendor_admin', 'viewer'], true)]);
            $this->actingAsPurchaseUser($user)->postJson('/purchase-requests', $this->payload())->assertForbidden();
        }
        $row = $this->createRequest();
        $this->requester->forceFill(['purchase_request_enabled' => false])->save();
        $this->actingAsPurchaseUser($this->requester)->getJson('/purchase-requests/'.$row['id'])->assertForbidden();
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_requester_and_buyer_site_boundaries_are_checked_on_server(): void
    {
        $row = $this->createRequest();
        $other = $this->user();
        $this->actingAsPurchaseUser($other)->getJson('/purchase-requests/'.$row['id'])->assertNotFound();
        $this->getJson('/purchase-requests')->assertOk()->assertJsonCount(0, 'rows');
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests', $this->payload(['site_id' => $this->otherSite->id]))->assertForbidden();
        $this->buyer->forceFill(['allowed_site_id' => $this->otherSite->id])->save();
        $this->actingAsPurchaseUser($this->buyer)->getJson('/purchase-requests/'.$row['id'])->assertNotFound();
        $this->getJson('/purchase-requests?desk=1')->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_super_admin_can_request_without_a_separate_grant_but_suspension_still_blocks_access(): void
    {
        $owner = $this->user(['access_role' => 'super_admin', 'purchase_request_enabled' => false]);
        $this->actingAsPurchaseUser($owner)->get('/attendance-app?tab=home')
            ->assertOk()->assertSee('/attendance-app/purchase-requests', false);
        $this->postJson('/purchase-requests', $this->payload())->assertOk();
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->assertFalse(PurchaseAccess::canRequest($owner));
    }

    public function test_requester_cannot_process_purchase_and_phone_only_buyer_cannot_execute(): void
    {
        $row = $this->createRequest();
        $action = ['action' => 'review', 'version' => $row['version']];
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertForbidden();
        $this->actingAsPurchaseUser($this->buyer)->withSession([WorkerDeviceSession::FLAG => true])->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertForbidden();
        $this->getJson('/purchase-requests?desk=1')->assertForbidden();
        $this->assertSame('submitted', PurchaseRequest::find($row['id'])->status);
    }

    public function test_create_retries_are_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        $payload = $this->payload();
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests', $payload)->assertOk();
        $this->postJson('/purchase-requests', $payload)->assertOk()->assertJsonPath('replayed', true);
        $payload['lines'][0]['quantity'] = 50;
        $this->postJson('/purchase-requests', $payload)->assertStatus(409);
        $this->assertSame(1, PurchaseRequest::count());
        $this->assertSame(1, PurchaseRequestEvent::where('action', 'submit')->count());
    }

    public function test_reason_buttons_send_one_private_notification_and_ignore_no_op(): void
    {
        $row = $this->createRequest();
        $action = ['action' => 'hold', 'version' => $row['version'], 'reason' => 'budget', 'request_key' => (string) Str::uuid()];
        $result = $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertOk()->json('request');
        $this->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'hold', 'version' => $result['version'], 'reason' => 'budget'])->assertOk()->assertJsonPath('unchanged', true);
        $notifications = CommunicationNotification::where('user_id', $this->requester->id)->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('예산 확인', $notifications->first()->body);
        $this->assertStringContainsString('request='.$row['id'], $notifications->first()->action_url);
        $this->assertSame(1, PurchaseRequestEvent::where('action', 'hold')->count());
    }

    public function test_stale_version_and_missing_reason_cannot_overwrite_another_change(): void
    {
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'hold', 'version' => $row['version']])->assertUnprocessable();
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'review', 'version' => $row['version']])->assertOk();
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'hold', 'version' => $row['version'], 'reason' => 'budget'])->assertStatus(409);
        $this->assertSame('reviewing', PurchaseRequest::find($row['id'])->status);
    }

    public function test_order_requires_real_private_evidence_and_requester_never_receives_costs(): void
    {
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', [
            'action' => 'order', 'version' => $row['version'], 'vendor' => 'Supplier', 'order_number' => 'PO-NOPROOF',
        ])->assertUnprocessable();
        $this->assertSame(0, PurchaseRequestOrder::count());
        $ordered = $this->order($row);
        $this->assertSame('ordered', $ordered['status']);
        $this->assertSame(125.5, $ordered['amount']);
        $own = $this->actingAsPurchaseUser($this->requester)->getJson('/purchase-requests/'.$row['id'])->assertOk()->json('request');
        $this->assertArrayNotHasKey('amount', $own);
        $this->assertArrayNotHasKey('amount', $own['orders'][0]);
        $this->assertCount(0, $own['attachments']);
        $file = PurchaseRequestAttachment::first();
        $this->get('/purchase-requests/'.$row['id'].'/attachments/'.$file->id.'/download')->assertNotFound();
        $this->assertSame(0, MobileExpense::count(), 'An order is not a paid expense or a receipt.');
    }

    public function test_partial_purchase_does_not_mark_unordered_quantities_as_purchased(): void
    {
        $row = $this->createRequest();
        $partial = $this->order($row, ['order_lines' => [['request_line_id' => $row['lines'][0]['id'], 'quantity' => 4]]]);
        $this->assertSame('partially_ordered', $partial['status']);
        $this->assertSame(4.0, (float) $partial['lines'][0]['ordered_quantity']);
        $this->assertSame(6.0, (float) $partial['lines'][0]['remaining_to_order']);
        $this->postJson('/purchase-requests/'.$row['id'].'/action', [
            'action' => 'order', 'version' => $partial['version'], 'vendor' => 'Supplier', 'order_number' => 'SECOND-PO',
        ])->assertUnprocessable();
        $this->assertSame(1, PurchaseRequestOrder::count(), 'A second order must not silently borrow the first order evidence.');
        $this->getJson('/purchase-requests?desk=1&status=partially_ordered')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $row['id']);
        $receipt = $this->receipt();
        $this->receive($partial, $receipt, 5)->assertUnprocessable();
        $this->receive($partial, $receipt, 4)->assertOk()->assertJsonPath('request.status', 'partial');
    }

    public function test_confirmed_receipts_support_partial_receiving_without_a_second_ledger(): void
    {
        $ordered = $this->order($this->createRequest());
        $receipt = $this->receipt();
        $partial = $this->receive($ordered, $receipt, 4)->assertOk()->assertJsonPath('request.status', 'partial')->json('request');
        $complete = $this->receive($partial, $receipt, 6)->assertOk()->assertJsonPath('request.status', 'received')->json('request');
        $this->assertEquals(10, $complete['lines'][0]['received_quantity']);
        $this->assertSame(1, MaterialReceipt::count());
        $this->assertEquals(10, PurchaseReceiptAllocation::sum('quantity'));
        $this->assertSame(0, MobileExpense::count());
        $receipt->forceFill(['status' => 'draft', 'confirmed_at' => null])->save();
        $this->getJson('/purchase-requests/'.$ordered['id'])->assertOk()->assertJsonPath('request.status', 'ordered')->assertJsonPath('request.lines.0.received_quantity', 0);
    }

    public function test_draft_other_site_and_reused_receipt_quantities_are_rejected(): void
    {
        $ordered = $this->order($this->createRequest());
        $draft = $this->receipt(extra: ['status' => 'draft']);
        $this->receive($ordered, $draft, 1)->assertUnprocessable();
        $foreign = $this->receipt(extra: ['site_id' => $this->otherSite->id]);
        $this->receive($ordered, $foreign, 1)->assertUnprocessable();
        $receipt = $this->receipt();
        $this->receive($ordered, $receipt, 10)->assertOk();
        $second = $this->order($this->createRequest());
        $this->receive($second, $receipt, 1)->assertUnprocessable();
        $this->assertEquals(10, PurchaseReceiptAllocation::sum('quantity'));
    }

    public function test_temporarily_reversed_receipt_cannot_create_double_fulfilment(): void
    {
        $ordered = $this->order($this->createRequest());
        $receipt = $this->receipt(4);
        $partial = $this->receive($ordered, $receipt, 4)->assertOk()->json('request');
        $receipt->update(['status' => 'draft']);
        $secondReceipt = $this->receipt(10);
        $this->receive($partial, $secondReceipt, 10)->assertUnprocessable();
        $this->assertEquals(4, PurchaseReceiptAllocation::sum('quantity'));
    }

    public function test_requester_can_only_clarify_own_requested_information(): void
    {
        $row = $this->createRequest();
        $needs = $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', [
            'action' => 'needs_info', 'version' => $row['version'], 'reason' => 'size',
        ])->assertOk()->json('request');
        $action = ['action' => 'clarify', 'version' => $needs['version'], 'note' => '3/4 inch copper sweat fitting'];
        $this->actingAsPurchaseUser($this->user())->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertNotFound();
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertOk()->assertJsonPath('request.status', 'submitted');
        $this->assertSame(1, PurchaseRequestEvent::where('action', 'clarify')->count());
    }

    public function test_request_attachment_and_analysis_provenance_are_owner_scoped(): void
    {
        $path = 'purchase-analysis/'.$this->requester->id.'/drawing.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 drawing');
        $job = AiJob::create(['user_id' => $this->requester->id, 'company_id' => $this->company->id,
            'kind' => 'purchase_draft', 'status' => 'done', 'params' => ['site_id' => $this->site->id, 'mode' => 'request',
                'source' => ['disk' => 'local', 'path' => $path, 'name' => 'drawing.pdf', 'mime' => 'application/pdf']]]);
        $row = $this->createRequest(['analysis_job_id' => $job->id]);
        $this->assertCount(1, $row['attachments']);
        $this->assertArrayNotHasKey('path', $row['attachments'][0]);
        $this->get($row['attachments'][0]['download_url'])->assertOk();
        $this->actingAsPurchaseUser($this->user())->postJson('/purchase-requests', $this->payload(['analysis_job_id' => $job->id]))->assertUnprocessable();
        $this->assertSame(1, PurchaseRequest::count(), 'Invalid AI provenance must roll back the request.');
    }

    public function test_disguised_executable_and_foreign_order_attachment_are_rejected(): void
    {
        $row = $this->createRequest();
        $this->actingAsPurchaseUser($this->requester)->postJson('/purchase-requests/'.$row['id'].'/attachments', [
            'purpose' => 'request', 'file' => UploadedFile::fake()->createWithContent('proof.html', '<script>alert(1)</script>'),
        ])->assertUnprocessable();
        $this->postJson('/purchase-requests/'.$row['id'].'/attachments', [
            'purpose' => 'order', 'file' => UploadedFile::fake()->createWithContent('order.pdf', '%PDF-1.4'),
        ])->assertForbidden();
        $other = $this->evidence($this->createRequest());
        $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', [
            'action' => 'order', 'version' => $row['version'], 'vendor' => 'Supplier', 'order_number' => 'PO-X', 'evidence_id' => $other['attachments'][0]['id'],
        ])->assertUnprocessable();
    }

    public function test_quote_ai_cannot_confirm_order_but_verified_order_analysis_can_supply_evidence(): void
    {
        $row = $this->createRequest();
        $path = 'purchase-analysis/'.$this->buyer->id.'/quote.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 quote');
        $job = AiJob::create(['user_id' => $this->buyer->id, 'company_id' => $this->company->id,
            'kind' => 'purchase_draft', 'status' => 'done', 'result' => ['doc_kind' => 'quote'],
            'params' => ['site_id' => $this->site->id, 'mode' => 'order', 'source' => ['disk' => 'local', 'path' => $path, 'name' => 'quote.pdf', 'mime' => 'application/pdf']]]);
        $action = ['action' => 'order', 'version' => $row['version'], 'vendor' => 'Supplier', 'order_number' => 'PO-AI', 'analysis_job_id' => $job->id];
        $this->actingAsPurchaseUser($this->buyer)->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertUnprocessable();
        $this->assertSame(0, PurchaseRequestOrder::count());
        $this->assertSame(0, PurchaseRequestAttachment::count());
        $job->update(['result' => ['doc_kind' => 'order_confirmation']]);
        $this->postJson('/purchase-requests/'.$row['id'].'/action', $action)->assertOk()->assertJsonPath('request.status', 'ordered');
        $this->assertSame(1, PurchaseRequestAttachment::count());
    }

    public function test_order_links_vendor_master_and_cancel_requires_supplier_confirmation(): void
    {
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name' => 'Supplier', 'status' => 'active']);
        $row = $this->order($this->createRequest());
        $this->assertSame($vendor->id, PurchaseRequestOrder::first()->vendor_id);
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'cancel', 'version' => $row['version'], 'reason' => 'no_longer_needed'])->assertUnprocessable();
        $this->postJson('/purchase-requests/'.$row['id'].'/action', ['action' => 'cancel', 'version' => $row['version'], 'reason' => 'supplier_cancelled'])->assertOk()->assertJsonPath('request.status', 'cancelled');
        $this->assertSame(1, PurchaseRequestOrder::count(), 'Cancelling never erases the original order evidence.');
        $this->assertSame(0, MobileExpense::count(), 'Cancellation does not claim a refund or a payment.');
    }

    public function test_an_old_pending_request_is_not_hidden_behind_recent_closed_rows(): void
    {
        $pending = $this->createRequest();
        PurchaseRequest::whereKey($pending['id'])->update(['updated_at' => now()->subMonth()]);
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['company_id' => $this->company->id, 'site_id' => $this->site->id,
                'requested_by_id' => $this->requester->id, 'request_key' => 'closed-'.$i, 'request_fingerprint' => hash('sha256', 'closed-'.$i),
                'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('purchase_requests')->insert($rows);
        $this->actingAsPurchaseUser($this->buyer)->getJson('/purchase-requests?desk=1')->assertOk()->assertJsonPath('rows.0.id', $pending['id'])->assertJsonPath('has_more', true);
        $this->getJson('/purchase-requests?desk=1&status=submitted')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $pending['id']);
    }
}
