<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OpsIntakeItem;
use App\Models\ProcurementItem;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WbsItem;
use App\Services\Ops\OpsIntakeService;
use App\Services\Procurement\ProcurementDocAnalyzer;
use App\Services\Procurement\ProcurementService;
use App\Services\Vendors\VendorResolver;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProcurementAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $other;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['code' => 'BUY', 'name' => 'Buyer company', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'BUY-S', 'name' => 'Buyer site', 'status' => 'active']);
        $this->other = Site::create(['company_id' => $company->id, 'code' => 'BUY-X', 'name' => 'Other site', 'status' => 'active']);
        $this->buyer = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active',
            'access_scope' => 'site', 'allowed_site_id' => $this->site->id, 'purchase_buy_enabled' => true]);
        foreach ([$this->site, $this->other] as $site) {
            WbsItem::create(['project_code' => 'BUY-P', 'site_id' => $site->id, 'wbs_code' => $site->code,
                'name' => '자재 조달', 'level' => 'subtask', 'crew_size' => 0]);
        }
        Storage::fake('local');
        config(['filesystems.documents_disk' => 'public']);
    }

    private function callUpdate(string $code, array $patch): TestResponse
    {
        return $this->postJson('/smart-company-api/api_updateProcurement', ['siteId' => 'ALL', 'args' => ['BUY-P', $code, $patch]]);
    }

    private function po(Site $site): ProcurementItem
    {
        return ProcurementItem::create(['project_code' => 'BUY-P', 'wbs_code' => $site->code, 'site_id' => $site->id,
            'status' => '발주완료', 'po_no' => 'PO-'.$site->code, 'amount' => 99, 'eta' => '2026-10-05',
            'document_disk' => 'local', 'document_path' => 'procurement-docs/test.pdf', 'document_name' => 'Order.pdf']);
    }

    public function test_request_permission_does_not_allow_legacy_order_updates_or_document_analysis(): void
    {
        $this->buyer->forceFill(['purchase_buy_enabled' => false, 'purchase_request_enabled' => true])->save();
        $this->actingAsPurchaseUser($this->buyer);
        $this->callUpdate($this->site->code, ['status' => '발주완료', 'amount' => 999])->assertJson(['success' => false, 'code' => 403]);
        $this->postJson('/procurement-api/analyze', ['site_id' => $this->site->id])->assertForbidden();
        $this->assertDatabaseCount('procurement_items', 0);
    }

    public function test_buyer_grant_is_scoped_and_device_only_session_cannot_purchase(): void
    {
        $this->actingAsPurchaseUser($this->buyer);
        $this->callUpdate($this->other->code, ['amount' => 50])->assertJson(['success' => false, 'code' => 403]);
        $this->callUpdate($this->site->code, ['status' => '발주완료', 'amount' => 50])->assertOk()->assertJson(['success' => true]);
        $this->withSession([WorkerDeviceSession::FLAG => true]);
        $this->callUpdate($this->site->code, ['amount' => 100])->assertJson(['success' => false, 'code' => 403]);
        $this->assertSame(50.0, (float) ProcurementItem::firstOrFail()->amount);
    }

    public function test_service_does_not_trust_an_absent_actor_or_another_users_id(): void
    {
        try {
            app(ProcurementService::class)->update('BUY-P', $this->site->code, ['amount' => 50]);
            $this->fail('An anonymous console invocation was treated as a buyer.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $requester = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($requester);
        $this->expectException(HttpException::class);
        app(ProcurementService::class)->update('BUY-P', $this->site->code, ['amount' => 50], 'ALL', $this->buyer->id);
    }

    public function test_readers_keep_scoped_eta_but_cannot_read_purchase_financials_or_documents(): void
    {
        $own = $this->po($this->site);
        $this->po($this->other);
        $this->buyer->forceFill(['access_role' => 'site_manager', 'purchase_buy_enabled' => false])->save();
        $this->actingAsPurchaseUser($this->buyer);
        $response = $this->postJson('/smart-company-api/api_getProcurement', ['siteId' => 'ALL', 'args' => ['BUY-P']]);
        $response->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.eta', '2026-10-05')
            ->assertJsonPath('items.0.amount', null)->assertJsonPath('items.0.documentUrl', null)->assertJsonPath('contracts', []);
        $this->get('/procurement-api/file/'.$own->id)->assertForbidden();
    }

    public function test_buyer_cannot_open_another_sites_purchase_document_or_attach_arbitrary_disk_paths(): void
    {
        $own = $this->po($this->site);
        $foreign = $this->po($this->other);
        Storage::disk('local')->put('procurement-docs/test.pdf', '%PDF');
        Storage::disk('local')->put('mail/private.pdf', 'private mail');
        $this->actingAsPurchaseUser($this->buyer);
        $this->get('/procurement-api/file/'.$own->id)->assertOk();
        $this->get('/procurement-api/file/'.$foreign->id)->assertForbidden();
        $this->callUpdate($this->site->code, ['document_disk' => 'local', 'document_path' => 'mail/private.pdf'])->assertJson(['success' => false, 'code' => 403]);
        $this->assertSame('procurement-docs/test.pdf', $own->fresh()->document_path);
    }

    public function test_scoped_read_only_viewer_keeps_operational_procurement_visibility(): void
    {
        $this->po($this->site);
        $this->po($this->other);
        $viewer = User::factory()->create(['access_role' => 'viewer', 'account_status' => 'active',
            'access_scope' => 'site', 'allowed_site_id' => $this->site->id]);
        $this->actingAsPurchaseUser($viewer)->postJson('/smart-company-api/api_getProcurement', ['siteId' => 'ALL', 'args' => ['BUY-P']])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.eta', '2026-10-05')
            ->assertJsonPath('items.0.amount', null)->assertJsonPath('canBuy', false);
    }

    public function test_document_analysis_stores_private_evidence_owned_by_actor_and_site(): void
    {
        $this->mock(ProcurementDocAnalyzer::class)->shouldReceive('analyze')->once()->andReturn(['vendor' => 'Supplier']);
        $this->actingAsPurchaseUser($this->buyer);
        $response = $this->post('/procurement-api/analyze', ['site_id' => $this->site->id,
            'file' => UploadedFile::fake()->create('order.pdf', 1, 'application/pdf')], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('file.disk', 'local');
        $path = $response->json('file.path');
        $this->assertStringStartsWith("procurement-docs/{$this->buyer->id}/{$this->site->id}/", $path);
        Storage::disk('local')->assertExists($path);
        $this->callUpdate($this->site->code, ['document_disk' => 'local', 'document_path' => $path, 'document_name' => 'order.pdf'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_foreign_site_contract_cannot_be_attached_to_a_permitted_wbs(): void
    {
        $contract = ProjectContract::create(['site_id' => $this->other->id, 'direction' => 'payable', 'status' => 'active', 'title' => 'Foreign purchase']);
        $this->actingAsPurchaseUser($this->buyer);
        $this->callUpdate($this->site->code, ['contract_id' => $contract->id, 'vendor' => 'Should never be created'])->assertJson(['success' => false, 'code' => 403]);
        $this->assertDatabaseCount('procurement_items', 0);
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_vendor_resolution_does_not_link_a_same_named_company_private_vendor(): void
    {
        $foreign = Company::create(['code' => 'OTHER-BUY', 'name' => 'Other company', 'status' => 'active']);
        $secret = Vendor::create(['company_id' => $foreign->id, 'name' => 'Supplier', 'status' => 'active']);
        $vendor = app(VendorResolver::class)->resolve('Supplier', $this->site->company_id);
        $this->assertNotSame($secret->id, $vendor->id);
        $this->assertSame($this->site->company_id, $vendor->company_id);
    }

    public function test_automatic_analysis_cannot_apply_procurement_even_when_the_submitter_is_a_buyer(): void
    {
        $po = $this->po($this->site);
        $proposal = OpsIntakeItem::create(['site_id' => $this->site->id, 'project_code' => 'BUY-P', 'source' => 'manual',
            'raw_text' => '입고 일정 변경',
            'category' => 'procurement', 'target_type' => 'procurement', 'target_code' => $po->po_no,
            'status' => 'pending', 'proposed' => ['eta' => '2026-11-01', 'status' => '입고완료']]);
        $this->actingAsPurchaseUser($this->buyer);
        foreach ([OpsIntakeItem::VIA_AUTO, OpsIntakeItem::VIA_REPORT] as $via) {
            $result = app(OpsIntakeService::class)->apply($proposal->id, null, $this->buyer->id, $via);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('구매 담당자', $result['error']);
            $this->assertSame('pending', $proposal->fresh()->status);
            $this->assertSame('발주완료', $po->fresh()->status);
        }
        $result = app(OpsIntakeService::class)->apply($proposal->id, ['eta' => '2026-11-01'], $this->buyer->id);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('2026-11-01', $po->fresh()->eta->toDateString());
    }
}
