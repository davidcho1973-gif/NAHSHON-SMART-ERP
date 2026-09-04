<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpensePreApproval;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\GeminiReceiptAnalyzer;
use App\Support\ReceiptFilePayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Company $company;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'TEST-COMP',
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'TEST-SITE',
            'name' => 'Test Site',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'employment_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id,
            'email' => 'john.doe@example.com',
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);
    }

    public function test_mobile_expense_routes_require_auth(): void
    {
        $this->get(route('mobile-expense.index'))->assertRedirect(route('login'));
        $this->get(route('mobile-expense.wizard'))->assertRedirect(route('login'));
        $this->post(route('mobile-expense.upload-receipt'))->assertRedirect(route('login'));
        $this->post(route('mobile-expense.store'))->assertRedirect(route('login'));
    }

    public function test_mobile_expense_index_displays_expenses_and_stats(): void
    {
        MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => '6402 Business Meals (50% deductible)',
            'description' => 'Business lunch',
            'amount' => 50.00,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'approved',
        ]);

        MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'corporate',
            'category' => 'Office Supplies',
            'description' => 'Notebooks',
            'amount' => 25.00,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        // 지난달 지출인데 오늘 승인한 건 — 누적 카드는 월 구분 없이 전 기간을 센다
        // (David 지시 2026-08-19). 예전엔 이번 달 지출만 세서, 방금 승인해도 $0 이었다.
        MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'corporate',
            'category' => '5401 Equipment Rental',
            'description' => '지난달 장비 임대',
            'amount' => 3186.00,
            'expense_date' => now()->subMonthNoOverflow()->format('Y-m-d'),
            'status' => 'approved',
        ]);
        // 지급완료도 승인을 거쳐 나간 돈이다 — 누적에서 빠지면 안 된다.
        MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'corporate',
            'category' => '5502 Vehicle & Fuel',
            'description' => '유류비',
            'amount' => 100.00,
            'expense_date' => now()->subMonths(2)->format('Y-m-d'),
            'status' => 'paid',
        ]);

        $response = $this->actingAs($this->user)->get(route('mobile-expense.index'));

        $response->assertStatus(200);
        $response->assertViewHas('expenses');
        $response->assertViewHas('approvedMtd', 3336.00);
        $response->assertViewHas('pendingCount', 1);
        $response->assertViewHas('pendingAmount', 25.00);
        // 승인됐지만 아직 지급 전 → 환급 "대기". 지급(paid) 전에는 환급 "완료"가 0이어야 한다.
        $response->assertViewHas('claimableAmount', 50.00);
        $response->assertViewHas('totalReimbursement', 0.00);
        $response->assertSee('Business lunch');
        $response->assertSee('Notebooks');
    }

    public function test_mobile_expense_wizard_displays_sites_and_details(): void
    {
        $response = $this->actingAs($this->user)->get(route('mobile-expense.wizard'));

        $response->assertStatus(200);
        $response->assertViewHas('sites');
        $response->assertSee('Test Site');
    }

    public function test_mobile_expense_wizard_preselects_site_from_finance_context(): void
    {
        $response = $this->actingAs($this->user)->get(route('mobile-expense.wizard', [
            'site' => $this->site->code,
        ]));

        $response->assertStatus(200);
        $response->assertViewHas('selectedSiteId', $this->site->id);
        $response->assertSee('value="' . $this->site->id . '" selected', false);
    }

    public function test_manager_can_approve_and_reject_expense_via_review(): void
    {
        $manager = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $expense = MobileExpense::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'payment_type' => 'personal', 'category' => 'Materials', 'description' => 'x',
            'amount' => 50, 'expense_date' => '2026-07-21', 'status' => 'pending',
        ]);

        $this->actingAs($manager)
            ->patch(route('mobile-expense.review', $expense), ['decision' => 'approved'])
            ->assertRedirect();

        $expense->refresh();
        $this->assertSame('approved', $expense->status);
        $this->assertSame($manager->id, $expense->reviewed_by_user_id);
        $this->assertNotNull($expense->reviewed_at);
    }

    public function test_non_manager_cannot_review_expense(): void
    {
        $worker = User::factory()->create(['employee_id' => $this->employee->id, 'access_role' => 'worker', 'account_status' => 'active']);
        $expense = MobileExpense::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'payment_type' => 'personal', 'category' => 'Materials', 'description' => 'x',
            'amount' => 50, 'expense_date' => '2026-07-21', 'status' => 'pending',
        ]);

        $this->actingAs($worker)
            ->patch(route('mobile-expense.review', $expense), ['decision' => 'approved'])
            ->assertForbidden();
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_expense_site_dropdown_lists_sites_from_other_companies(): void
    {
        // 다른 발주처 회사의 현장 — 직원 회사와 무관하게 선택할 수 있어야 한다.
        $otherCompany = Company::create(['code' => 'LGES', 'name' => 'LG Energy', 'status' => 'active']);
        $otherSite = Site::create([
            'company_id' => $otherCompany->id, 'code' => 'LGES-AZ', 'name' => 'LGES', 'status' => 'active',
        ]);

        // 신규 등록(wizard)과 수정(edit) 양쪽에서 노출되어야 한다.
        $wizard = $this->actingAs($this->user)->get(route('mobile-expense.wizard'));
        $wizard->assertStatus(200)->assertSee('LGES-AZ - LGES');

        $expense = MobileExpense::create([
            'employee_id' => $this->employee->id, 'site_id' => $this->site->id,
            'payment_type' => 'personal', 'category' => '5201 Job Materials',
            'accounting_account' => '5201 Job Materials', 'description' => 'Materials',
            'amount' => 100, 'expense_date' => '2026-07-21', 'status' => 'pending',
        ]);
        $edit = $this->actingAs($this->user)->get(route('mobile-expense.edit', $expense));
        $edit->assertStatus(200)->assertSee('LGES-AZ - LGES');
    }

    public function test_mobile_expense_upload_receipt_runs_ocr_analyzer(): void
    {
        Storage::fake('public');

        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'vendor_name' => 'McDonalds',
                    'amount' => 12.34,
                    'date' => '2026-06-20',
                    'category' => '6402 Business Meals (50% deductible)',
                    'accounting_account' => '6402 Business Meals (50% deductible)',
                    'description' => 'Happy Meal',
                    'handwritten_notes' => 'Crew lunch',
                    'model' => 'gemini-mock',
                ]);
        });

        $file = UploadedFile::fake()->create('receipt.jpg', 500, 'image/jpeg');

        $response = $this->actingAs($this->user)->postJson(route('mobile-expense.upload-receipt'), [
            'receipt' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'vendor_name' => 'McDonalds',
                'amount' => 12.34,
                'date' => '2026-06-20',
                'category' => '6402 Business Meals (50% deductible)',
                'accounting_account' => '6402 Business Meals (50% deductible)',
                'description' => 'Happy Meal',
                'handwritten_notes' => 'Crew lunch',
                'model' => 'gemini-mock',
            ],
        ]);

        $path = $response->json('receipt_path');
        $this->assertNotNull($path);
        // Path matches public storage receipts directory
        $storedFilename = basename($path);
        Storage::disk('public')->assertExists('receipts/' . $storedFilename);
    }

    public function test_mobile_expense_store_saves_expense_record(): void
    {
        $expenseData = [
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'class' => 'Admin',
            'description' => 'Printer paper',
            'amount' => '45.99',
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/test.jpg',
            'site_id' => $this->site->id,
            'ocr_data' => [
                'vendor_name' => 'OfficeMax',
                'amount' => 45.99,
            ],
        ];

        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), $expenseData);

        $response->assertRedirect(route('mobile-expense.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('mobile_expenses', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => '6601 Office Supplies',
            'accounting_account' => '6601 Office Supplies',
            'amount' => 45.99,
            'status' => 'pending',
        ]);
    }

    public function test_mobile_expense_store_uses_ai_account_and_handwritten_notes(): void
    {
        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), [
            'payment_type' => 'personal',
            'category' => '6201 Fuel - Office Vehicles',
            'accounting_account' => '6201 Fuel - Office Vehicles',
            'class' => '',
            'description' => 'Shell - Fuel',
            'amount' => '82.15',
            'expense_date' => '2026-06-23',
            'site_id' => $this->site->id,
            'ocr_data' => json_encode([
                'vendor_name' => 'Shell',
                'amount' => 82.15,
                'date' => '2026-06-23',
                'category' => '6201 Fuel - Office Vehicles',
                'accounting_account' => '6201 Fuel - Office Vehicles',
                'handwritten_notes' => 'Truck 12 / LG-ESAZ',
                'description' => 'Fuel',
            ]),
        ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $expense = MobileExpense::query()->latest('id')->first();

        $this->assertNotNull($expense);
        $this->assertSame('6201 Fuel - Office Vehicles', $expense->category);
        $this->assertSame('6201 Fuel - Office Vehicles', $expense->accounting_account);
        $this->assertNull($expense->class);
        $this->assertStringContainsString('Shell - Fuel', $expense->description);
        $this->assertStringContainsString('Handwritten note: Truck 12 / LG-ESAZ', $expense->description);
        $this->assertSame('Truck 12 / LG-ESAZ', $expense->ocr_data['handwritten_notes']);
    }

    public function test_mobile_expense_store_survives_when_optional_accounting_column_is_not_migrated_yet(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropColumn('accounting_account');
        });

        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), [
            'payment_type' => 'personal',
            'category' => '6201 Fuel - Office Vehicles',
            'accounting_account' => '6201 Fuel - Office Vehicles',
            'class' => '6140 Automobile Expense',
            'description' => 'Marathon gas receipt',
            'amount' => '42.53',
            'expense_date' => '2026-06-23',
            'site_id' => $this->site->id,
        ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $this->assertDatabaseHas('mobile_expenses', [
            'employee_id' => $this->employee->id,
            'category' => '6201 Fuel - Office Vehicles',
            'class' => null,
            'amount' => 42.53,
            'status' => 'pending',
        ]);
    }

    public function test_mobile_expense_can_link_approved_pre_approval(): void
    {
        $preApproval = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Approved travel budget',
            'description' => 'Hotel and rental car',
            'justification' => 'Project travel',
            'estimated_amount' => 800.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), [
            'payment_type' => 'personal',
            'category' => 'Travel & Lodging',
            'class' => 'Field',
            'description' => 'Hotel receipt',
            'amount' => '450.00',
            'expense_date' => now()->format('Y-m-d'),
            'site_id' => $this->site->id,
            'expense_pre_approval_id' => $preApproval->id,
        ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $this->assertDatabaseHas('mobile_expenses', [
            'employee_id' => $this->employee->id,
            'expense_pre_approval_id' => $preApproval->id,
            'description' => 'Hotel receipt',
            'status' => 'pending',
        ]);
    }

    public function test_mobile_expense_cannot_link_pending_pre_approval(): void
    {
        $preApproval = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Pending travel budget',
            'description' => 'Hotel and rental car',
            'justification' => 'Project travel',
            'estimated_amount' => 800.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), [
            'payment_type' => 'personal',
            'category' => 'Travel & Lodging',
            'class' => 'Field',
            'description' => 'Hotel receipt',
            'amount' => '450.00',
            'expense_date' => now()->format('Y-m-d'),
            'site_id' => $this->site->id,
            'expense_pre_approval_id' => $preApproval->id,
        ]);

        $response->assertSessionHasErrors('expense_pre_approval_id');

        $this->assertDatabaseMissing('mobile_expenses', [
            'expense_pre_approval_id' => $preApproval->id,
            'description' => 'Hotel receipt',
        ]);
    }

    public function test_employee_can_view_own_receipt_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/test-receipt.png', 'receipt-image');

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/test-receipt.png',
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->get(route('mobile-expense.receipt', $expense))
            ->assertOk();
    }

    public function test_employee_cannot_view_another_employee_receipt_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/private-receipt.png', 'receipt-image');

        $otherEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'employment_status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'employee_id' => $otherEmployee->id,
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/private-receipt.png',
            'status' => 'pending',
        ]);

        $this->actingAs($otherUser)
            ->get(route('mobile-expense.receipt', $expense))
            ->assertForbidden();
    }

    public function test_admin_can_view_any_receipt_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/admin-visible-receipt.png', 'receipt-image');

        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/admin-visible-receipt.png',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('mobile-expense.receipt', $expense))
            ->assertOk();
    }

    public function test_receipt_file_can_be_served_from_database_when_storage_file_is_missing(): void
    {
        Storage::fake('public');

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/missing-after-deploy.png',
            'receipt_mime_type' => 'image/png',
            'receipt_original_name' => 'missing-after-deploy.png',
            'receipt_file' => ReceiptFilePayload::encode('receipt-image-from-database'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('mobile-expense.receipt', $expense));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertSame('receipt-image-from-database', $response->getContent());
    }

    public function test_legacy_raw_receipt_file_can_still_be_served_from_database(): void
    {
        Storage::fake('public');

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => '7206 Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/legacy-missing.png',
            'receipt_mime_type' => 'image/png',
            'receipt_original_name' => 'legacy-missing.png',
            'receipt_file' => 'legacy-raw-receipt-image',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('mobile-expense.receipt', $expense));

        $response->assertOk();
        $this->assertSame('legacy-raw-receipt-image', $response->getContent());
    }

    public function test_mobile_expense_store_persists_uploaded_receipt_file_as_database_safe_payload(): void
    {
        Storage::fake('public');

        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        $this->assertIsString($imageBytes);
        Storage::disk('public')->put('receipts/database-safe.png', $imageBytes);

        $response = $this->actingAs($this->user)->post(route('mobile-expense.store'), [
            'payment_type' => 'personal',
            'category' => '6201 Fuel - Office Vehicles',
            'accounting_account' => '6201 Fuel - Office Vehicles',
            'class' => '',
            'description' => 'Marathon gas receipt',
            'amount' => '42.53',
            'expense_date' => '2026-06-23',
            'receipt_path' => '/storage/receipts/database-safe.png',
            'site_id' => $this->site->id,
        ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $expense = MobileExpense::query()->latest('id')->first();

        $this->assertNotNull($expense);
        $this->assertSame($imageBytes, ReceiptFilePayload::decode($expense->receipt_file));

        $receiptResponse = $this->actingAs($this->user)
            ->get(route('mobile-expense.receipt', $expense));

        $receiptResponse->assertOk();
        $this->assertSame($imageBytes, $receiptResponse->getContent());
    }

    public function test_employee_can_update_own_pending_expense_and_replace_receipt(): void
    {
        Storage::fake('public');

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'status' => 'pending',
        ]);

        $replacementImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        $this->assertIsString($replacementImage);

        $response = $this->actingAs($this->user)
            ->put(route('mobile-expense.update', $expense), [
                'payment_type' => 'corporate',
                'category' => '5403 Small Tools & Consumables',
                'accounting_account' => '5403 Small Tools & Consumables',
                'class' => 'Field',
                'description' => 'Replacement blades',
                'amount' => 67.89,
                'expense_date' => '2026-06-22',
                'site_id' => $this->site->id,
                'receipt' => UploadedFile::fake()->createWithContent('replacement.png', $replacementImage),
            ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $expense->refresh();
        $this->assertSame('corporate', $expense->payment_type);
        $this->assertSame('5403 Small Tools & Consumables', $expense->category);
        $this->assertSame('5403 Small Tools & Consumables', $expense->accounting_account);
        $this->assertSame('Replacement blades', $expense->description);
        $this->assertSame('pending', $expense->status);
        $this->assertNotNull($expense->receipt_file);
        $this->assertSame('replacement.png', $expense->receipt_original_name);
    }

    public function test_employee_cannot_update_another_employee_expense(): void
    {
        $otherEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.update@example.com',
            'employment_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $otherEmployee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->put(route('mobile-expense.update', $expense), [
                'payment_type' => 'personal',
                'category' => '5403 Small Tools & Consumables',
                'description' => 'Attempted edit',
                'amount' => 10,
                'expense_date' => '2026-06-22',
            ])
            ->assertForbidden();
    }

    public function test_employee_can_delete_own_pending_expense(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('receipts/delete-me.png', 'receipt-image');

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'receipt_path' => '/storage/receipts/delete-me.png',
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->delete(route('mobile-expense.destroy', $expense))
            ->assertRedirect(route('mobile-expense.index'));

        $this->assertDatabaseMissing('mobile_expenses', ['id' => $expense->id]);
        Storage::disk('public')->assertMissing('receipts/delete-me.png');
    }

    public function test_the_erp_finance_screen_gets_a_json_answer_when_it_deletes(): void
    {
        // ERP 재무 화면은 fetch 로 지운다. 리다이렉트를 돌려주면 fetch 가 그것을 따라가
        // «목록 페이지» 의 결과를 삭제의 결과로 읽는다 — 그 페이지가 500 이면 삭제는 됐는데
        // «실패» 가 뜨고, 다시 누르면 404 다(이미 없으니까). 삭제의 성패는 삭제만 말한다.
        $expense = MobileExpense::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'employee_id' => $this->employee->id, 'payment_type' => 'personal',
            'category' => 'Office Supplies', 'description' => 'Printer paper',
            'amount' => 45.99, 'expense_date' => '2026-06-21', 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->user)->deleteJson(route('mobile-expense.destroy', $expense));

        $res->assertOk()->assertJson(['success' => true, 'id' => $expense->id]);
        $this->assertDatabaseMissing('mobile_expenses', ['id' => $expense->id]);

        // 이미 지워진 것을 다시 지우면 404 도 JSON 으로 온다 — 화면이 HTML 을 읽다 죽지 않는다.
        $this->actingAs($this->user)->deleteJson(route('mobile-expense.destroy', $expense->id))
            ->assertNotFound()->assertHeader('content-type', 'application/json');
    }

    public function test_employee_cannot_delete_another_employee_expense(): void
    {
        $otherEmployee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.delete@example.com',
            'employment_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $otherEmployee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->delete(route('mobile-expense.destroy', $expense))
            ->assertForbidden();

        $this->assertDatabaseHas('mobile_expenses', ['id' => $expense->id]);
    }

    public function test_admin_can_update_and_delete_any_expense(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Printer paper',
            'amount' => 45.99,
            'expense_date' => '2026-06-21',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->put(route('mobile-expense.update', $expense), [
                'payment_type' => 'personal',
                'category' => '6601 Office Supplies',
                'accounting_account' => '6601 Office Supplies',
                'description' => 'Reviewed expense',
                'amount' => 99.99,
                'expense_date' => '2026-06-23',
                'status' => 'rejected',
            ])
            ->assertRedirect(route('mobile-expense.index'));

        $expense->refresh();
        $this->assertSame('6601 Office Supplies', $expense->category);
        $this->assertSame('6601 Office Supplies', $expense->accounting_account);
        $this->assertSame('rejected', $expense->status);

        $this->actingAs($admin)
            ->delete(route('mobile-expense.destroy', $expense))
            ->assertRedirect(route('mobile-expense.index'));

        $this->assertDatabaseMissing('mobile_expenses', ['id' => $expense->id]);
    }

    public function test_admin_paid_status_records_review_and_payment_audit_fields(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'payroll',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Travel & Lodging',
            'description' => 'Hotel reimbursement',
            'amount' => 125.50,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put(route('mobile-expense.update', $expense), [
                'payment_type' => 'personal',
                'category' => 'Travel & Lodging',
                'description' => 'Hotel reimbursement',
                'amount' => 125.50,
                'expense_date' => now()->format('Y-m-d'),
                'site_id' => $this->site->id,
                'status' => 'paid',
            ])
            ->assertRedirect(route('mobile-expense.index'));

        $expense->refresh();

        $this->assertSame('paid', $expense->status);
        $this->assertNotNull($expense->reviewed_at);
        $this->assertSame($admin->id, $expense->reviewed_by_user_id);
        $this->assertNotNull($expense->paid_at);
        $this->assertSame($admin->id, $expense->paid_by_user_id);
    }

    public function test_desktop_expense_api_exposes_edit_and_delete_actions_for_admin(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $preApproval = ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Approved lodging budget',
            'description' => 'Hotel budget',
            'justification' => 'Project travel',
            'estimated_amount' => 300.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'approved',
        ]);

        $expense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'expense_pre_approval_id' => $preApproval->id,
            'payment_type' => 'personal',
            'category' => 'Travel & Lodging',
            'description' => 'Hotel receipt',
            'amount' => 125.50,
            'expense_date' => '2026-06-23',
            'receipt_path' => '/storage/receipts/hotel.png',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->postJson('/smart-company-api/api_getExpenses', [
            'args' => [],
            'siteId' => 'ALL',
        ]);

        $response->assertOk();
        $this->assertSame('EXP-' . $expense->id, $response->json('0.id'));
        $this->assertTrue($response->json('0.canModify'));
        $this->assertSame($preApproval->id, $response->json('0.preApprovalId'));
        $this->assertSame('Approved lodging budget', $response->json('0.preApprovalTitle'));
        $this->assertSame(300, $response->json('0.preApprovalAmount'));
        $this->assertSame(route('mobile-expense.edit', $expense, false), $response->json('0.editUrl'));
        $this->assertSame(route('mobile-expense.destroy', $expense, false), $response->json('0.deleteUrl'));
    }

    public function test_desktop_finance_includes_global_office_expenses_when_site_is_selected(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        $siteExpense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Travel & Lodging',
            'description' => 'Selected site hotel',
            'amount' => 100.00,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $globalExpense = MobileExpense::create([
            'company_id' => $this->company->id,
            'site_id' => null,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'description' => 'Global office receipt',
            'amount' => 25.00,
            'expense_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->postJson('/smart-company-api/api_getExpenses', [
            'args' => [],
            'siteId' => $this->site->code,
        ]);

        $response->assertOk();

        $rows = collect($response->json());

        $this->assertContains('EXP-' . $siteExpense->id, $rows->pluck('id')->all());
        $this->assertContains('EXP-' . $globalExpense->id, $rows->pluck('id')->all());
        $this->assertSame('Global / Office', $rows->firstWhere('id', 'EXP-' . $globalExpense->id)['site']);

        $statsResponse = $this->actingAs($admin)->postJson('/smart-company-api/api_getFinanceStats', [
            'args' => [],
            'siteId' => $this->site->code,
        ]);

        $statsResponse->assertOk();
        $this->assertEquals(125.00, $statsResponse->json('mtdTotal'));
    }

    public function test_desktop_finance_stats_are_built_from_linked_database_records(): void
    {
        $admin = User::factory()->create([
            'access_role' => 'payroll',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
        ]);

        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Approved monthly budget',
            'description' => 'Approved travel',
            'justification' => 'Project travel',
            'estimated_amount' => 300.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'approved',
        ]);

        ExpensePreApproval::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'title' => 'Pending monthly budget',
            'description' => 'Pending travel',
            'justification' => 'Project travel',
            'estimated_amount' => 50.00,
            'planned_date' => now()->format('Y-m-d'),
            'payment_method' => 'personal',
            'status' => 'pending',
        ]);

        foreach ([
            ['amount' => 125.00, 'status' => 'approved', 'payment_type' => 'personal', 'description' => 'Approved reimbursement'],
            ['amount' => 10.00, 'status' => 'pending', 'payment_type' => 'corporate', 'description' => 'Pending receipt'],
            ['amount' => 5.00, 'status' => 'paid', 'payment_type' => 'personal', 'description' => 'Paid reimbursement'],
            ['amount' => 1000.00, 'status' => 'rejected', 'payment_type' => 'personal', 'description' => 'Rejected receipt'],
        ] as $row) {
            MobileExpense::create([
                'company_id' => $this->company->id,
                'site_id' => $this->site->id,
                'employee_id' => $this->employee->id,
                'payment_type' => $row['payment_type'],
                'category' => 'Travel & Lodging',
                'description' => $row['description'],
                'amount' => $row['amount'],
                'expense_date' => now()->format('Y-m-d'),
                'status' => $row['status'],
            ]);
        }

        $response = $this->actingAs($admin)->postJson('/smart-company-api/api_getFinanceStats', [
            'args' => [],
            'siteId' => 'ALL',
        ]);

        $response->assertOk();
        $this->assertEquals(300.00, $response->json('mtdBudget'));
        $this->assertEquals(140.00, $response->json('mtdTotal'));
        $this->assertEquals(2, $response->json('pendingApproval'));
        $this->assertEquals(60.00, $response->json('pendingAmount'));
        $this->assertEquals(125.00, $response->json('claimable'));
        $this->assertEquals(160.00, $response->json('budgetBalance'));
    }

    public function test_desktop_universal_ai_scan_saves_expense(): void
    {
        Storage::fake('public');

        $this->mock(GeminiReceiptAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'vendor_name' => 'Staples',
                    'amount' => 89.99,
                    'date' => '2026-06-22',
                    'category' => '6601 Office Supplies',
                    'accounting_account' => '6601 Office Supplies',
                    'description' => 'Paper and pens',
                    'handwritten_notes' => 'Field office',
                    'model' => 'gemini-mock',
                ]);
        });

        $fakeBase64 = 'data:image/jpeg;base64,' . base64_encode('fake-image-bytes');

        $response = $this->actingAs($this->user)->postJson('/smart-company-api/api_universalAIScan', [
            'args' => [
                'EXPENSE',
                $fakeBase64,
                'image/jpeg',
            ],
            'siteId' => $this->site->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'category' => 'EXPENSE',
            'data' => [
                'vendor_name' => 'Staples',
                'amount' => 89.99,
            ],
        ]);

        $this->assertDatabaseHas('mobile_expenses', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'employee_id' => $this->employee->id,
            'payment_type' => 'personal',
            'category' => '6601 Office Supplies',
            'accounting_account' => '6601 Office Supplies',
            'class' => null,
            'amount' => 89.99,
            'status' => 'pending',
        ]);

        $expense = MobileExpense::query()->latest('id')->first();

        $this->assertNotNull($expense);
        $this->assertStringContainsString('Handwritten note: Field office', $expense->description);
    }
}
