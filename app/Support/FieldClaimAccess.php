<?php

namespace App\Support;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\Scopes\JobDataScope;
use App\Models\User;
use App\Models\WorkSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** A quantity-only workspace; money-bearing original documents stay with reviewers. */
final class FieldClaimAccess
{
    public static function restricted(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user?->job_role === 'claim_recorder' && $user->access_role !== 'super_admin';
    }

    public static function sections(): Builder
    {
        $user = auth()->user();
        abort_unless(self::restricted($user) && $user->account_status === 'active'
            && $user->access_scope === 'trade' && filled($user->job_trade)
            && JobAccess::can($user, 'progress'), 403);

        return WorkSection::query()->where('company_id', $user->allowed_company_id ?: 0)
            ->whereIn('site_id', JobAccess::siteIds($user))->where('claim_trade', $user->job_trade);
    }

    public static function line(int $id, bool $write = false): ContractBoqLine
    {
        abort_if($write && ! JobAccess::can(auth()->user(), 'progress', 'edit'), 403);
        $line = ContractBoqLine::query()->whereIn('work_section_id', self::sections()->select('id'))->findOrFail($id);
        $contract = $line->contract;
        abort_unless($contract && $contract->direction === 'receivable'
            && (int) $contract->company_id === (int) auth()->user()->allowed_company_id
            && in_array((int) $contract->site_id, array_map('intval', JobAccess::siteIds(auth()->user())), true), 404);

        return $line;
    }

    public static function editable(?ClaimWorkRecord $record): void
    {
        if ($record) {
            abort_unless((int) $record->reported_by === (int) auth()->id()
                && $record->record_kind === 'actual' && $record->status === 'pending', 403);
        }
    }

    public static function evidence(ContractBoqLine $line, array $references): array
    {
        abort_if(count($references) > 20, 422);

        return array_map(function (array $ref) use ($line): array {
            $attachment = DB::table('field_claim_attachments')->where('contract_boq_line_id', $line->id)
                ->where('user_id', auth()->id())->where('id', (int) ($ref['attachmentId'] ?? 0))->first();
            abort_unless($attachment, 403);
            // The upload receipt authorizes this exact immutable original, never a document search.
            $doc = IntelligentDocument::withoutGlobalScope(JobDataScope::class)->findOrFail($attachment->intelligent_document_id);
            abort_unless(hash_equals($attachment->sha256, (string) $doc->sha256)
                && (int) $doc->company_id === (int) $line->contract->company_id
                && (int) $doc->site_id === (int) $line->contract->site_id
                && (! $doc->project_contract_id || (int) $doc->project_contract_id === (int) $line->project_contract_id)
                && Storage::disk($doc->disk)->exists($doc->file_path), 409);
            $locator = trim((string) ($ref['locator'] ?? '문서 전체'));
            abort_if($locator === '' || mb_strlen($locator) > 255, 422);

            return ['type' => 'document', 'id' => $doc->id, 'locator' => $locator,
                'title' => $attachment->original_name, 'sha256' => $doc->sha256, 'revision' => $doc->revision,
                'filePath' => $doc->file_path, 'disk' => $doc->disk,
                'url' => route('document-intelligence.preview', $doc, false), 'note' => null];
        }, $references);
    }
}
