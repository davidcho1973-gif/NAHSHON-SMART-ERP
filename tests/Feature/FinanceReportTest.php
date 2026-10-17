<?php

namespace Tests\Feature;

use App\Models\MobileExpense;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\LegacyActions;
use App\Services\Finance\ExpenseReportWorkbook;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_export_contains_every_matching_page_and_correct_status_totals(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        for ($i = 0; $i < 27; $i++) {
            MobileExpense::create(['description' => '=Supply 100% '.$i, 'amount' => '12.34', 'expense_date' => '2026-10-17', 'status' => 'approved', 'payment_type' => 'personal', 'category' => 'Materials']);
        }
        MobileExpense::create(['category' => 'Materials', 'description' => 'Supply 100X', 'amount' => '999', 'expense_date' => '2026-10-17', 'status' => 'approved', 'payment_type' => 'personal']);
        MobileExpense::create(['category' => 'Materials', 'description' => 'Supply 100%', 'amount' => '888', 'expense_date' => '2026-09-17', 'status' => 'approved', 'payment_type' => 'personal']);
        MobileExpense::create(['category' => 'Materials', 'description' => 'Supply 100%', 'amount' => '777', 'expense_date' => '2026-10-17', 'status' => 'pending', 'payment_type' => 'corporate']);
        $filters = ['search' => '100%', 'dateFrom' => '2026-10-01', 'dateTo' => '2026-10-31', 'status' => 'approved', 'payment' => 'personal'];
        $page = SmartCompanyData::expenses('ALL', true, $filters);
        $this->assertCount(25, $page['items']);
        $this->assertSame(27, $page['total']);
        $this->assertEqualsWithDelta(333.18, $page['summary'][0]['amount'], 0.001);
        $encoded = SmartCompanyData::handle('api_getFinanceExcelBase64', [$filters]);
        $file = tempnam(sys_get_temp_dir(), 'finance-test');
        try {
            file_put_contents($file, base64_decode($encoded, true));
            $book = IOFactory::load($file);
            $detail = $book->getSheetByName('비용 상세');
            $this->assertSame('EXP-'.$page['items'][0]['expenseId'], $detail->getCell('A7')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('E7')->getDataType());
            $this->assertSame('=SUM(F7:F33)', $detail->getCell('F34')->getValue());
            $this->assertEqualsWithDelta(333.18, $detail->getCell('F34')->getCalculatedValue(), 0.001);
            $this->assertEquals(27, $book->getSheetByName('조회 요약')->getCell('B12')->getCalculatedValue());
            $this->assertEqualsWithDelta(333.18, $book->getSheetByName('조회 요약')->getCell('C14')->getCalculatedValue(), 0.001);
            $this->assertSame('A6:K33', $detail->getAutoFilter()->getRange());
            $this->assertSame(1, $detail->getPageSetup()->getFitToWidth());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_invalid_date_range_is_rejected_not_silently_exported(): void
    {
        $this->expectException(ValidationException::class);
        SmartCompanyData::financeFilters(['dateFrom' => '2026-10-31', 'dateTo' => '2026-10-01']);
    }

    public function test_empty_report_has_zero_totals_and_no_formula_errors(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $book = app(ExpenseReportWorkbook::class)->build('ALL', []);
        $this->assertEquals(0, $book->getSheet(0)->getCell('C12')->getCalculatedValue());
        $this->assertEquals(0, $book->getSheet(1)->getCell('F8')->getCalculatedValue());
    }

    public function test_list_and_export_keep_site_scope_and_hq_common_costs(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']));
        $a = Site::create(['code' => 'REPORT-A', 'name' => 'Report A']);
        $b = Site::create(['code' => 'REPORT-B', 'name' => 'Report B']);
        foreach ([$a->id, $b->id, null] as $site) {
            MobileExpense::create(['site_id' => $site, 'category' => 'Materials', 'description' => 'Scoped cost', 'amount' => 10, 'expense_date' => '2026-10-17', 'status' => 'pending', 'payment_type' => 'corporate']);
        }
        $page = SmartCompanyData::expenses('REPORT-A', true, []);
        $this->assertSame(2, $page['total']);
        $this->assertEquals(20, $page['summary'][0]['amount']);
        $book = app(ExpenseReportWorkbook::class)->build('REPORT-A', []);
        $this->assertEquals(20, $book->getSheet(0)->getCell('C12')->getCalculatedValue());
        $this->assertEquals(0, $book->getSheet(0)->getCell('C14')->getCalculatedValue());
        $this->assertStringNotContainsString('REPORT-B', json_encode($book->getSheet(1)->toArray()));
    }

    public function test_unprivileged_user_cannot_export(): void
    {
        $this->actingAs(User::factory()->create(['access_role' => 'worker', 'account_status' => 'active']));
        $this->expectException(HttpException::class);
        app(LegacyActions::class)->financeExcel('ALL', []);
    }
}
