<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\DeliverySlipAnalyzer;
use App\Support\WorkerDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileMaterialReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/attendance-app/material-receipts';

    private Company $company;

    private Site $site;

    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.wbs_photos_disk' => 'local']);
        Storage::fake('local');
        $this->company = Company::create(['code' => 'MRC', 'name' => 'Receiving Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'MR-703K', 'name' => 'Receiving Site', 'status' => 'active',
        ]);
        $this->otherSite = Site::create([
            'company_id' => $this->company->id, 'code' => 'MR-OTHER', 'name' => 'Another Site', 'status' => 'active',
        ]);
    }

    private function manager(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'account_status' => 'active', 'access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id,
        ], $overrides));
        $this->actingAs($user);

        return $user;
    }

    private function employee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'first_name' => 'Field', 'last_name' => 'Manager', 'employment_status' => 'active',
            'attendance_app_role' => 'foreman',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_id' => $this->site->id, 'received_on' => '2026-09-22', 'vendor' => 'Graybar',
            'delivery_no' => 'DN-7', 'po_no' => 'PO-3', 'note' => 'Partial delivery checked at unloading',
            'request_key' => (string) Str::uuid(),
            'lines' => [
                ['name' => 'EMT 1/2 inch', 'quantity' => 12.5, 'unit' => 'LF', 'unit_price' => 3.2],
                ['name' => 'Strut clamps', 'quantity' => 24, 'unit' => 'EA'],
            ],
        ], $overrides);
    }

    private function upload(bool $analyze = false): array
    {
        $response = $this->postJson(self::URL.'/upload', [
            'site_id' => $this->site->id, 'analyze' => $analyze,
            'file' => UploadedFile::fake()->image('delivery-photo.jpg'),
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertIsString($response->json('file.token'));
        $this->assertSame('delivery-photo.jpg', $response->json('file.name'));
        $this->assertArrayNotHasKey('path', $response->json('file'));
        $this->assertArrayNotHasKey('disk', $response->json('file'));

        return $response->json();
    }

    public function test_receiving_requires_login_and_stronger_than_device_only_authentication(): void
    {
        $this->get(self::URL)->assertRedirect(route('worker-app.entry'));
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();

        $this->manager();
        $this->withSession([WorkerDeviceSession::FLAG => true])->getJson(self::URL.'/items')
            ->assertForbidden()->assertJsonPath('code', 'pin_required');
        $this->postJson(self::URL, $this->payload())->assertForbidden()->assertJsonPath('code', 'pin_required');
        $this->assertDatabaseCount('material_receipts', 0);
    }

    public function test_unlinked_manager_can_open_receiving_from_worker_app_home(): void
    {
        $this->manager();
        $this->get('/attendance-app')->assertOk()->assertViewHas('canReceiveMaterials', true)
            ->assertSee(self::URL, false);
        $this->get(self::URL)->assertOk();
    }

    public function test_device_only_session_cannot_bypass_pin_through_legacy_receiving_adapter(): void
    {
        $this->manager();
        $id = $this->postJson(self::URL, $this->payload())->assertOk()->json('id');
        $this->withSession([WorkerDeviceSession::FLAG => true]);

        foreach ([
            'api_getMaterialReceipts' => [],
            'api_saveMaterialReceipt' => [$this->payload()],
            'api_confirmMaterialReceipt' => [$id],
            'api_deleteMaterialReceipt' => [$id],
        ] as $method => $args) {
            $this->postJson('/smart-company-api/'.$method, [
                'siteId' => (string) $this->site->id, 'args' => $args,
            ])->assertJsonPath('success', false);
        }
        $this->postJson('/material-receipt-api/analyze', [
            'site_id' => $this->site->id, 'file' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertForbidden()->assertJsonPath('code', 'pin_required');
        $this->getJson(route('material-receipts.file', $id))
            ->assertForbidden()->assertJsonPath('code', 'pin_required');

        $this->assertDatabaseCount('material_receipts', 1);
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, MaterialReceipt::findOrFail($id)->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_worker_viewer_and_inactive_accounts_cannot_use_receiving(): void
    {
        foreach ([
            ['access_role' => 'worker', 'access_scope' => 'all_sites'],
            ['access_role' => 'viewer', 'employee_id' => $this->employee()->id],
            ['account_status' => 'pending'],
        ] as $overrides) {
            $this->manager($overrides);
            $this->get(self::URL)->assertForbidden();
            $this->getJson(self::URL.'/items')->assertForbidden();
            $this->postJson(self::URL, $this->payload())->assertForbidden();
            $this->postJson(self::URL.'/upload', [
                'site_id' => $this->site->id, 'file' => UploadedFile::fake()->image('denied.jpg'),
            ])->assertForbidden();
        }
        $this->assertDatabaseCount('material_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_employee_supervisor_can_receive_only_for_assigned_site_even_with_all_sites_flag(): void
    {
        $this->manager([
            'access_role' => 'worker', 'access_scope' => 'all_sites', 'employee_id' => $this->employee()->id,
        ]);
        $this->get(self::URL)->assertOk();
        $this->postJson(self::URL, $this->payload())->assertSuccessful()->assertJsonPath('success', true);
        $this->postJson(self::URL, $this->payload(['site_id' => $this->otherSite->id]))->assertForbidden();
        $response = $this->getJson(self::URL.'/items')->assertOk();
        $this->assertSame([$this->site->id], array_column($response->json('sites'), 'value'));
    }

    public function test_manager_preview_remains_read_only(): void
    {
        $employee = $this->employee();
        $this->manager(['access_role' => 'super_admin', 'access_scope' => 'all_sites']);
        $this->get('/attendance-app?as='.$employee->id)->assertOk()->assertViewHas('canReceiveMaterials', false)
            ->assertDontSee('href="'.url(self::URL).'"', false);
        $this->postJson(self::URL.'?as='.$employee->id, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('material_receipts', 0);
    }

    public function test_camera_photo_upload_retains_proof_without_ai_or_creating_an_empty_receipt(): void
    {
        $this->manager();
        $this->mock(DeliverySlipAnalyzer::class)->shouldNotReceive('analyze');
        $upload = $this->upload();

        $this->assertDatabaseCount('material_receipts', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $id = $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))
            ->assertSuccessful()->assertJsonPath('success', true)->json('id');
        $receipt = MaterialReceipt::findOrFail($id);
        $this->assertSame('local', $receipt->photo_disk);
        Storage::disk('local')->assertExists($receipt->photo_path);
        $this->get(route('material-receipts.file', $receipt))->assertOk();
    }

    public function test_failed_optional_ai_keeps_photo_and_allows_manual_receipt(): void
    {
        $this->manager();
        $this->mock(DeliverySlipAnalyzer::class)->shouldReceive('analyze')->once()
            ->andThrow(new \RuntimeException('AI service unavailable'));
        $upload = $this->upload(true);

        $this->assertNotEmpty($upload['warning'] ?? null);
        $this->assertDatabaseCount('material_receipts', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $id = $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))
            ->assertSuccessful()->assertJsonPath('success', true)->json('id');
        $receipt = MaterialReceipt::findOrFail($id);
        Storage::disk('local')->assertExists($receipt->photo_path);
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, $receipt->status);
    }

    public function test_ai_suggestions_wait_for_explicit_user_save_and_confirmation(): void
    {
        $this->manager();
        $this->mock(DeliverySlipAnalyzer::class)->shouldReceive('analyze')->once()->andReturn([
            'vendor' => 'Graybar', 'received_on' => '2026-09-22',
            'lines' => [['name' => 'EMT', 'quantity' => 100, 'unit' => 'EA']], 'confidence' => 0.9,
        ]);
        $upload = $this->upload(true);
        $this->assertSame(100, $upload['data']['lines'][0]['quantity']);
        $this->assertDatabaseCount('material_receipts', 0);

        $id = $this->postJson(self::URL, $this->payload([
            'photo' => $upload['file'], 'lines' => [['name' => 'EMT', 'quantity' => 10, 'unit' => 'EA']],
        ]))->assertSuccessful()->json('id');
        $receipt = MaterialReceipt::with('lines')->findOrFail($id);
        $this->assertSame(10.0, (float) $receipt->lines->first()->quantity);
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertNull($receipt->confirmed_at);
    }

    public function test_file_picker_accepts_pdf_and_csv_proof_without_ai(): void
    {
        $this->manager();
        $this->mock(DeliverySlipAnalyzer::class)->shouldNotReceive('analyze');
        foreach ([
            UploadedFile::fake()->createWithContent('packing-list.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
            UploadedFile::fake()->createWithContent('packing-list.csv', "Item,Quantity,Unit\nEMT,10,EA\n"),
        ] as $file) {
            $response = $this->postJson(self::URL.'/upload', [
                'site_id' => $this->site->id, 'file' => $file, 'analyze' => false,
            ])->assertOk()->assertJsonPath('success', true);
            $this->assertIsString($response->json('file.token'));
        }
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_upload_rejects_disguised_executable_and_unauthorized_site_before_storing(): void
    {
        $this->manager();
        $this->mock(DeliverySlipAnalyzer::class)->shouldNotReceive('analyze');
        foreach (['<?php echo "not a photograph";', 'plain text pretending to be a photograph'] as $content) {
            $temporary = UploadedFile::fake()->createWithContent('photo.jpg', $content);
            // Laravel's fake guesses MIME from the filename. Use a real UploadedFile so
            // the server must inspect the bytes even when the client claims image/jpeg.
            $file = new UploadedFile($temporary->getRealPath(), 'photo.jpg', 'image/jpeg', null, true);
            $this->postJson(self::URL.'/upload', [
                'site_id' => $this->site->id, 'file' => $file,
            ])->assertUnprocessable();
        }
        $this->postJson(self::URL.'/upload', [
            'site_id' => $this->otherSite->id, 'file' => UploadedFile::fake()->image('other.jpg'), 'analyze' => true,
        ])->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_manual_mobile_receipt_is_idempotent_and_requires_separate_human_confirmation(): void
    {
        $manager = $this->manager();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertSuccessful()->assertJsonPath('success', true)->json('id');
        $this->postJson(self::URL, $payload)->assertSuccessful()->assertJsonPath('id', $id);

        $receipt = MaterialReceipt::with('lines')->findOrFail($id);
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertSame($manager->id, $receipt->created_by_id);
        $this->assertSame($this->site->id, $receipt->site_id);
        $this->assertSame($this->company->id, $receipt->company_id);
        $this->assertSame('2026-09-22', $receipt->received_on->toDateString());
        $this->assertSame('DN-7', $receipt->delivery_no);
        $this->assertSame(12.5, (float) $receipt->lines[0]->quantity);
        $this->assertSame(24.0, (float) $receipt->lines[1]->quantity);
        $this->assertDatabaseCount('material_receipts', 1);
        $this->assertDatabaseCount('material_receipt_lines', 2);
        $this->assertSame(0, MobileExpense::query()->count(), 'Receiving alone does not post an expense — only a human confirmation does.');

        $this->postJson(self::URL.'/'.$id.'/confirm')->assertSuccessful()->assertJsonPath('success', true);
        $receipt->refresh();
        $this->assertSame(MaterialReceipt::STATUS_CONFIRMED, $receipt->status);
        $this->assertSame($manager->id, $receipt->confirmed_by_id);
        $this->assertNotNull($receipt->confirmed_at);
        $this->postJson(self::URL, $payload)->assertSuccessful()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('material_receipts', 1);
        $this->assertSame(0, Equipment::query()->count(), 'Receiving does not invent rental assets.');
        // 2026-09-23 owner directive lifted the hold on the accounting link: a confirmed
        // receipt posts the priced total (12.5 × 3.2) as a pending expense, never a purchase order.
        $expense = MobileExpense::query()->where('source_ref', "material-receipt:{$id}")->first();
        $this->assertNotNull($expense, 'Confirmation posts the priced total to the pending ledger.');
        $this->assertSame('pending', $expense->status);
        $this->assertSame(40.0, (float) $expense->amount);
        $this->assertSame(1, MobileExpense::query()->count(), 'Re-posting the same receipt never doubles the expense.');
    }

    public function test_mobile_save_rejects_invalid_line_instead_of_silently_dropping_it(): void
    {
        $this->manager();
        $payload = $this->payload();
        $payload['lines'][] = ['name' => 'Uncounted boxes', 'quantity' => 0, 'unit' => 'BOX'];
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.2.quantity');
        $this->postJson(self::URL, $this->payload(['received_on' => '2026-02-30']))
            ->assertUnprocessable()->assertJsonValidationErrors('received_on');
        $this->assertDatabaseCount('material_receipts', 0);
    }

    public function test_raw_storage_paths_and_tampered_tokens_cannot_be_attached(): void
    {
        $this->manager();
        Storage::disk('local')->put('private/salary.pdf', 'private unrelated document');
        $this->postJson(self::URL, $this->payload(['photo' => [
            'disk' => 'local', 'path' => 'private/salary.pdf', 'name' => 'delivery.pdf',
        ]]))->assertUnprocessable();
        $upload = $this->upload();
        $upload['file']['token'] .= 'tampered';
        $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))->assertUnprocessable();
        $this->assertDatabaseCount('material_receipts', 0);
    }

    public function test_upload_token_belongs_to_uploader_site_and_expiry(): void
    {
        $originalManager = $this->manager(['access_scope' => 'all_sites']);
        $upload = $this->upload();
        $this->postJson(self::URL, $this->payload([
            'site_id' => $this->otherSite->id, 'photo' => $upload['file'],
        ]))->assertUnprocessable();

        $this->manager();
        $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))->assertUnprocessable();
        $this->actingAs($originalManager);
        $this->travel(25)->hours();
        $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))->assertUnprocessable();
        $this->travelBack();
        $this->assertDatabaseCount('material_receipts', 0);
    }

    public function test_other_site_receipts_and_evidence_stay_private(): void
    {
        $this->manager();
        $upload = $this->upload();
        $id = $this->postJson(self::URL, $this->payload(['photo' => $upload['file']]))
            ->assertSuccessful()->json('id');

        $this->manager(['allowed_site_id' => $this->otherSite->id]);
        $this->getJson(self::URL.'/items')->assertOk()->assertJsonPath('total', 0);
        $this->get(route('material-receipts.file', $id))->assertForbidden();
        $this->postJson(self::URL.'/'.$id.'/confirm')->assertForbidden();
        $this->postJson(self::URL, $this->payload())->assertForbidden();
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, MaterialReceipt::findOrFail($id)->status);
    }
}
