<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('claim_work_records')) {
            return;
        }
        // The old models sent Phoenix wall time without its offset to a UTC session.
        // Recover only untouched imports whose independent ISO import timestamp proves
        // that exact error. Current session timezone alone cannot prove how a row was written.
        $timezone = (string) config('app.timezone', 'UTC');
        DB::table('project_contracts')->whereNotNull('payload')->orderBy('id')->chunkById(100, function ($contracts) use ($timezone): void {
            foreach ($contracts as $candidate) {
                DB::transaction(function () use ($candidate, $timezone): void {
                    $contract = DB::table('project_contracts')->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $contract) {
                        return;
                    }
                    $payload = json_decode((string) $contract->payload, true);
                    if (! is_array($payload) || ! is_array($payload['claimSourceImports'] ?? null)) {
                        return;
                    }
                    $changed = false;
                    foreach ($payload['claimSourceImports'] as $hash => $import) {
                        if (! is_array($import) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $hash)
                            || isset($payload['claimTimestampRepairs'][$hash])) {
                            continue;
                        }
                        $expected = filter_var($import['rowCount'] ?? null, FILTER_VALIDATE_INT);
                        $iso = (string) ($import['importedAt'] ?? '');
                        if (! $expected || $expected > 2000 || ! preg_match('/T.*(?:[+-]\d{2}:\d{2}|Z)$/D', $iso)) {
                            continue;
                        }
                        try {
                            $importedAt = CarbonImmutable::parse($iso);
                            if ($importedAt->offset !== $importedAt->setTimezone($timezone)->offset) {
                                continue;
                            }
                        } catch (Throwable) {
                            continue;
                        }
                        $prefix = 'source:'.$hash.':';
                        $lines = DB::table('contract_boq_lines')->where('project_contract_id', $contract->id)->where('source_ref', 'like', $prefix.'%')->lockForUpdate()->get();
                        if ($lines->count() !== $expected || $lines->contains(fn ($row) => $row->status !== 'draft' || $row->accepted_at !== null || $row->accepted_by !== null || ! $this->untouched($row))) {
                            continue;
                        }
                        $lineIds = $lines->pluck('id')->all();
                        $records = DB::table('claim_work_records')->whereIn('contract_boq_line_id', $lineIds)->lockForUpdate()->get();
                        if ($records->count() !== $expected || $records->contains(fn ($row) => $row->record_kind !== 'source_claim' || $row->status !== 'pending' || $row->verified_qty !== null || $row->reviewed_at !== null || $row->reviewed_by !== null || ! str_starts_with((string) $row->source_ref, $prefix) || ! $this->untouched($row))) {
                            continue;
                        }
                        $recordIds = $records->pluck('id')->all();
                        if (DB::table('pay_application_allocations')->whereIn('claim_work_record_id', $recordIds)->exists()) {
                            continue;
                        }
                        $range = $this->range('claim_work_records', $recordIds, $timezone);
                        $lineRange = $this->range('contract_boq_lines', $lineIds, $timezone);
                        if (abs($importedAt->getTimestamp() - CarbonImmutable::parse($range->old_latest)->getTimestamp()) <= 300
                            || ! $this->nearImport($range, $importedAt) || ! $this->nearImport($lineRange, $importedAt)) {
                            continue;
                        }
                        $this->repair('contract_boq_lines', $lineIds, $timezone);
                        $this->repair('claim_work_records', $recordIds, $timezone);
                        $payload['claimTimestampRepairs'][$hash] = [
                            'migration' => '2026_09_24_000210',
                            'repairedAt' => CarbonImmutable::now($timezone)->toIso8601String(),
                            'sourceTimezone' => $timezone, 'misinterpretedTimezone' => 'UTC',
                            'rowCount' => $expected, 'oldLatest' => $range->old_latest,
                            'correctedLatest' => $range->corrected_latest,
                        ];
                        $changed = true;
                    }
                    if ($changed) {
                        // Original claim import metadata and contractual timestamps remain intact.
                        DB::table('project_contracts')->where('id', $contract->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                    }
                });
            }
        });
    }

    private function untouched(object $row): bool
    {
        return $row->created_at !== null && $row->updated_at !== null
            && CarbonImmutable::parse($row->created_at)->equalTo(CarbonImmutable::parse($row->updated_at));
    }

    private function range(string $table, array $ids, string $timezone): object
    {
        $slots = implode(',', array_fill(0, count($ids), '?'));

        return DB::selectOne("SELECT max(created_at) AS old_latest, min((created_at AT TIME ZONE 'UTC') AT TIME ZONE ?) AS corrected_earliest, max((created_at AT TIME ZONE 'UTC') AT TIME ZONE ?) AS corrected_latest FROM {$table} WHERE id IN ({$slots})", [$timezone, $timezone, ...$ids]);
    }

    private function nearImport(object $range, CarbonImmutable $importedAt): bool
    {
        return abs($importedAt->getTimestamp() - CarbonImmutable::parse($range->corrected_earliest)->getTimestamp()) <= 300
            && abs($importedAt->getTimestamp() - CarbonImmutable::parse($range->corrected_latest)->getTimestamp()) <= 300;
    }

    private function repair(string $table, array $ids, string $timezone): void
    {
        $slots = implode(',', array_fill(0, count($ids), '?'));
        DB::update("UPDATE {$table} SET created_at = (created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, updated_at = (updated_at AT TIME ZONE 'UTC') AT TIME ZONE ? WHERE id IN ({$slots})", [$timezone, $timezone, ...$ids]);
    }

    public function down(): void
    {
        // An audit correction is not reversed: doing so would knowingly restore false instants.
    }
};
