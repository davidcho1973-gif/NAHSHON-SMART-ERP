<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\GeminiReceiptAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            'category' => 'Meals & Entertainment',
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

        $response = $this->actingAs($this->user)->get(route('mobile-expense.index'));

        $response->assertStatus(200);
        $response->assertViewHas('expenses');
        $response->assertViewHas('approvedMtd', 50.00);
        $response->assertViewHas('pendingCount', 1);
        $response->assertViewHas('totalReimbursement', 50.00);
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
                    'category' => 'Meals & Entertainment',
                    'description' => 'Happy Meal',
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
                'category' => 'Meals & Entertainment',
                'description' => 'Happy Meal',
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
            'category' => 'Office Supplies',
            'amount' => 45.99,
            'status' => 'pending',
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
            'receipt_file' => 'receipt-image-from-database',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('mobile-expense.receipt', $expense));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertSame('receipt-image-from-database', $response->getContent());
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

        $response = $this->actingAs($this->user)
            ->put(route('mobile-expense.update', $expense), [
                'payment_type' => 'corporate',
                'category' => 'Tools',
                'class' => 'Field',
                'description' => 'Replacement blades',
                'amount' => 67.89,
                'expense_date' => '2026-06-22',
                'site_id' => $this->site->id,
                'receipt' => UploadedFile::fake()->create('replacement.png', 500, 'image/png'),
            ]);

        $response->assertRedirect(route('mobile-expense.index'));

        $expense->refresh();
        $this->assertSame('corporate', $expense->payment_type);
        $this->assertSame('Tools', $expense->category);
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
                'category' => 'Tools',
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
                'category' => 'Admin Updated',
                'description' => 'Reviewed expense',
                'amount' => 99.99,
                'expense_date' => '2026-06-23',
                'status' => 'rejected',
            ])
            ->assertRedirect(route('mobile-expense.index'));

        $expense->refresh();
        $this->assertSame('Admin Updated', $expense->category);
        $this->assertSame('rejected', $expense->status);

        $this->actingAs($admin)
            ->delete(route('mobile-expense.destroy', $expense))
            ->assertRedirect(route('mobile-expense.index'));

        $this->assertDatabaseMissing('mobile_expenses', ['id' => $expense->id]);
    }

    public function test_desktop_expense_api_exposes_edit_and_delete_actions_for_admin(): void
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
        $this->assertSame(route('mobile-expense.edit', $expense, false), $response->json('0.editUrl'));
        $this->assertSame(route('mobile-expense.destroy', $expense, false), $response->json('0.deleteUrl'));
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
                    'category' => 'Office Supplies',
                    'description' => 'Paper and pens',
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
            'category' => 'Office Supplies',
            'amount' => 89.99,
            'status' => 'pending',
        ]);
    }
}
