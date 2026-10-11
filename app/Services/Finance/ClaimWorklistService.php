<?php

namespace App\Services\Finance;

use App\Models\ClaimSubmission;
use App\Models\PayApplication;
use App\Models\ProjectContract;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Carbon;

/** A read model over the existing billing ledger; no second financial totals store. */
class ClaimWorklistService
{
    public function get(array $filters = []): array
    {
        $result = app(BillingAdminService::class)->getBillingContracts($filters);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $ids = array_column($result['rows'], 'id');
        $contracts = ProjectContract::with('site:id,timezone')->whereIn('id', $ids)->get(['id', 'site_id', 'payload'])->keyBy('id');
        $applications = PayApplication::whereIn('project_contract_id', $ids)
            ->orderBy('application_no')->get(['id', 'project_contract_id', 'application_no', 'status'])->keyBy('project_contract_id');
        $submissions = ClaimSubmission::whereIn('pay_application_id', $applications->pluck('id'))
            ->orderBy('revision')->get(['id', 'pay_application_id', 'revision', 'status', 'sent_at'])->keyBy('pay_application_id');

        foreach ($result['rows'] as &$row) {
            $contract = $contracts->get($row['id']);
            $profile = ContractClaimProfile::read($contract);
            $application = $applications->get($row['id']);
            $submission = $application ? $submissions->get($application->id) : null;
            $confirmed = ContractClaimProfile::confirmed($contract);
            $day = (int) ($profile['submission_day'] ?? 0);
            $today = Carbon::today($contract->site?->timezone ?: config('app.timezone'));
            $due = $day > 0 ? $today->copy()->startOfMonth()->day(min($day, $today->daysInMonth)) : null;
            if ($due && $submission?->sent_at && $submission->sent_at->gte($due)) {
                $due = $due->addMonthNoOverflow()->day(min($day, $due->daysInMonth));
            }
            $row['submissionDue'] = $due?->toDateString();
            $row['preparationOn'] = $due?->copy()->subDays((int) ($profile['preparation_days'] ?? 7))->toDateString();
            $row['latestApplicationId'] = $application?->id;
            $row['submissionRevision'] = $submission?->revision;
            $row['submissionStatus'] = $submission?->status;
            $row['profileConfirmed'] = $confirmed;
            $row['nextAction'] = match (true) {
                ! $confirmed => '계약 청구조건 확인',
                $submission?->status === 'changes_requested' => '보완 후 다시 제출',
                (int) $row['unassignedCount'] > 0 => '입금 회차 연결',
                (float) $row['outstanding'] > 0 => '미수금 확인',
                $submission?->status === 'accepted' && $application?->status === 'submitted' => '승인 금액 기록',
                in_array($submission?->status, ['sent', 'received'], true) => '원청 회신 확인',
                $submission?->status === 'prepared' => '준비파일 검토 · 송부',
                $application?->status === 'draft' => '증빙 연결 · 제출 준비',
                ! $application => '확인 실적 · 첫 회차 준비',
                (float) $row['retainageHeld'] > 0 => '유보금 해제 확인',
                default => '다음 회차 확인',
            };
            $row['attention'] = ! $confirmed || $submission?->status === 'changes_requested'
                || (float) $row['outstanding'] > 0
                || ($due && $due->lt($today) && (! $submission || $submission->status === 'prepared'));
        }
        unset($row);

        // Closed sites remain here until their commercial/document obligations are resolved.
        return $result;
    }
}
