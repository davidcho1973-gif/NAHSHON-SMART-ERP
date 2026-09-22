<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\MaterialReceipt;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\DeliverySlipAnalyzer;
use App\Services\Inventory\MaterialReceiptService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 자재 입고 — 공정표 없이, 수량과 함께, 사진에서.
 *
 * 여기서 잠그는 것은 두 가지다.
 *
 * 1. <b>공정표가 없어도 적을 수 있다.</b> 기존 조달 추적은 WBS 한 줄에 매달려 있어
 *    공정표에 그 자재 줄이 없으면 적을 자리가 없었다. 적을 자리가 없으면 사람은
 *    종이에 적고, 종이는 ERP 로 오지 않는다.
 * 2. <b>AI 가 읽은 숫자는 장부가 아니다.</b> 사람이 확정하기 전까지 «확인 대기» 다.
 *    번진 글씨의 10 이 100 으로 읽히는 일은 반드시 생긴다.
 */
class MaterialReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'C1', 'name' => 'ABC ENG', 'legal_name' => 'ABC ENG LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => '703K', 'name' => 'Savannah',
            'country' => 'US', 'timezone' => 'America/New_York', 'status' => 'active',
        ]);
    }

    private function actingAsManager(array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
        $this->actingAs($user);

        return $user;
    }

    private function svc(): MaterialReceiptService
    {
        return app(MaterialReceiptService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function save(array $lines, array $extra = []): array
    {
        return $this->svc()->save(array_merge([
            'site_id' => $this->site->id,
            'received_on' => '2026-09-22',
            'vendor' => 'Graybar',
            'lines' => $lines,
        ], $extra), '703K', auth()->id());
    }

    public function test_a_delivery_is_recorded_with_quantities_and_no_schedule(): void
    {
        // 공정표(WbsItem)를 하나도 만들지 않았다 — 그래도 적힌다.
        $this->actingAsManager();

        $res = $this->save([
            ['name' => 'EMT 1/2" Conduit', 'quantity' => 120, 'unit' => 'EA'],
            ['name' => '3/4" Strut', 'quantity' => 40.5, 'unit' => 'LF', 'unit_price' => 3.25],
        ]);

        $this->assertTrue($res['success'], $res['error'] ?? '');
        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame(2, $row['lineCount']);
        $this->assertSame(120.0, $row['lines'][0]['quantity']);
        $this->assertSame(40.5, $row['lines'][1]['quantity'], '소수점 수량이 정수로 잘리면 안 된다.');
        $this->assertSame(131.63, $row['amount'], '단가가 있는 줄만 금액이 된다.');
    }

    public function test_a_line_without_a_quantity_is_not_a_delivery(): void
    {
        // 납품서의 「소계」·「운임」 줄이 품목으로 들어오면 재고에 유령이 생긴다.
        $this->actingAsManager();

        $this->save([
            ['name' => 'EMT 1/2" Conduit', 'quantity' => 10, 'unit' => 'EA'],
            ['name' => 'SUBTOTAL', 'quantity' => 0],
            ['name' => '', 'quantity' => 5],
        ]);

        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame(1, $row['lineCount']);
        $this->assertSame('EMT 1/2" Conduit', $row['lines'][0]['name']);
    }

    public function test_a_delivery_with_no_usable_line_is_refused(): void
    {
        $this->actingAsManager();

        $res = $this->save([['name' => 'TAX', 'quantity' => 0]]);

        $this->assertFalse($res['success']);
        $this->assertSame(0, MaterialReceipt::query()->count(), '빈 입고 한 장이 목록에 남으면 안 된다.');
    }

    public function test_a_new_receipt_waits_for_a_person(): void
    {
        $this->actingAsManager();
        $this->save([['name' => 'EMT', 'quantity' => 10]]);

        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, $row['status']);
        $this->assertNull($row['confirmedAt']);
    }

    public function test_confirming_stamps_who_looked_at_it(): void
    {
        $user = $this->actingAsManager();
        $id = $this->save([['name' => 'EMT', 'quantity' => 10]])['id'];

        $this->assertTrue($this->svc()->confirm($id)['success']);

        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame(MaterialReceipt::STATUS_CONFIRMED, $row['status']);
        $this->assertSame($user->name, $row['confirmedBy']);
        $this->assertNotNull($row['confirmedAt']);
    }

    public function test_a_confirmed_receipt_cannot_be_changed_behind_the_confirmation(): void
    {
        // 확정은 «이 숫자를 내가 봤다» 는 뜻이다. 뒤에서 수량이 바뀌면 그 말이 거짓이 된다.
        $this->actingAsManager();
        $id = $this->save([['name' => 'EMT', 'quantity' => 10]])['id'];
        $this->svc()->confirm($id);

        $res = $this->save([['name' => 'EMT', 'quantity' => 999]], ['id' => $id]);
        $this->assertFalse($res['success']);

        $this->assertFalse($this->svc()->delete($id)['success']);
        $this->assertSame(10.0, $this->svc()->list('703K')['items'][0]['lines'][0]['quantity']);

        // 확정을 풀면 다시 고칠 수 있다 — 막다른 길을 만들지 않는다.
        $this->assertTrue($this->svc()->confirm($id, false)['success']);
        $this->assertTrue($this->save([['name' => 'EMT', 'quantity' => 999]], ['id' => $id])['success']);
    }

    public function test_the_supplier_goes_into_the_vendor_ledger_not_a_free_text_box(): void
    {
        // 대장 밖의 글자는 돈으로 이어지지 않는다 — 이름이 오면 거래처 대장을 지난다.
        $this->actingAsManager();
        Vendor::create(['name' => 'Graybar', 'status' => 'active']);

        $this->save([['name' => 'EMT', 'quantity' => 10]], ['vendor' => '  graybar ']);

        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame('Graybar', $row['vendor'], '대소문자만 다른 이름이 두 거래처가 되면 집계가 갈라진다.');
        $this->assertSame(1, Vendor::query()->count());
        $this->assertNotNull($row['vendorId']);
    }

    public function test_a_known_item_name_is_linked_and_an_unknown_one_is_not_invented(): void
    {
        // 품목 마스터는 사람이 정하는 기준이다. 판독 결과가 마스터를 만들면
        // 잘못 읽은 글자가 영구히 남는다.
        $this->actingAsManager();
        $item = Item::create(['name' => 'EMT 1/2" Conduit', 'unit' => 'EA', 'status' => 'active']);

        $this->save([
            ['name' => ' emt 1/2" conduit ', 'quantity' => 10],
            ['name' => 'EMI 1/2 Condiut', 'quantity' => 5],
        ]);

        $lines = $this->svc()->list('703K')['items'][0]['lines'];
        $this->assertSame($item->id, $lines[0]['itemId']);
        $this->assertNull($lines[1]['itemId']);
        $this->assertSame(1, Item::query()->count());
    }

    public function test_another_sites_delivery_stays_out_of_view(): void
    {
        $this->actingAsManager();
        $this->save([['name' => 'EMT', 'quantity' => 10]]);

        $other = Site::create([
            'company_id' => $this->company->id, 'code' => 'XYZ', 'name' => 'Other',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->actingAsManager(['access_scope' => 'site', 'allowed_site_id' => $other->id]);

        $this->assertSame(0, $this->svc()->list('ALL')['total']);
        // 남의 현장에 적을 수도 없다 — 화면에서 현장 번호만 바꿔 보내면 그만이기 때문이다.
        $this->assertFalse($this->save([['name' => 'EMT', 'quantity' => 1]])['success']);
    }

    public function test_someone_without_site_authority_cannot_record_one(): void
    {
        $this->actingAs(User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]));

        $this->assertFalse($this->save([['name' => 'EMT', 'quantity' => 10]])['success']);
        $this->assertSame(0, MaterialReceipt::query()->count());
    }

    // ── 사진 한 장으로 ────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function fakeEngine(array $data): void
    {
        $this->app->instance(OcrEngine::class, new class($data) implements OcrEngine
        {
            public function __construct(private array $data) {}

            public function analyze(array $images, string $prompt, array $schema): array
            {
                return ['data' => $this->data, 'model' => 'test-model'];
            }

            public function name(): string
            {
                return 'test';
            }

            public function maxAttachmentBytes(): int
            {
                return 50 * 1024 * 1024;
            }
        });
    }

    public function test_the_analyzer_drops_rows_that_are_not_deliveries(): void
    {
        $this->fakeEngine([
            'vendor' => 'Graybar', 'po_no' => 'PO-77', 'delivery_no' => 'DN-9',
            'received_on' => '2026-09-22', 'confidence' => 1.4, 'summary' => '전선관 입고',
            'lines' => [
                ['name' => 'EMT 1/2" Conduit', 'quantity' => 120, 'unit' => 'EA', 'unit_price' => 4.5],
                ['name' => 'Freight', 'quantity' => null, 'unit' => ''],
                ['name' => '합계', 'quantity' => 0],
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'slip').'.png';
        file_put_contents($path, "\x89PNG fake");
        $data = app(DeliverySlipAnalyzer::class)->analyze($path, 'image/png');
        @unlink($path);

        $this->assertCount(1, $data['lines']);
        $this->assertSame(120.0, $data['lines'][0]['quantity']);
        $this->assertSame('Graybar', $data['vendor']);
        $this->assertSame('2026-09-22', $data['received_on']);
        $this->assertSame(1.0, $data['confidence'], '확신도는 0~1 밖으로 나갈 수 없다.');
    }

    public function test_a_photo_becomes_a_receipt_that_still_waits_for_a_person(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $this->fakeEngine([
            'vendor' => 'Graybar', 'received_on' => '2026-09-22', 'confidence' => 0.9,
            'lines' => [['name' => 'EMT 1/2" Conduit', 'quantity' => 120, 'unit' => 'EA']],
        ]);

        $res = $this->post('/material-receipt-api/analyze', [
            'file' => UploadedFile::fake()->image('slip.jpg'),
            'site_id' => $this->site->id,
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($res->json('id'));

        $row = $this->svc()->list('703K')['items'][0];
        $this->assertSame(MaterialReceipt::STATUS_DRAFT, $row['status'], 'AI 가 읽은 것은 확인 대기여야 한다.');
        $this->assertSame(120.0, $row['lines'][0]['quantity']);
        $this->assertNotNull($row['photoUrl'], '근거 사진이 없으면 나중에 숫자를 설명할 수 없다.');
        $this->assertSame(0.9, $row['aiConfidence']);
    }

    public function test_a_photo_that_reads_nothing_leaves_no_empty_receipt(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $this->fakeEngine(['vendor' => 'Graybar', 'lines' => []]);

        $res = $this->post('/material-receipt-api/analyze', [
            'file' => UploadedFile::fake()->image('blurry.jpg'),
            'site_id' => $this->site->id,
        ]);

        $res->assertOk();
        $this->assertNull($res->json('id'));
        $this->assertSame(0, MaterialReceipt::query()->count());
    }

    public function test_a_worker_cannot_push_a_photo_into_the_books(): void
    {
        $this->actingAs(User::factory()->create([
            'access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active',
        ]));

        $this->post('/material-receipt-api/analyze', [
            'file' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertStatus(403);
    }
}
