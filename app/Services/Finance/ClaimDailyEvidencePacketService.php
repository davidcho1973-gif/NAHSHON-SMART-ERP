<?php

namespace App\Services\Finance;

use App\Models\CadDrawingRevision;
use App\Models\IntelligentDocument;
use App\Models\MaterialReceipt;
use App\Models\MobileExpense;
use App\Models\OpsIntakeItem;
use App\Models\PayApplication;
use App\Models\PayApplicationAllocation;
use App\Models\ProjectContract;
use App\Models\WbsPhoto;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Collect retained claim proofs without turning purchases or deliveries into earned work. */
class ClaimDailyEvidencePacketService
{
    public function __construct(private readonly ClaimEvidenceInboxService $inbox) {}

    public function sources(PayApplication $application, ProjectContract $contract): array
    {
        if ($application->type === 'retainage_release') {
            return [];
        }

        $sources = [];
        foreach (PayApplicationAllocation::where('pay_application_id', $application->id)->with('record.line')->orderBy('id')->get() as $allocation) {
            $snapshot = $allocation->snapshot ?? [];
            $lineId = (int) ($allocation->record?->contract_boq_line_id ?? 0);
            if (! $lineId || (int) $allocation->record?->line?->project_contract_id !== (int) $contract->id) {
                throw new InvalidArgumentException('기성 배분의 계약 공정을 확인할 수 없습니다.');
            }
            $label = trim((string) data_get($snapshot, 'line.lineNo').' '.(string) data_get($snapshot, 'line.description'));
            foreach (['evidence', 'contractEvidence'] as $bucket) {
                foreach ($snapshot[$bucket] ?? [] as $proof) {
                    $type = (string) ($proof['type'] ?? '');
                    $id = (int) ($proof['id'] ?? 0);
                    $source = $this->resolve($contract, $type, $id);
                    $expected = $proof['verifiedTextSha256'] ?? $proof['verifiedFileSha256'] ?? $proof['originalSha256'] ?? $proof['sha256'] ?? null;
                    if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/iD', $expected)) {
                        throw new InvalidArgumentException('검토 당시 원본 해시가 없는 기성 근거입니다. 근거를 다시 확인한 뒤 초안을 만드세요.');
                    }
                    $source['expectedSha256'] = strtolower($expected);
                    // Only the server-owned, verified allocation can qualify an undated old document.
                    $source['allocationId'] = $allocation->id;
                    if ($type === 'document' && empty($source['date'])) {
                        $source['date'] = $allocation->record->work_date?->toDateString();
                        $source['dateBasis'] = 'reviewed_work_date';
                    }
                    $source['lineIds'] = [$lineId];
                    $source['lineLabels'] = [$label];
                    $source['locators'] = [['lineId' => $lineId, 'locator' => (string) ($proof['locator'] ?? '')]];
                    $source['allocationIds'] = [$allocation->id];
                    $source['basis'] = $bucket;
                    $source['classification'] = $bucket === 'contractEvidence' ? 'contract_basis' : 'work_proof';
                    $this->notFuture($source, $application);
                    $sources[] = $source;
                }
            }
        }

        foreach ($this->inbox->materializeForApplication($application) as $source) {
            if ((int) ($source['contractId'] ?? 0) !== (int) $contract->id) {
                throw new InvalidArgumentException('기성 증빙의 계약이 일치하지 않습니다.');
            }
            $this->notFuture($source, $application);
            $sources[] = $source + ['basis' => 'daily_inbox', 'allocationIds' => []];
        }

        return $sources;
    }

    public function read(array $source): string
    {
        if (($source['sourceType'] ?? '') === 'cad_revision') {
            $contract = ProjectContract::findOrFail((int) ($source['contractId'] ?? 0));
            // Descriptors are not file capabilities: resolve current permissions and the pinned revision again.
            $canonical = $this->resolve($contract, 'cad_revision', (int) ($source['sourceId'] ?? 0));
            try {
                $disk = Storage::disk($canonical['disk']);
                $stream = $disk->readStream($canonical['path']);
                if (! is_resource($stream)) {
                    throw new InvalidArgumentException('CAD 개정 원본을 읽을 수 없습니다.');
                }
                try {
                    $bytes = stream_get_contents($stream, 26214401);
                } finally {
                    fclose($stream);
                }
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('CAD 개정 원본을 저장소에서 읽을 수 없습니다.', previous: $e);
            }
            if (! is_string($bytes) || ! hash_equals(strtolower($canonical['sha256']), hash('sha256', $bytes))) {
                throw new InvalidArgumentException('등록된 CAD 개정 원본 해시와 파일이 다릅니다.');
            }
        } else {
            $bytes = ($source['sourceType'] ?? '') === 'intake'
                ? (string) ($source['rawText'] ?? '')
                : $this->inbox->readSource($source);
        }
        if ($bytes === '' || strlen($bytes) > 26214400) {
            throw new InvalidArgumentException('기성 증빙 원본은 비어 있지 않아야 하며 25MB 이하여야 합니다.');
        }
        foreach (['sha256', 'expectedSha256'] as $field) {
            if (filled($source[$field] ?? null) && ! hash_equals(strtolower((string) $source[$field]), hash('sha256', $bytes))) {
                throw new InvalidArgumentException('기성 검토 당시 원본과 현재 파일이 다릅니다. 원본을 복구하거나 새 근거로 다시 검토하세요.');
            }
        }

        return $bytes;
    }

    public function cadPreview(ProjectContract $contract, int $revisionId): array
    {
        $source = $this->resolve($contract, 'cad_revision', $revisionId);

        return ['bytes' => $this->read($source), 'mime' => $source['mime'], 'name' => $source['name']];
    }

    public function manifest(array $source, array $file): array
    {
        return [
            'source' => ['type' => $source['sourceType'], 'id' => (int) $source['sourceId']],
            'title' => $source['title'] ?? $source['name'],
            'date' => $source['date'] ?? null, 'dateBasis' => $source['dateBasis'] ?? null,
            'lineIds' => array_values($source['lineIds'] ?? []), 'lineLabels' => array_values($source['lineLabels'] ?? []),
            'locators' => array_values($source['locators'] ?? []), 'allocationIds' => array_values($source['allocationIds'] ?? []),
            'inboxId' => $source['inboxId'] ?? null, 'classification' => $source['classification'] ?? null,
            'basis' => $source['basis'] ?? 'daily_inbox',
            'file' => $file['name'], 'sha256' => $file['sha256'], 'bytes' => $file['bytes'],
        ];
    }

    public function assertCurrent(PayApplication $application, ProjectContract $contract, array $manifest): void
    {
        $current = [];
        foreach ($this->sources($application, $contract) as $source) {
            $bytes = $this->read($source);
            $current[] = $this->manifest($source, ['name' => '', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]);
        }
        $fingerprint = static function (array $items): array {
            $values = array_map(static function (array $item): string {
                unset($item['file']);
                return json_encode(Arr::sortRecursive($item), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }, $items);
            sort($values, SORT_STRING);
            return $values;
        };
        if ($fingerprint($current) !== $fingerprint($manifest)) {
            throw new InvalidArgumentException('준비 이후 공정별 증빙 또는 연결 정보가 변경되었습니다. 새 제출본을 준비하세요.');
        }
    }

    public function assertArchiveAccess(ProjectContract $contract, array $manifest): void
    {
        foreach ($manifest as $item) {
            $type = (string) data_get($item, 'source.type');
            $id = (int) data_get($item, 'source.id');
            $model = match ($type) {
                'document' => IntelligentDocument::class, 'expense' => MobileExpense::class,
                'receipt' => MaterialReceipt::class, 'photo' => WbsPhoto::class, 'intake' => OpsIntakeItem::class,
                'cad_revision' => CadDrawingRevision::class,
                default => throw new InvalidArgumentException('알 수 없는 보존 증빙 형식입니다.'),
            };
            if ($model::withoutGlobalScopes()->whereKey($id)->exists()) {
                // Deletion/expiry never rewrites a packet. Current privacy still applies to it.
                $this->resolve($contract, $type, $id, true);
            }
        }
    }

    private function resolve(ProjectContract $contract, string $type, int $id, bool $archived = false): array
    {
        if ($type === 'cad_revision') {
            // The ledger rechecks contract scope and CAD export access; revision ID never follows the mutable head.
            $ledger = app(ClaimEvidenceService::class);
            $proof = $ledger->editableReferences($contract->id, [['type' => $type, 'id' => $id, 'locator' => 'CAD 개정 원본']])[0];
            $revision = $ledger->cadRevision($contract, $id);

            return ['contractId' => $contract->id, 'sourceType' => $type, 'sourceId' => $id,
                'title' => $proof['title'], 'name' => 'cad-'.$proof['drawingId'].'-r'.$proof['drawingRevision'].'.dxf',
                'mime' => 'application/dxf', 'disk' => $proof['disk'], 'path' => $proof['filePath'],
                'sha256' => $proof['sha256'], 'url' => $proof['downloadUrl'],
                'date' => $revision->created_at?->toDateString(), 'dateBasis' => 'cad_revision_created_at'];
        }
        if ($type === 'intake') {
            $proof = app(ClaimEvidenceService::class)->submissionIntakeProof($contract, $id, $archived);
            return $proof + ['contractId' => $contract->id, 'sourceType' => 'intake', 'sourceId' => $id];
        }
        // Read and hash once when retaining it; metadata collection must not preload every file.
        return $this->inbox->resolveSource($contract, $type, $id, false, $archived);
    }

    private function notFuture(array $source, PayApplication $application): void
    {
        if (filled($source['date'] ?? null) && $application->period_end
            && (string) $source['date'] > $application->period_end->toDateString()) {
            throw new InvalidArgumentException('기성 기간 이후의 자료는 이 회차 증빙에 포함할 수 없습니다.');
        }
    }
}
