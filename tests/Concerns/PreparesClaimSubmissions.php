<?php

namespace Tests\Concerns;

use App\Models\ClaimSubmission;
use App\Models\PayApplication;
use App\Models\ProjectContractDocument;
use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimSubmissionService;
use App\Services\Finance\ContractClaimProfile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait PreparesClaimSubmissions
{
    /** Test contracts explicitly specify their submission rules; production has no such fallback. */
    protected function submitClaimFixture(int $id): array
    {
        $application = PayApplication::findOrFail($id);
        if ($application->status !== 'draft') {
            return app(BillingAdminService::class)->setBillingStatus(['id' => $id, 'action' => 'submit']);
        }
        $contract = $application->contract;
        if (! ContractClaimProfile::confirmed($contract)) {
            $contract->update(['payload' => array_merge($contract->payload ?? [], ['claim_profile' => [
                'version' => 1, 'confirmed_at' => now()->toIso8601String(), 'confirmed_by' => auth()->id(),
                'basis_note' => 'Fixture contract permits this billing format and stored materials.',
                'stored_materials' => true, 'submission_day' => 25, 'preparation_days' => 7,
                'template_label' => '', 'requirements' => [],
            ]])]);
        }
        $service = app(ClaimSubmissionService::class);
        $prepared = $service->prepare($id);
        if (! ($prepared['success'] ?? false)) {
            return $prepared;
        }
        return $service->transition([
            'submission_id' => $prepared['submission']['id'], 'status' => 'sent',
            'occurred_at' => now()->timezone($contract->site?->timezone ?: config('app.timezone'))->toDateString(),
            'reference' => 'Fixture portal receipt '.$id,
        ]);
    }

    protected function acceptClaimFixture(int $id): array
    {
        $submission = ClaimSubmission::where('pay_application_id', $id)->orderByDesc('revision')->first();
        if (! $submission || $submission->status === 'accepted') {
            return ['success' => true];
        }
        $path = 'test-claim-replies/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, 'GC confirms application '.$id.'; fixture approval response.');
        $document = ProjectContractDocument::create([
            'project_contract_id' => $submission->project_contract_id, 'disk' => 'local',
            'file_path' => $path, 'original_file_name' => 'gc-approval.txt',
            'title' => 'GC approval response', 'document_type' => 'correspondence',
            'version' => '1', 'status' => 'approved', 'is_current' => true, 'is_confidential' => false,
        ]);
        return app(ClaimSubmissionService::class)->transition([
            'submission_id' => $submission->id, 'status' => 'accepted',
            'occurred_at' => now()->timezone($submission->contract->site?->timezone ?: config('app.timezone'))->toDateString(),
            'reference' => 'GC acceptance '.$id,
            'response_document' => ['type' => 'contract_document', 'id' => $document->id],
        ]);
    }
}
