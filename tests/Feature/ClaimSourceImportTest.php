<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Finance\ClaimSourceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClaimSourceImportTest extends TestCase
{
    use RefreshDatabase;

    private ProjectContract $contract;

    private IntelligentDocument $sheet;

    private IntelligentDocument $drawing;

    private array $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $company = Company::create(['code' => 'IMPORT', 'name' => 'Import test', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'IMPORT', 'name' => 'Import site', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $this->contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $site->id, 'title' => 'Import contract', 'direction' => 'receivable', 'original_amount' => 10000, 'currency' => 'USD']);
        $makeDoc = function (string $name): IntelligentDocument {
            Storage::disk('local')->put($name, 'original-'.$name);

            return IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $this->contract->company_id, 'site_id' => $this->contract->site_id, 'project_contract_id' => $this->contract->id, 'uploaded_by' => auth()->id(), 'disk' => 'local', 'file_path' => $name, 'original_file_name' => $name, 'stored_file_name' => $name, 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 20, 'sha256' => hash('sha256', 'original-'.$name), 'title' => $name, 'received_at' => now(), 'ai_status' => 'ready', 'access_level' => 'shared']);
        };
        $this->sheet = $makeDoc('claim.xlsx');
        $this->drawing = $makeDoc('drawing.pdf');
        $this->source = ['schema_version' => '1.0', 'data_classification' => 'source_claims_not_verified_actuals',
            'project' => ['application_date' => '2026-09-20', 'claim_period_start' => '2026-09-01', 'claim_period_end' => '2026-09-30', 'period_basis' => 'Forecast', 'currency' => 'USD'],
            'sources' => ['workbook' => 'claim.xlsx', 'drawing_pdf' => 'drawing.pdf', 'workbook_sha256' => $this->sheet->sha256, 'drawing_pdf_sha256' => $this->drawing->sha256, 'pdf_page_count' => 11],
            'totals' => ['contract' => 10000, 'current_claim' => 2000, 'advance' => 2000, 'retention' => 100, 'current_due' => 3900],
            'items' => [
                ['boq' => 195, 'description' => 'Pipe', 'unit' => 'm', 'contract_qty' => 5, 'unit_price' => 75.39, 'current_qty' => 2.5, 'current' => 188.475, 'row' => 195, 'pdf_pages' => []],
                ['boq' => 300, 'description' => 'Panel', 'unit' => 'ea', 'contract_qty' => 1, 'unit_price' => 900.005, 'current_qty' => 0.8, 'current' => 720.004, 'row' => 300, 'pdf_pages' => [11], 'group_id' => 'panel-fabrication'],
            ]];
    }

    private function import(?array $source = null): array
    {
        return app(ClaimSourceImportService::class)->import($this->contract->id, $source ?? $this->source, $this->sheet->id, $this->drawing->id);
    }

    public function test_source_import_is_unverified_idempotent_and_does_not_post_money(): void
    {
        $this->assertTrue($this->import()['success']);
        $this->assertSame(2, ContractBoqLine::where('status', 'draft')->count());
        $this->assertSame(2, ClaimWorkRecord::where('record_kind', 'source_claim')->whereNull('verified_qty')->count());
        $this->assertSame(0, PayApplication::count());
        $missing = ClaimWorkRecord::whereHas('line', fn ($q) => $q->where('line_no', '195'))->firstOrFail();
        $this->assertCount(1, $missing->evidence, 'No invented PDF match');
        $this->assertStringContainsString('추가 근거', $missing->notes);
        $panel = ContractBoqLine::where('line_no', '300')->firstOrFail();
        $this->assertSame('milestone', $panel->recognition_basis);
        $this->assertSame([], $panel->stage_weights, 'Source 80% is not an accepted contractual milestone');
        $this->assertSame('900.0050', $panel->unit_price);
        $this->assertTrue($this->import()['duplicate']);
        $this->assertSame(2, ClaimWorkRecord::count());
    }

    public function test_one_invalid_row_rolls_back_the_whole_import(): void
    {
        $this->source['items'][1]['current_qty'] = 2;
        $this->assertFalse($this->import()['success']);
        $this->assertSame(0, ContractBoqLine::count());
        $this->assertSame(0, ClaimWorkRecord::count());
        $this->assertEmpty($this->contract->fresh()->payload);
    }

    public function test_wrong_hash_private_document_and_cross_contract_source_are_rejected(): void
    {
        $bad = $this->source;
        $bad['sources']['workbook_sha256'] = str_repeat('0', 64);
        $this->assertFalse($this->import($bad)['success']);
        $this->sheet->update(['access_level' => 'private', 'owner_user_id' => auth()->id()]);
        $this->assertFalse($this->import()['success']);
        $other = $this->contract->replicate();
        $other->internal_reference = null;
        $other->save();
        $this->sheet->update(['access_level' => 'shared', 'project_contract_id' => $other->id]);
        $this->assertFalse($this->import()['success']);
        $this->assertSame(0, ClaimWorkRecord::count());
    }

    public function test_linked_document_cannot_lose_its_file_on_delete(): void
    {
        $this->assertTrue($this->import()['success']);
        try {
            $this->drawing->delete();
            $this->fail('Linked proof should be protected before the storage hook');
        } catch (ValidationException) {
            Storage::disk('local')->assertExists($this->drawing->file_path);
            $this->assertNotNull($this->drawing->fresh());
        }
    }
}
