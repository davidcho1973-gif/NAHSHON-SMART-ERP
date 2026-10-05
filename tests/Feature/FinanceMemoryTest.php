<?php

namespace Tests\Feature;

use App\Models\MobileExpense;
use App\Models\User;
use App\Support\ReceiptPhoto;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_totals_and_paginated_search_do_not_load_receipt_content(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        for ($i = 0; $i < 31; $i++) {
            MobileExpense::create(['description' => 'Supply '.$i, 'amount' => '12.34', 'expense_date' => now()->toDateString(), 'status' => 'approved', 'payment_type' => 'personal', 'category' => 'Materials', 'receipt_file' => str_repeat('x', 100000)]);
        }
        DB::enableQueryLog();
        $stats = SmartCompanyData::financeStats();
        $page1 = SmartCompanyData::expenses('ALL', true, ['page' => 1]);
        $page2 = SmartCompanyData::expenses('ALL', true, ['page' => 2]);
        $search = SmartCompanyData::expenses('ALL', true, ['search' => 'Supply 0']);
        $this->assertEqualsWithDelta(382.54, $stats['totalSpend'], 0.001);
        $this->assertCount(25, $page1['items']);
        $this->assertCount(6, $page2['items']);
        $this->assertSame(31, $page1['total']);
        $this->assertCount(1, $search['items']);
        $this->assertNotEmpty($search['items'][0]['receiptUrl']);
        $this->assertEmpty(array_intersect(array_column($page1['items'], 'id'), array_column($page2['items'], 'id')));
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'from "mobile_expenses"')) {
                $this->assertStringNotContainsString('select *', $query['query']);
                $this->assertStringNotContainsString('"mobile_expenses"."receipt_file"', $query['query']);
                $this->assertStringNotContainsString('"mobile_expenses"."ocr_data"', $query['query']);
            }
        }
        DB::disableQueryLog();
    }

    public function test_new_photo_is_smaller_but_pdf_is_unchanged(): void
    {
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('large.jpg', 4000, 3000);
        $before = file_get_contents($photo->getRealPath());
        $path = ReceiptPhoto::store($photo);
        $after = Storage::disk('public')->get($path);
        $this->assertLessThan(strlen($before), strlen($after));
        $this->assertSame(2560, getimagesizefromstring($after)[0]);
        $pdf = UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 original bytes');
        $this->assertSame('%PDF-1.4 original bytes', Storage::disk('public')->get(ReceiptPhoto::store($pdf)));
    }

    public function test_long_receipt_preserves_readable_width(): void
    {
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('long.jpg', 2000, 7000);
        $size = getimagesizefromstring(Storage::disk('public')->get(ReceiptPhoto::store($photo)));
        $this->assertGreaterThanOrEqual(1600, $size[0]);
    }
}
