<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Housing;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WbsItem;
use App\Services\Admin\UserAccessService;
use App\Services\Finance\DocumentExpenseConnector;
use App\Services\Finance\ProcurementExpenseConnector;
use App\Services\Finance\RentalExpenseConnector;
use App\Services\Procurement\ProcurementService;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ErpIntegrationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_vendor_database_does_not_return_demo_contacts(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        $this->assertSame([], SmartCompanyData::vendors());
    }

    public function test_housing_summary_and_cards_share_real_capacity_and_rent_fields(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']));
        Housing::create(['code' => 'H-A', 'name' => 'House A', 'address' => 'Test address', 'beds' => 4, 'occupied' => 2, 'monthly_rent' => 2500, 'status' => 'available']);
        $rows = SmartCompanyData::housingList();
        $stats = SmartCompanyData::housingStats();
        $this->assertSame('Test address', $rows[0]['address']);
        $this->assertSame(2500.0, $rows[0]['monthlyRent']);
        $this->assertSame(50, $stats['occupancyRate']);
        $this->assertSame(2, $stats['currentOcc']);
        $this->assertSame(4, $stats['totalCapacity']);
        $this->assertSame(2500.0, $stats['monthlyRentTotal']);
    }

    public function test_manual_is_authenticated_and_covers_every_sidebar_menu(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']));
        $this->get('/help')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $manual = file_get_contents(resource_path('manuals/user-manual-ko.html'));
        $spa = file_get_contents(resource_path('views/smart-company/index.blade.php'));
        $sidebar = explode('<div class="sidebar-footer">', explode('<nav class="sidebar-nav">', $spa)[1])[0];
        preg_match_all('/data-view="([^"]+)"/', $sidebar, $matches);
        foreach (array_unique($matches[1]) as $view) {
            $this->assertStringContainsString('id="'.$view.'"', $manual, 'Missing user guide for '.$view);
        }
        $this->assertStringContainsString('팀 공용 가입 QR은 사용하지 않습니다.', $manual);
        $this->assertStringContainsString('등록 승인이나 확인 의견 입력은 없습니다.', $manual);
    }

    public function test_hr_cannot_demote_edit_or_suspend_an_existing_admin(): void
    {
        $hr = User::factory()->create(['access_role' => 'hr_manager', 'account_status' => 'active']);
        $admin = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($hr);
        $service = app(UserAccessService::class);
        $this->assertFalse($service->save(['id' => $admin->id, 'name' => 'Hijack', 'email' => 'replace@example.test', 'role' => 'worker', 'scope' => 'self', 'status' => 'active'])['success']);
        $this->assertFalse($service->setStatus($admin->id, 'suspended')['success']);
        $this->assertSame('admin', $admin->fresh()->access_role);
        $this->assertSame('active', $admin->fresh()->account_status);
    }

    public function test_employee_identity_cannot_be_linked_to_two_accounts(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $employee = Employee::create(['name' => 'Existing worker', 'phone' => '+12025550111']);
        User::factory()->create(['employee_id' => $employee->id]);
        $result = app(UserAccessService::class)->save(['name' => 'New login', 'email' => 'duplicate@example.test', 'role' => 'worker', 'scope' => 'self', 'status' => 'active', 'employeeId' => $employee->id]);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('employeeId', $result['errors']);
        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
    }

    public function test_reversing_receipt_removes_only_unapproved_automatic_cost(): void
    {
        $item = ProcurementItem::create(['project_code' => 'AUDIT', 'wbs_code' => 'A-1', 'status' => '입고완료', 'amount' => 150]);
        $sync = app(ProcurementExpenseConnector::class);
        $sync->sync($item);
        $this->assertSame(150.0, (float) MobileExpense::firstOrFail()->amount);
        $item->update(['status' => '선적중']);
        $sync->sync($item);
        $this->assertDatabaseCount('mobile_expenses', 0);
        $item->update(['status' => '입고완료']);
        $sync->sync($item);
        $expense = MobileExpense::firstOrFail();
        $expense->update(['status' => 'approved']);
        $item->update(['status' => '선적중', 'amount' => 0]);
        $sync->sync($item);
        $this->assertSame(150.0, (float) $expense->fresh()->amount);
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_pending_cost_follows_vendor_identity_even_when_amount_and_name_are_unchanged(): void
    {
        $vendor = Vendor::create(['name' => 'Supplier', 'status' => 'active']);
        $item = ProcurementItem::create(['project_code' => 'AUDIT', 'wbs_code' => 'A-2', 'vendor' => 'Supplier', 'vendor_id' => $vendor->id, 'status' => '입고완료', 'amount' => 150]);
        $sync = app(ProcurementExpenseConnector::class);
        $sync->sync($item);
        $expense = MobileExpense::firstOrFail();
        $date = $expense->expense_date->toDateString();
        $replacement = Vendor::create(['name' => 'Supplier', 'status' => 'active']);
        $item->update(['vendor_id' => $replacement->id]);
        $this->travel(2)->days();
        $sync->sync($item);
        $this->assertSame($replacement->id, $expense->fresh()->vendor_id);
        $this->assertSame($date, $expense->fresh()->expense_date->toDateString());
        $this->assertDatabaseCount('mobile_expenses', 1);
    }

    public function test_missed_eta_is_late_even_before_the_planned_need_by_date(): void
    {
        WbsItem::create(['project_code' => 'AUDIT', 'wbs_code' => 'A-3', 'level' => 'subtask', 'name' => '자재 조달', 'crew_size' => 0, 'planned_end' => '2026-11-01']);
        app(ProcurementService::class)->update('AUDIT', 'A-3', ['status' => '선적중', 'eta' => '2026-09-18']);
        $list = app(ProcurementService::class)->list('AUDIT', 'ALL', '2026-09-19');
        $this->assertSame('late', $list['items'][0]['delay']);
        $this->assertSame(1, $list['lateCount']);
    }

    public function test_failed_finance_link_is_reported_without_losing_procurement_save(): void
    {
        WbsItem::create(['project_code' => 'AUDIT', 'wbs_code' => 'A-4', 'level' => 'subtask', 'name' => '자재 조달']);
        $this->mock(ProcurementExpenseConnector::class)->shouldReceive('sync')->once()->andThrow(new \RuntimeException('Simulated unavailable ledger'));
        $result = app(ProcurementService::class)->update('AUDIT', 'A-4', ['status' => '입고완료', 'amount' => 100]);
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['financeWarning']);
        $this->assertDatabaseHas('procurement_items', ['wbs_code' => 'A-4', 'status' => '입고완료']);
    }

    public function test_document_cost_tracks_site_changes_and_reclassification(): void
    {
        $document = IntelligentDocument::create([
            'uuid' => (string) Str::uuid(), 'original_file_name' => 'audit.pdf', 'stored_file_name' => 'audit.pdf', 'file_path' => 'missing/audit.pdf', 'file_size' => 10, 'mime_type' => 'application/pdf', 'sha256' => hash('sha256', 'audit'),
            'title' => 'Receipt', 'document_type' => 'receipt', 'ai_status' => 'ready', 'document_date' => '2026-09-01',
            'ai_payload' => ['money' => ['flow' => 'out', 'amount' => 100]],
        ]);
        $sync = app(DocumentExpenseConnector::class);
        $sync->sync($document);
        $site = Site::create(['code' => 'AUDIT-SITE', 'name' => 'Actual site', 'status' => 'active']);
        $document->update(['site_id' => $site->id]);
        $sync->sync($document);
        $this->assertSame($site->id, MobileExpense::firstOrFail()->site_id);
        $document->update(['ai_payload' => ['money' => ['flow' => 'in', 'amount' => 100]]]);
        $sync->sync($document);
        $this->assertDatabaseCount('mobile_expenses', 0);
    }

    public function test_rental_cost_tracks_site_only_change(): void
    {
        $site = Site::create(['code' => 'RENT-A', 'name' => 'A', 'status' => 'active']);
        $other = Site::create(['code' => 'RENT-B', 'name' => 'B', 'status' => 'active']);
        $house = Housing::create(['site_id' => $site->id, 'code' => 'AUDIT-H', 'name' => 'Crew house', 'beds' => 4, 'occupied' => 0, 'monthly_rent' => 3000, 'status' => 'active']);
        $sync = app(RentalExpenseConnector::class);
        $sync->accrueMonth('2026-09');
        $house->update(['site_id' => $other->id]);
        $sync->accrueMonth('2026-09');
        $this->assertSame($other->id, MobileExpense::firstOrFail()->site_id);
        $this->assertDatabaseCount('mobile_expenses', 1);
    }

    public function test_literal_spa_api_calls_are_all_dispatched(): void
    {
        $backend = file_get_contents(app_path('Support/SmartCompanyData.php'));
        foreach (array_merge([resource_path('views/smart-company/index.blade.php')], glob(public_path('js/admin-*.js'))) as $file) {
            preg_match_all('/gsRun\(\s*[\'\"](api_[A-Za-z0-9_]+)[\'\"]/', file_get_contents($file), $matches);
            foreach (array_unique($matches[1]) as $method) {
                $this->assertStringContainsString("'{$method}'", $backend, basename($file).': '.$method);
            }
        }
    }
}
