<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentFolder;
use App\Models\Employee;
use App\Models\IntegratedDocument;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\IntegratedDocumentService;
use App\Support\ReceiptFilePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 문서통합관리 — 사용자 폴더 생성/삭제, 업로드 시 폴더 수동 지정,
 * 재무관리 영수증의 "자재·구매" 폴더 자동 편철.
 */
class DocumentFolderManualFilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.documents_disk' => 'public']);
        IntegratedDocument::forgetFolderMap();
    }

    private function service(): IntegratedDocumentService
    {
        return app(IntegratedDocumentService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    // ── 1) 사용자 폴더 ──────────────────────────────────────────

    public function test_custom_folder_is_created_after_the_default_nine(): void
    {
        $res = $this->service()->createFolder('협력사 제출서류', '#ff8800');

        $this->assertTrue($res['success']);
        $this->assertSame('10', $res['folder']['code']); // 기본 01~09 다음
        IntegratedDocument::forgetFolderMap();
        $this->assertArrayHasKey('10', IntegratedDocument::folderMap());
        $this->assertSame('협력사 제출서류', IntegratedDocument::folderMap()['10']['name']);

        // 폴더 목록 조회에도 함께 나온다.
        $codes = collect($this->service()->folders(null))->pluck('code');
        $this->assertTrue($codes->contains('10'));
    }

    public function test_duplicate_and_empty_folder_names_are_rejected(): void
    {
        $this->assertFalse($this->service()->createFolder('  ')['success']);
        $this->assertFalse($this->service()->createFolder('자재·구매')['success']); // 기본 폴더와 중복
        $this->service()->createFolder('신규폴더');
        IntegratedDocument::forgetFolderMap();
        $this->assertFalse($this->service()->createFolder('신규폴더')['success']);
    }

    public function test_default_folder_cannot_be_deleted_and_non_empty_custom_folder_is_protected(): void
    {
        $this->assertFalse($this->service()->deleteFolder('06')['success']); // 기본 폴더

        $code = $this->service()->createFolder('임시폴더')['folder']['code'];
        IntegratedDocument::forgetFolderMap();
        IntegratedDocument::create([
            'folder_code' => $code, 'title' => 'doc', 'status' => 'needs_review',
            'disk' => 'public', 'path' => 'integrated-documents/x.pdf',
            'type_confidence' => 50, 'folder_confidence' => 50,
        ]);

        $blocked = $this->service()->deleteFolder($code);
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('문서 1건', $blocked['error']);

        // 문서를 치우면 삭제된다.
        IntegratedDocument::query()->where('folder_code', $code)->delete();
        $this->assertTrue($this->service()->deleteFolder($code)['success']);
        $this->assertSame(0, DocumentFolder::query()->where('code', $code)->count());
    }

    // ── 2) 업로드 시 폴더 수동 지정 ─────────────────────────────

    public function test_upload_with_chosen_folder_locks_it_against_ai_reclassification(): void
    {
        // CAD 는 보통 03(시공·공정)으로 가지만, 사용자가 06 을 고르면 06 에 저장돼야 한다.
        $res = $this->actingAs($this->admin())->postJson(route('docs.upload'), [
            'file' => UploadedFile::fake()->create('plan.dwg', 20),
            'folder_code' => '06',
        ]);

        $res->assertStatus(201);
        $doc = IntegratedDocument::firstOrFail();
        $this->assertSame('06', $doc->folder_code);
        $this->assertTrue($doc->folder_locked);
        $this->assertSame(100, $doc->folder_confidence);
    }

    public function test_upload_into_a_custom_folder_works(): void
    {
        $code = $this->service()->createFolder('협력사 제출서류')['folder']['code'];
        IntegratedDocument::forgetFolderMap();

        $this->actingAs($this->admin())->postJson(route('docs.upload'), [
            'file' => UploadedFile::fake()->create('plan.dwg', 10),
            'folder_code' => $code,
        ])->assertStatus(201);

        $this->assertSame($code, IntegratedDocument::firstOrFail()->folder_code);
    }

    public function test_upload_without_folder_choice_still_uses_ai_classification(): void
    {
        $this->actingAs($this->admin())->postJson(route('docs.upload'), [
            'file' => UploadedFile::fake()->create('plan.dwg', 10),
        ])->assertStatus(201);

        $doc = IntegratedDocument::firstOrFail();
        $this->assertFalse($doc->folder_locked);
        $this->assertSame('03', $doc->folder_code); // 도면 → 시공·공정 자동 분류
    }

    // ── 3) 영수증 자동 편철 ─────────────────────────────────────

    private function expense(array $overrides = []): MobileExpense
    {
        $company = Company::create(['code' => 'C1', 'name' => 'Co', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'S1', 'name' => 'Site', 'status' => 'active']);
        $emp = Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'name' => '김철수',
            'first_name' => '김철수', 'last_name' => '', 'email' => 'k@x.com', 'employment_status' => 'active',
        ]);

        return MobileExpense::create(array_merge([
            'company_id' => $company->id, 'site_id' => $site->id, 'employee_id' => $emp->id,
            'payment_type' => 'personal', 'category' => 'Materials', 'accounting_account' => 'Materials',
            'description' => 'Home Depot 자재 구매', 'amount' => 152.30, 'expense_date' => now()->toDateString(),
            'receipt_file' => ReceiptFilePayload::encode('fake-receipt-bytes'),
            'receipt_mime_type' => 'image/jpeg', 'receipt_original_name' => 'receipt.jpg',
            'ocr_data' => ['vendor_name' => 'HOME DEPOT'], 'status' => 'pending',
        ], $overrides));
    }

    public function test_receipt_is_auto_filed_into_material_purchase_folder(): void
    {
        $expense = $this->expense();

        $doc = IntegratedDocument::query()->where('document_type', 'receipt')->first();
        $this->assertNotNull($doc, '영수증 등록 시 문서함에 자동 편철돼야 한다.');
        $this->assertSame('06', $doc->folder_code); // 자재·구매
        $this->assertTrue($doc->folder_locked);
        $this->assertSame('자재·구매', $doc->folderName());
        $this->assertStringContainsString('HOME DEPOT', $doc->title);
        $this->assertSame($expense->id, $doc->fields['mobile_expense_id']);
        Storage::disk('public')->assertExists($doc->path);
    }

    public function test_deleting_the_filed_document_does_not_destroy_the_expense_receipt(): void
    {
        // 문서함 사본은 지출결의 영수증과 독립적이어야 한다.
        $expense = $this->expense();
        $doc = IntegratedDocument::query()->where('document_type', 'receipt')->firstOrFail();

        $doc->delete();

        $expense->refresh();
        $this->assertNotNull($expense->receipt_file, '문서 삭제가 지출결의 영수증을 지우면 안 된다.');
        $this->assertSame('fake-receipt-bytes', ReceiptFilePayload::decode($expense->receipt_file));
    }

    public function test_expense_without_receipt_is_not_filed(): void
    {
        $this->expense(['receipt_file' => null, 'receipt_path' => null]);

        $this->assertSame(0, IntegratedDocument::query()->where('document_type', 'receipt')->count());
    }

    public function test_receipt_filing_is_idempotent(): void
    {
        $expense = $this->expense();
        $this->service()->fileReceipt($expense); // 다시 호출해도 중복 생성 금지

        $this->assertSame(1, IntegratedDocument::query()->where('document_type', 'receipt')->count());
    }
}
