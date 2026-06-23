<?php

namespace Tests\Feature;

use App\Models\MobileExpense;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartCompanySeedRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_records_handle_mobile_expenses_without_vendor_key(): void
    {
        $expense = MobileExpense::create([
            'payment_type' => 'personal',
            'category' => 'Office Supplies',
            'class' => 'Office',
            'description' => 'Receipt from field purchase',
            'amount' => 42.35,
            'expense_date' => '2026-06-22',
            'status' => 'pending',
        ]);

        $records = SmartCompanyData::seedRecords();

        // PostgreSQL does not roll back identity sequences between tests, so the
        // expense id is not guaranteed to be 1 — resolve it from the model.
        $financeRecord = collect($records)->firstWhere('record_key', 'EXP-' . $expense->id);

        $this->assertNotNull($financeRecord);
        $this->assertSame('finance', $financeRecord['module']);
        $this->assertSame('Receipt from field purchase', $financeRecord['name']);
        $this->assertSame('Office Supplies', $financeRecord['category']);
        $this->assertSame(42.35, $financeRecord['amount']);
    }
}
