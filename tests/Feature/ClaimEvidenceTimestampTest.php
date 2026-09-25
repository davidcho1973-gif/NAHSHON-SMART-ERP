<?php

namespace Tests\Feature;

use App\Models\ClaimWorkRecord;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClaimEvidenceTimestampTest extends TestCase
{
    use RefreshDatabase;

    private string $phpTimezone;

    private string $appTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phpTimezone = date_default_timezone_get();
        $this->appTimezone = config('app.timezone');
        config(['app.timezone' => 'America/Phoenix']);
        date_default_timezone_set('America/Phoenix');
        $this->travelTo(Carbon::parse('2026-09-23T17:05:45-07:00'));
        // A same-zone local database hid the production failure. Exercise the real boundary.
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        config(['app.timezone' => $this->appTimezone]);
        date_default_timezone_set($this->phpTimezone);
        parent::tearDown();
    }

    public function test_three_models_preserve_timestamp_instants_in_utc_database_session(): void
    {
        [$contract, $line, $record] = $this->importFixture(false);
        $line->update(['accepted_at' => now()]);
        $record->update(['reviewed_at' => now()]);
        $application = PayApplication::create(['project_contract_id' => $contract->id, 'period_end' => '2026-09-30']);
        $allocation = PayApplicationAllocation::create(['pay_application_id' => $application->id, 'claim_work_record_id' => $record->id, 'quantity' => 1, 'amount' => 10, 'snapshot' => []]);
        foreach ([$line->fresh(), $record->fresh(), $allocation->fresh()] as $row) {
            $this->assertSame(now()->getTimestamp(), $row->created_at->getTimestamp());
            $this->assertSame(now()->getTimestamp(), $row->updated_at->getTimestamp());
        }
        $this->assertSame(now()->getTimestamp(), $line->fresh()->accepted_at->getTimestamp());
        $this->assertSame(now()->getTimestamp(), $record->fresh()->reviewed_at->getTimestamp());
        $this->assertSame('2026-09-23', $record->fresh()->work_date->toDateString());
        $this->assertSame('2026-09-24 00:05:45+00', DB::table('claim_work_records')->where('id', $record->id)->value('created_at'));
    }

    public function test_faulty_untouched_source_import_is_repaired_once_with_audit_marker(): void
    {
        [$contract, $line, $record, $hash] = $this->importFixture(true);
        $oldImport = $contract->payload['claimSourceImports'];
        $this->assertSame(now()->getTimestamp() - 7 * 3600, $record->fresh()->created_at->getTimestamp());
        $this->repair();
        $this->assertSame(now()->getTimestamp(), $record->fresh()->created_at->getTimestamp());
        $this->assertSame(now()->getTimestamp(), $record->fresh()->updated_at->getTimestamp());
        $this->assertSame(now()->getTimestamp(), $line->fresh()->created_at->getTimestamp());
        $this->assertSame($oldImport, $contract->fresh()->payload['claimSourceImports']);
        $this->assertSame(1, $contract->fresh()->payload['claimTimestampRepairs'][$hash]['rowCount']);
        $after = DB::table('claim_work_records')->where('id', $record->id)->first();
        $marker = $contract->fresh()->payload['claimTimestampRepairs'];
        $this->repair();
        $this->assertEquals($after, DB::table('claim_work_records')->where('id', $record->id)->first());
        $this->assertSame($marker, $contract->fresh()->payload['claimTimestampRepairs']);
    }

    public function test_already_correct_import_is_left_untouched(): void
    {
        [$contract, $line, $record] = $this->importFixture(false);
        $before = DB::table('claim_work_records')->where('id', $record->id)->first();
        $this->repair();
        $this->assertEquals($before, DB::table('claim_work_records')->where('id', $record->id)->first());
        $this->assertSame(now()->getTimestamp(), $line->fresh()->created_at->getTimestamp());
        $this->assertArrayNotHasKey('claimTimestampRepairs', $contract->fresh()->payload);
    }

    public function test_reviewed_import_is_left_untouched(): void
    {
        [$contract, $line, $record] = $this->importFixture(true);
        DB::table('claim_work_records')->where('id', $record->id)->update(['reviewed_at' => '2026-09-23 17:05:45+00']);
        $before = DB::table('claim_work_records')->where('id', $record->id)->first();
        $this->repair();
        $this->assertEquals($before, DB::table('claim_work_records')->where('id', $record->id)->first());
        $this->assertSame(now()->getTimestamp() - 7 * 3600, $line->fresh()->created_at->getTimestamp());
        $this->assertArrayNotHasKey('claimTimestampRepairs', $contract->fresh()->payload);
    }

    public function test_edited_import_is_left_untouched(): void
    {
        [$contract, $line, $record] = $this->importFixture(true);
        DB::table('claim_work_records')->where('id', $record->id)->update(['updated_at' => '2026-09-23 17:06:45+00']);
        $before = DB::table('claim_work_records')->where('id', $record->id)->first();
        $this->repair();
        $this->assertEquals($before, DB::table('claim_work_records')->where('id', $record->id)->first());
        $this->assertSame(now()->getTimestamp() - 7 * 3600, $line->fresh()->created_at->getTimestamp());
        $this->assertArrayNotHasKey('claimTimestampRepairs', $contract->fresh()->payload);
    }

    private function importFixture(bool $legacy): array
    {
        $company = Company::create(['code' => 'TS', 'name' => 'Timestamp test', 'status' => 'active']);
        $contract = ProjectContract::create(['company_id' => $company->id, 'title' => 'Timestamp probe', 'direction' => 'receivable', 'original_amount' => 100]);
        $hash = hash('sha256', 'timestamp-fixture');
        $line = new ContractBoqLine;
        $record = new ClaimWorkRecord;
        if ($legacy) {
            $line->setDateFormat('Y-m-d H:i:s');
            $record->setDateFormat('Y-m-d H:i:s');
        }
        $line->fill(['project_contract_id' => $contract->id, 'line_no' => '15', 'description' => 'Source item', 'unit' => 'EA', 'contract_qty' => 10, 'unit_price' => 10, 'status' => 'draft', 'source_ref' => 'source:'.$hash.':15'])->save();
        $record->fill(['contract_boq_line_id' => $line->id, 'record_kind' => 'source_claim', 'work_date' => '2026-09-23', 'location' => 'Source import', 'stage' => 'installed', 'reported_qty' => 1, 'status' => 'pending', 'source_ref' => 'source:'.$hash.':15'])->save();
        $contract->update(['payload' => ['claimSourceImports' => [$hash => ['rowCount' => 1, 'importedAt' => now()->addSeconds(5)->toIso8601String(), 'notice' => 'Unverified source only']]]]);

        return [$contract, $line, $record, $hash];
    }

    private function repair(): void
    {
        (require database_path('migrations/2026_09_24_000210_repair_untouched_claim_import_timestamps.php'))->up();
    }
}
