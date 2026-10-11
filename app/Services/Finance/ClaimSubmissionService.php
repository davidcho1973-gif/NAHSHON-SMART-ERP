<?php

namespace App\Services\Finance;

use App\Models\ClaimSubmission;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Services\Admin\BillingAdminService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use ZipArchive;

/**
 * Billing amounts and an outward submission are different records. Freezing the
 * packet fixes the lost evidence/version problem without duplicating the ledger.
 */
class ClaimSubmissionService
{
    private const MAX_FILE_BYTES = 26214400;
    private const MAX_PACKET_BYTES = 104857600;

    public function __construct(private readonly BillingAdminService $billing) {}

    public function workspace(int $applicationId): array
    {
        return $this->respond(function () use ($applicationId): array {
            [$application, $contract] = $this->context($applicationId, false);
            $profile = ContractClaimProfile::read($contract);
            $historicalDate = $this->historicalSubmittedOn($application);
            $evidence = $this->draftEvidence($application, $profile);
            $requirements = [];
            foreach ($profile['requirements'] ?? [] as $requirement) {
                $active = $this->active($requirement, $application);
                $sources = [];
                foreach ($evidence[$requirement['key']] ?? [] as $ref) {
                    try {
                        $sources[] = $this->source($contract, $ref, true, null, $historicalDate)['display'];
                    } catch (InvalidArgumentException) {
                        $sources[] = ['type' => $ref['type'] ?? '', 'id' => $ref['id'] ?? 0, 'label' => '자료를 다시 선택하세요.', 'unavailable' => true];
                    }
                }
                $requirements[] = $requirement + [
                    'active' => $active, 'evidence' => $sources,
                    'missing' => $active && ($requirement['required'] ?? false) && (! $sources || collect($sources)->contains('unavailable', true)),
                ];
            }

            return [
                'success' => true,
                'application' => [
                    'id' => $application->id, 'applicationNo' => $application->application_no,
                    'type' => $application->type, 'status' => $application->status,
                    'historicalSubmittedOn' => $historicalDate,
                    'periodStart' => $application->period_start?->toDateString(),
                    'periodEnd' => $application->period_end?->toDateString(),
                    'amountDue' => (float) $application->amount_due, 'currency' => $contract->currency,
                ],
                'profile' => $profile, 'profileConfirmed' => ContractClaimProfile::confirmed($contract),
                'requirements' => $requirements, 'draftEvidence' => $evidence,
                'sourceOptions' => $this->sourceOptions($contract, $historicalDate),
                'submissions' => ClaimSubmission::where('pay_application_id', $application->id)->orderByDesc('revision')->get()->map(fn ($row) => $this->row($row))->all(),
                'canManage' => $this->billing->canManage(),
            ];
        });
    }

    public function saveEvidence(array $input): array
    {
        return $this->respond(function () use ($input): array {
            [$application, $contract] = $this->context((int) ($input['application_id'] ?? 0));
            return DB::transaction(function () use ($application, $contract, $input): array {
                $this->lock($application, $contract);
                $this->editable($application);
                $profile = ContractClaimProfile::read($contract);
                $incoming = $input['evidence'] ?? [];
                if (! is_array($incoming) || count($incoming) > 100) {
                    throw new InvalidArgumentException('제출 요건별 문서 목록을 확인하세요.');
                }
                $known = collect($profile['requirements'] ?? [])->keyBy('key');
                $evidence = [];
                foreach ($incoming as $key => $refs) {
                    if (! $known->has($key) || ! is_array($refs) || count($refs) > 20) {
                        throw new InvalidArgumentException('계약의 제출 요건과 문서 목록이 일치하지 않습니다.');
                    }
                    $evidence[$key] = [];
                    foreach ($refs as $ref) {
                        $source = $this->source($contract, is_array($ref) ? $ref : [], true, null, $this->historicalSubmittedOn($application));
                        $evidence[$key][$source['display']['value']] = ['type' => $source['display']['type'], 'id' => $source['display']['id']];
                    }
                    $evidence[$key] = array_values($evidence[$key]);
                }
                $application->update(['payload' => array_merge($application->payload ?? [], ['claimSubmissionEvidence' => $evidence])]);
                return ['success' => true, 'applicationId' => $application->id];
            });
        });
    }

    public function prepare(int $applicationId): array
    {
        return $this->respond(function () use ($applicationId): array {
            [$application, $contract] = $this->context($applicationId);
            $storedPath = null;
            $temporaryFiles = [];
            $diskName = (string) config('document-intelligence.disk', 'local');
            try {
                return DB::transaction(function () use ($application, $contract, $diskName, &$storedPath, &$temporaryFiles): array {
                    $this->lock($application, $contract);
                    $this->editable($application);
                    if (! ContractClaimProfile::confirmed($contract)) {
                        throw new InvalidArgumentException('계약별 제출 조건과 근거를 먼저 확인하세요.');
                    }
                    $profile = ContractClaimProfile::read($contract);
                    $historicalDate = $this->historicalSubmittedOn($application);
                    $this->assertMaterialRules($application, $profile);
                    if ($application->type !== 'retainage_release' && ContractBoqLine::where('project_contract_id', $contract->id)->exists()) {
                        $error = app(ClaimEvidenceService::class)->validateApplication($application);
                        if ($error) {
                            throw new InvalidArgumentException($error);
                        }
                    }
                    $evidence = $this->draftEvidence($application, $profile);
                    $files = [];
                    $manifest = [];
                    $totalBytes = 0;
                    foreach ($profile['requirements'] ?? [] as $requirement) {
                        if (! $this->active($requirement, $application)) {
                            continue;
                        }
                        $refs = $evidence[$requirement['key']] ?? [];
                        if (($requirement['required'] ?? false) && ! $refs) {
                            throw new InvalidArgumentException('필수 자료가 없습니다: '.$requirement['title']);
                        }
                        foreach ($refs as $ref) {
                            $source = $this->source($contract, $ref, true, null, $historicalDate);
                            $key = $source['display']['value'];
                            if (! isset($files[$key])) {
                                $bytes = $this->readVerified($source['disk'], $source['path'], $source['hash']);
                                $totalBytes += strlen($bytes);
                                if ($totalBytes > self::MAX_PACKET_BYTES || count($files) >= 100) {
                                    throw new InvalidArgumentException('제출본은 원본 100개, 합계 100MB 이내로 준비하세요.');
                                }
                                $name = 'documents/'.$source['display']['type'].'-'.$source['display']['id'].'-'.$this->safeName($source['name']);
                                $temporary = tempnam(sys_get_temp_dir(), 'claim-source-');
                                if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                                    if ($temporary !== false) {
                                        @unlink($temporary);
                                    }
                                    throw new InvalidArgumentException('제출 원본을 임시 보존할 수 없습니다.');
                                }
                                $temporaryFiles[] = $temporary;
                                $files[$key] = ['name' => $name, 'path' => $temporary, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
                                unset($bytes);
                            }
                            $manifest[] = [
                                'requirement' => $requirement['key'], 'title' => $requirement['title'],
                                'source' => ['type' => $source['display']['type'], 'id' => $source['display']['id']],
                                'file' => $files[$key]['name'], 'sha256' => $files[$key]['sha256'],
                                'bytes' => $files[$key]['bytes'], 'version' => $source['display']['version'],
                                'expiresOn' => $source['display']['expiresOn'],
                            ];
                        }
                    }
                    $snapshot = $this->financialSnapshot($application);
                    $revision = 1 + (int) ClaimSubmission::where('pay_application_id', $application->id)->max('revision');
                    $package = [
                        'contract' => ['id' => $contract->id, 'number' => $contract->contract_number, 'title' => $contract->title, 'currency' => $contract->currency],
                        'application' => $snapshot, 'revision' => $revision,
                        'profileVersion' => $profile['version'], 'profile' => $profile,
                        'preparedAt' => now()->toIso8601String(), 'documents' => $manifest,
                        'historicalSubmittedOn' => $historicalDate,
                        'documentsValidatedOn' => $historicalDate ?? Carbon::today($contract->site?->timezone ?: config('app.timezone'))->toDateString(),
                        'lines' => $this->claimLines($application),
                    ];
                    $zipPath = $this->zip($package, $files);
                    $temporaryFiles[] = $zipPath;
                    $zipHash = hash_file('sha256', $zipPath);
                    $zipSize = filesize($zipPath);
                    $storedPath = 'claim-submissions/'.$contract->id.'/'.$application->id.'/'.Str::uuid().'.zip';
                    $stream = fopen($zipPath, 'rb');
                    if (! is_resource($stream)) {
                        throw new InvalidArgumentException('제출본 ZIP을 읽을 수 없습니다.');
                    }
                    try {
                        if (! Storage::disk($diskName)->put($storedPath, $stream, ['visibility' => 'private'])) {
                            throw new InvalidArgumentException('제출본을 저장하지 못했습니다.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $this->verifyArchive($diskName, $storedPath, $zipHash);
                    $submission = ClaimSubmission::create([
                        'project_contract_id' => $contract->id, 'pay_application_id' => $application->id,
                        'created_by' => auth()->id(), 'revision' => $revision, 'status' => 'prepared',
                        'profile_version' => $profile['version'], 'profile_snapshot' => $profile,
                        'application_snapshot' => $snapshot, 'evidence' => $evidence, 'manifest' => $package,
                        'disk' => $diskName, 'file_path' => $storedPath,
                        'file_name' => 'claim-'.$application->internal_reference.'-r'.$revision.'.zip',
                        'sha256' => $zipHash, 'file_size' => $zipSize, 'prepared_at' => now(),
                        'history' => [['status' => 'prepared', 'recordedAt' => now()->toIso8601String(), 'actorId' => auth()->id()]],
                    ]);
                    return ['success' => true, 'submission' => $this->row($submission)];
                });
            } catch (Throwable $e) {
                if ($storedPath) {
                    Storage::disk($diskName)->delete($storedPath);
                }
                throw $e;
            } finally {
                foreach ($temporaryFiles as $temporary) {
                    @unlink($temporary);
                }
            }
        });
    }

    public function transition(array $input): array
    {
        return $this->respond(function () use ($input): array {
            $submission = ClaimSubmission::find((int) ($input['submission_id'] ?? 0));
            if (! $submission) {
                throw new InvalidArgumentException('제출본을 찾을 수 없습니다.');
            }
            [$application, $contract] = $this->context($submission->pay_application_id);
            $responsePath = null;
            try {
                return DB::transaction(function () use ($submission, $application, $contract, $input, &$responsePath): array {
                    $this->lock($application, $contract);
                    $submission->refresh();
                    if ((int) ClaimSubmission::where('pay_application_id', $application->id)->max('revision') !== $submission->revision) {
                        throw new InvalidArgumentException('이전 제출본의 상태는 변경할 수 없습니다. 최신 개정본을 선택하세요.');
                    }
                    if (! in_array($application->status, ['draft', 'submitted'], true)) {
                        throw new InvalidArgumentException('승인·수금된 회차의 제출 이력은 변경할 수 없습니다.');
                    }
                    $status = (string) ($input['status'] ?? '');
                    $allowed = ['prepared' => ['sent'], 'sent' => ['received', 'changes_requested', 'accepted'], 'received' => ['changes_requested', 'accepted']];
                    if (! in_array($status, $allowed[$submission->status] ?? [], true)) {
                        throw new InvalidArgumentException('현재 제출본에서 허용되지 않는 상태 변경입니다.');
                    }
                    $date = $this->date($input['occurred_at'] ?? null);
                    $today = Carbon::today($contract->site?->timezone ?: config('app.timezone'))->toDateString();
                    $earliest = ($submission->received_at ?? $submission->sent_at)?->toDateString()
                        ?? ($submission->manifest['historicalSubmittedOn'] ?? $submission->prepared_at->copy()->timezone($contract->site?->timezone ?: config('app.timezone'))->toDateString());
                    if ($date > $today || $date < $earliest) {
                        throw new InvalidArgumentException('처리일은 이전 처리일 이후이며 현장 오늘 날짜 이하여야 합니다.');
                    }
                    $reference = trim((string) ($input['reference'] ?? ''));
                    if ($reference === '' || mb_strlen($reference) > 255) {
                        throw new InvalidArgumentException('실제 제출·응답의 접수번호 또는 참조를 255자 이내로 입력하세요.');
                    }
                    $note = trim((string) ($input['note'] ?? ''));
                    if ($status === 'changes_requested' && $note === '') {
                        throw new InvalidArgumentException('수정 요청 사유를 입력하세요.');
                    }
                    if (mb_strlen($note) > 2000) {
                        throw new InvalidArgumentException('처리 메모는 2000자 이내로 입력하세요.');
                    }
                    $proof = null;
                    if ($status === 'sent') {
                        $this->assertCurrent($submission, $application, $contract);
                    } else {
                        $source = $this->source($contract, is_array($input['response_document'] ?? null) ? $input['response_document'] : [], true, null, $date);
                        $bytes = $this->readVerified($source['disk'], $source['path'], $source['hash']);
                        $responsePath = 'claim-submissions/'.$contract->id.'/'.$application->id.'/responses/'.Str::uuid();
                        $this->writeVerified($submission->disk, $responsePath, $bytes);
                        $proof = $source['display'] + [
                            'disk' => $submission->disk, 'path' => $responsePath,
                            'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'name' => $source['name'],
                        ];
                    }
                    $event = [
                        'status' => $status, 'occurredAt' => $date, 'recordedAt' => now()->toIso8601String(),
                        'actorId' => auth()->id(), 'reference' => $reference, 'note' => $note,
                        'responseDocument' => $proof,
                    ];
                    $changes = ['status' => $status, 'reference' => $reference, 'history' => array_merge($submission->history ?? [], [$event])];
                    if ($status === 'sent') {
                        $changes += ['sent_at' => $date, 'sent_reference' => $reference];
                    } elseif ($status === 'received') {
                        $changes += ['received_at' => $date, 'response_document' => $proof];
                    } else {
                        $changes += ['response_at' => $date, 'response_document' => $proof];
                    }
                    $submission->update($changes);
                    $billingResult = ['success' => true];
                    if ($status === 'sent' && $application->status === 'draft') {
                        $billingResult = $this->billing->setBillingStatus(['id' => $application->id, 'action' => 'submit']);
                        if (! ($billingResult['success'] ?? false)) {
                            if (! empty($billingResult['errors'])) {
                                throw ValidationException::withMessages($billingResult['errors']);
                            }
                            throw new InvalidArgumentException($billingResult['error'] ?? implode(' ', $billingResult['errors'] ?? ['청구 제출 검증에 실패했습니다.']));
                        }
                    }
                    return $billingResult + ['submission' => $this->row($submission->fresh())];
                });
            } catch (Throwable $e) {
                if ($responsePath) {
                    Storage::disk($submission->disk)->delete($responsePath);
                }
                throw $e;
            }
        });
    }

    /** BillingAdmin calls these gates while holding the same contract lock. */
    public function validateSubmission(PayApplication $application): ?string
    {
        try {
            $submission = ClaimSubmission::where('pay_application_id', $application->id)->orderByDesc('revision')->first();
            if (! $submission || $submission->status !== 'sent' || ! $submission->sent_at || ! filled($submission->sent_reference)) {
                throw new InvalidArgumentException('제출본을 준비한 뒤 실제 제출일과 접수 참조를 기록하세요. 다운로드는 제출이 아닙니다.');
            }
            $this->assertCurrent($submission, $application, $application->contract);
            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function validateApproval(PayApplication $application): ?string
    {
        try {
            $submission = ClaimSubmission::where('pay_application_id', $application->id)->orderByDesc('revision')->first();
            if (! $submission || $submission->status !== 'accepted' || ! $submission->response_at || ! $submission->response_document) {
                throw new InvalidArgumentException('원청의 승인 응답 문서를 연결하고 제출본의 수리 상태를 기록한 뒤 승인액을 입력하세요.');
            }
            $proof = $submission->response_document;
            $this->readVerified($proof['disk'], $proof['path'], $proof['sha256']);
            if ($submission->application_snapshot !== $this->financialSnapshot($application)) {
                throw new InvalidArgumentException('승인 응답의 제출본과 청구 금액이 다릅니다.');
            }
            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function getDownload(int $id): ClaimSubmission
    {
        $submission = ClaimSubmission::find($id);
        if (! $submission) {
            throw new InvalidArgumentException('제출본을 찾을 수 없습니다.');
        }
        [, $contract] = $this->context($submission->pay_application_id, false);
        foreach ($submission->manifest['documents'] ?? [] as $item) {
            $ref = $item['source'];
            $exists = ($ref['type'] === 'document' ? IntelligentDocument::query() : ProjectContractDocument::query())->whereKey($ref['id'])->exists();
            if ($exists) {
                // A retained original survives expiry/deletion, but a later privacy restriction
                // must not turn an older archive into a route around current access control.
                $this->source($contract, $ref, false);
            }
        }
        $this->verifyArchive($submission->disk, $submission->file_path, $submission->sha256);
        return $submission;
    }

    public function getResponseDownload(int $submissionId, int $eventIndex): array
    {
        $submission = ClaimSubmission::find($submissionId);
        if (! $submission || $eventIndex < 0) {
            throw new InvalidArgumentException('보존한 응답을 찾을 수 없습니다.');
        }
        [, $contract] = $this->context($submission->pay_application_id, false);
        $proof = $submission->history[$eventIndex]['responseDocument'] ?? null;
        if (! is_array($proof) || empty($proof['path']) || empty($proof['sha256'])) {
            throw new InvalidArgumentException('이 처리 이력에는 보존한 응답 파일이 없습니다.');
        }
        $exists = ($proof['type'] === 'document' ? IntelligentDocument::query() : ProjectContractDocument::query())->whereKey($proof['id'])->exists();
        if ($exists) {
            $this->source($contract, $proof, false);
        }
        $this->readVerified($proof['disk'], $proof['path'], $proof['sha256']);
        return ['disk' => $proof['disk'], 'path' => $proof['path'], 'name' => $proof['name'] ?? 'gc-response'];
    }

    private function context(int $applicationId, bool $write = true): array
    {
        if ($write ? ! $this->billing->canManage() : ! $this->billing->canView()) {
            throw new InvalidArgumentException('기성 제출본 접근 권한이 없습니다.');
        }
        $application = PayApplication::find($applicationId);
        $contract = $application ? $this->billing->findAccessibleContract($application->project_contract_id) : null;
        if (! $application || ! $contract || $contract->direction !== 'receivable') {
            throw new InvalidArgumentException('접근 가능한 기성 회차를 찾을 수 없습니다.');
        }
        return [$application, $contract];
    }

    private function lock(PayApplication $application, ProjectContract $contract): void
    {
        ProjectContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
        $contract->refresh();
        $application->refresh();
    }

    private function historicalSubmittedOn(PayApplication $application): ?string
    {
        if ($application->status !== 'submitted' || ! $application->submitted_on
            || ClaimSubmission::where('pay_application_id', $application->id)->whereNotNull('sent_at')->exists()) {
            return null;
        }
        return $application->submitted_on->toDateString();
    }

    private function editable(PayApplication $application): void
    {
        $latest = ClaimSubmission::where('pay_application_id', $application->id)->orderByDesc('revision')->first();
        if (! in_array($application->status, ['draft', 'submitted'], true)
            || ($latest && ! in_array($latest->status, ['prepared', 'changes_requested'], true))) {
            throw new InvalidArgumentException('작성 중 또는 수정 요청된 제출본만 준비할 수 있습니다. 청구 금액 정정은 별도 회차에서 처리하세요.');
        }
    }

    private function draftEvidence(PayApplication $application, array $profile): array
    {
        $payload = $application->payload ?? [];
        if (array_key_exists('claimSubmissionEvidence', $payload)) {
            return (array) $payload['claimSubmissionEvidence'];
        }
        $latest = ClaimSubmission::where('pay_application_id', $application->id)->orderByDesc('revision')->first();
        if ($latest) {
            return $latest->evidence;
        }
        $previous = ClaimSubmission::where('project_contract_id', $application->project_contract_id)
            ->whereNotNull('sent_at')->where('pay_application_id', '!=', $application->id)->orderByDesc('id')->first();
        $evidence = [];
        foreach ($profile['requirements'] ?? [] as $requirement) {
            if (($requirement['stage'] ?? '') === 'once' && isset($previous?->evidence[$requirement['key']])) {
                $evidence[$requirement['key']] = $previous->evidence[$requirement['key']];
            }
        }
        return $evidence;
    }

    private function active(array $requirement, PayApplication $application): bool
    {
        return ($requirement['stage'] ?? 'each_claim') !== 'final' || in_array($application->type, ['final', 'retainage_release'], true);
    }

    private function source(ProjectContract $contract, array $ref, bool $current = true, IntelligentDocument|ProjectContractDocument|null $loaded = null, ?string $validOn = null): array
    {
        $type = $ref['type'] ?? '';
        $id = (int) ($ref['id'] ?? 0);
        if ($type === 'document') {
            $document = $loaded instanceof IntelligentDocument && (int) $loaded->id === $id
                ? $loaded : IntelligentDocument::visibleTo(auth()->user())->find($id);
            $valid = $document && $document->access_level !== 'private'
                && ! in_array($document->confidentiality, ['confidential', 'restricted'], true)
                && (! $document->company_id || (int) $document->company_id === (int) $contract->company_id)
                && (! $document->project_contract_id || (int) $document->project_contract_id === (int) $contract->id)
                && (! $document->project_id || (int) $document->project_id === (int) $contract->project_id)
                && (! $document->site_id || (int) $document->site_id === (int) $contract->site_id)
                && ((int) $document->project_contract_id === (int) $contract->id
                    || ($contract->project_id && (int) $document->project_id === (int) $contract->project_id));
            // Unscoped corporate documents must be explicitly linked and reviewed before reuse.
            if ($valid && ! $document->project_id && ! $document->site_id && $document->status !== 'approved') {
                $valid = false;
            }
            $version = $document?->revision;
            $hash = $document?->sha256;
        } elseif ($type === 'contract_document') {
            $document = $loaded instanceof ProjectContractDocument && (int) $loaded->id === $id && (int) $loaded->project_contract_id === (int) $contract->id
                ? $loaded : ProjectContractDocument::where('project_contract_id', $contract->id)->find($id);
            $valid = $document && ! $document->is_confidential && (! $current || $document->is_current);
            $version = $document?->version;
            $hash = data_get($document?->payload, 'sha256');
        } else {
            throw new InvalidArgumentException('문서함 또는 계약 문서의 실제 파일을 선택하세요.');
        }
        if (! $valid || ($current && in_array($document->status, ['rejected', 'superseded'], true))
            || ($current && $document->status === 'expired' && (! $validOn || ! $document->expires_on))) {
            throw new InvalidArgumentException('계약·프로젝트에 속한 공유 문서만 제출 근거로 사용할 수 있습니다.');
        }
        $today = $validOn ?? Carbon::today($contract->site?->timezone ?: config('app.timezone'))->toDateString();
        if ($current && $document->expires_on && $document->expires_on->toDateString() < $today) {
            throw new InvalidArgumentException('만료된 문서는 제출 근거로 사용할 수 없습니다.');
        }
        if ($current && $document->effective_on && $document->effective_on->toDateString() > $today) {
            throw new InvalidArgumentException('아직 유효하지 않은 문서입니다.');
        }
        if ($current && ! filled($document->file_path)) {
            throw new InvalidArgumentException('선택한 문서의 원본 파일이 없습니다.');
        }
        $name = $document->original_file_name ?: ('document-'.$id);
        return [
            'display' => [
                'type' => $type, 'id' => $document->id, 'value' => $type.':'.$document->id,
                'label' => ($document->title ?: $name).' (#'.$document->id.')',
                'version' => $version, 'expiresOn' => $document->expires_on?->toDateString(),
            ],
            'disk' => $document->disk ?: (string) config('document-intelligence.disk', 'local'),
            'path' => $document->file_path, 'hash' => $hash, 'name' => $name,
        ];
    }

    private function sourceOptions(ProjectContract $contract, ?string $validOn = null): array
    {
        $documents = IntelligentDocument::visibleTo(auth()->user())->where(function ($query) use ($contract): void {
            $query->where('project_contract_id', $contract->id);
            if ($contract->project_id) {
                $query->orWhere('project_id', $contract->project_id);
            }
        })->orderByDesc('id')->limit(300)->get();
        $candidates = $documents->map(fn ($row) => ['ref' => ['type' => 'document', 'id' => $row->id], 'model' => $row])->all();
        foreach ($contract->currentDocuments()->orderByDesc('id')->limit(200)->get() as $row) {
            $candidates[] = ['ref' => ['type' => 'contract_document', 'id' => $row->id], 'model' => $row];
        }
        $options = [];
        foreach ($candidates as $candidate) {
            try {
                $options[] = $this->source($contract, $candidate['ref'], true, $candidate['model'], $validOn)['display'];
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return $options;
    }

    private function financialSnapshot(PayApplication $application): array
    {
        $computed = $application->status === 'draft' ? $this->billing->submissionAmounts($application) : [
            'D' => $application->previous_billed_amount, 'G' => $application->cumulative_amount,
            'held' => $application->retainage_held, 'line6' => $application->earned_less_retainage,
            'line7' => $application->previous_certificates, 'due' => $application->amount_due,
        ];
        $snapshot = [
            'id' => $application->id, 'applicationNo' => $application->application_no,
            'type' => $application->type, 'periodStart' => $application->period_start?->toDateString(),
            'periodEnd' => $application->period_end?->toDateString(),
        ];
        foreach (['this_period_amount', 'stored_materials_amount', 'retainage_percent', 'retainage_released'] as $field) {
            $snapshot[$field] = number_format((float) $application->{$field}, 2, '.', '');
        }
        foreach ($computed as $key => $amount) {
            $snapshot[$key] = number_format((float) $amount, 2, '.', '');
        }
        return $snapshot;
    }

    private function assertCurrent(ClaimSubmission $submission, PayApplication $application, ProjectContract $contract): void
    {
        $this->assertMaterialRules($application, ContractClaimProfile::read($contract));
        if (! ContractClaimProfile::confirmed($contract) || $submission->profile_snapshot !== ContractClaimProfile::read($contract)) {
            throw new InvalidArgumentException('제출 조건이 변경되었습니다. 확인된 조건으로 새 제출본을 준비하세요.');
        }
        if ($submission->application_snapshot !== $this->financialSnapshot($application)) {
            throw new InvalidArgumentException('준비 이후 청구 금액·기간이 변경되었습니다. 새 제출본을 준비하세요.');
        }
        foreach ($submission->manifest['documents'] ?? [] as $item) {
            $source = $this->source($contract, $item['source'], true, null, $submission->manifest['historicalSubmittedOn'] ?? null);
            $this->readVerified($source['disk'], $source['path'], $item['sha256']);
        }
        $this->verifyArchive($submission->disk, $submission->file_path, $submission->sha256);
    }

    private function assertMaterialRules(PayApplication $application, array $profile): void
    {
        if ($profile['stored_materials'] ?? false) {
            return;
        }
        $storedAllocation = PayApplicationAllocation::where('pay_application_id', $application->id)
            ->with('record:id,stage')->get()->contains(fn ($allocation) =>
                ((float) $allocation->amount > 0 || (float) $allocation->quantity > 0)
                && (data_get($allocation->snapshot, 'record.stage') === 'stored' || $allocation->record?->stage === 'stored'));
        if ((float) $application->stored_materials_amount > 0 || $storedAllocation) {
            throw new InvalidArgumentException('계약 제출 조건과 BOQ 반입·보관 기성이 충돌합니다. 보관 자재 청구 허용 근거를 먼저 확인하세요.');
        }
    }

    private function verifyArchive(string $diskName, string $path, string $expected): void
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw new InvalidArgumentException('보존한 제출본 파일을 찾을 수 없습니다.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('보존한 제출본을 읽을 수 없습니다.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actual = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($expected, $actual)) {
            throw new InvalidArgumentException('보존한 제출본의 내용이 변경되었습니다.');
        }
    }

    private function readVerified(string $diskName, string $path, ?string $expected, int $limit = self::MAX_FILE_BYTES): string
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw new InvalidArgumentException('원본 파일을 찾을 수 없습니다. 자료를 복구한 뒤 다시 준비하세요.');
        }
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('원본 파일을 읽을 수 없습니다.');
        }
        try {
            $bytes = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > $limit) {
            throw new InvalidArgumentException('파일은 비어 있지 않아야 하며 허용 크기 이하여야 합니다.');
        }
        if (filled($expected) && ! hash_equals(strtolower($expected), hash('sha256', $bytes))) {
            throw new InvalidArgumentException('등록 원본과 파일 내용이 다릅니다. 새 원본으로 다시 확인하세요.');
        }
        return $bytes;
    }

    private function writeVerified(string $diskName, string $path, string $bytes): void
    {
        if (! Storage::disk($diskName)->put($path, $bytes, ['visibility' => 'private'])) {
            throw new InvalidArgumentException('제출본을 저장하지 못했습니다.');
        }
        $this->readVerified($diskName, $path, hash('sha256', $bytes), self::MAX_PACKET_BYTES + 10485760);
    }

    private function claimLines(PayApplication $application): array
    {
        return PayApplicationAllocation::where('pay_application_id', $application->id)->orderBy('id')->get()->map(fn ($allocation) => [
            'line' => data_get($allocation->snapshot, 'line.lineNo'),
            'description' => data_get($allocation->snapshot, 'line.description'),
            'quantity' => (string) $allocation->quantity,
            'amount' => (string) $allocation->amount,
        ])->all();
    }

    private function zip(array $package, array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'claim-');
        if ($path === false) {
            throw new InvalidArgumentException('제출본 임시 파일을 만들 수 없습니다.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new InvalidArgumentException('제출본 ZIP을 만들 수 없습니다.');
            }
            if (! $zip->addFromString('manifest.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                || ! $zip->addFromString('claim-summary.html', $this->summary($package))) {
                throw new InvalidArgumentException('제출 요약과 파일 목록을 ZIP에 넣을 수 없습니다.');
            }
            foreach ($files as $file) {
                if (! $zip->addFile($file['path'], $file['name'])) {
                    throw new InvalidArgumentException('제출본에 원본을 넣을 수 없습니다.');
                }
            }
            if (! $zip->close()) {
                throw new InvalidArgumentException('제출본 ZIP을 마무리하지 못했습니다.');
            }
            return $path;
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    private function summary(array $package): string
    {
        $escape = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $app = $package['application'];
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Claim summary</title><style>body{font:15px sans-serif;margin:36px;line-height:1.5}table{border-collapse:collapse;width:100%;margin:20px 0}td,th{border:1px solid #bbb;padding:8px;text-align:left}</style><h1>Progress claim summary</h1>';
        if (! empty($package['historicalSubmittedOn'])) {
            $html .= '<p><strong>Reconstructed record of an existing submitted claim (기존 제출 자료 복원본).</strong> Original recorded submission date: '.$escape($package['historicalSubmittedOn']).'. Documents validated for that date. This archive was assembled on '.$escape($package['preparedAt']).'. Actual dispatch and response evidence are recorded separately.</p>';
        }
        $html .= '<p>'.$escape($package['contract']['title']).' — '.$escape($package['contract']['number']).'<br>Application '.$escape($app['applicationNo']).' / Revision '.$escape($package['revision']).'<br>Period: '.$escape($app['periodStart']).' – '.$escape($app['periodEnd']).'<br>Currency: '.$escape($package['contract']['currency']).'</p><table>';
        foreach (['this_period_amount' => 'Current work', 'stored_materials_amount' => 'Stored materials', 'G' => 'Completed and stored to date', 'held' => 'Retainage held', 'line6' => 'Earned less retainage', 'line7' => 'Previous certificates', 'due' => 'Current payment requested'] as $key => $label) {
            $html .= '<tr><th>'.$escape($label).'</th><td>'.$escape($app[$key] ?? '').'</td></tr>';
        }
        $html .= '</table><h2>Claim lines</h2><table><tr><th>Line</th><th>Description</th><th>Quantity</th><th>Amount</th></tr>';
        foreach ($package['lines'] as $line) {
            $html .= '<tr><td>'.$escape($line['line']).'</td><td>'.$escape($line['description']).'</td><td>'.$escape($line['quantity']).'</td><td>'.$escape($line['amount']).'</td></tr>';
        }
        $html .= '</table><h2>Supporting documents</h2><ul>';
        foreach ($package['documents'] as $document) {
            $html .= '<li>'.$escape($document['title']).': '.$escape($document['file']).'</li>';
        }
        return $html.'</ul><p>Prepared package. Submission, acceptance and payment are recorded separately. This summary supplements any prescribed contract form.</p></html>';
    }

    private function row(ClaimSubmission $submission): array
    {
        $history = array_map(function (array $event, int $index) use ($submission): array {
            if (is_array($event['responseDocument'] ?? null)) {
                unset($event['responseDocument']['disk'], $event['responseDocument']['path']);
                $event['responseDownloadUrl'] = '/billing-export/submissions/'.$submission->id.'/responses/'.$index.'/file';
            }
            return $event;
        }, $submission->history ?? [], array_keys($submission->history ?? []));
        return [
            'id' => $submission->id, 'revision' => $submission->revision, 'status' => $submission->status,
            'profileVersion' => $submission->profile_version, 'preparedAt' => $submission->prepared_at?->toIso8601String(),
            'sentAt' => $submission->sent_at?->toDateString(), 'receivedAt' => $submission->received_at?->toDateString(),
            'responseAt' => $submission->response_at?->toDateString(), 'reference' => $submission->reference,
            'history' => $history, 'fileName' => $submission->file_name,
            'downloadUrl' => '/billing-export/submissions/'.$submission->id.'/packet.zip',
        ];
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\\pL\\pN._ -]+/u', '-', basename(str_replace('\\', '/', $name))) ?: 'document';
        return mb_substr($name, 0, 140);
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('처리일은 YYYY-MM-DD 형식의 실제 날짜여야 합니다.');
        }
        return $value;
    }

    private function respond(callable $work): array
    {
        try {
            return $work();
        } catch (ValidationException $e) {
            return ['success' => false, 'errors' => array_map(fn ($messages) => $messages[0], $e->errors())];
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
