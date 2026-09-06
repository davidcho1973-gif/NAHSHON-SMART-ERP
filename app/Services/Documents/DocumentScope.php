<?php

namespace App\Services\Documents;

use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One scope rule for intake, AI classification and human corrections. */
class DocumentScope
{
    /** @return array{company_id: ?int, site_id: ?int, project_id: ?int} */
    public function normalize(array $scope, ?User $user = null): array
    {
        $companyId = ! empty($scope['company_id']) ? (int) $scope['company_id'] : null;
        $siteId = ! empty($scope['site_id']) ? (int) $scope['site_id'] : null;
        $projectId = ! empty($scope['project_id']) ? (int) $scope['project_id'] : null;
        $project = $projectId ? Project::query()->findOrFail($projectId) : null;
        $site = $siteId ? Site::query()->findOrFail($siteId) : null;

        // Validate the actual requested site before deriving the project's site.
        if ($project && $siteId && (int) $project->site_id !== $siteId) {
            throw ValidationException::withMessages(['project_id' => 'PROJECT와 선택한 현장이 일치하지 않습니다.']);
        }
        if ($project && $companyId && $project->company_id && (int) $project->company_id !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'PROJECT와 선택한 회사가 일치하지 않습니다.']);
        }
        $site ??= $project?->site;
        if ($site && $companyId && (int) $site->company_id !== $companyId) {
            throw ValidationException::withMessages(['company_id' => '회사와 현장이 일치하지 않습니다.']);
        }
        if ($project && $site && $project->company_id && (int) $project->company_id !== (int) $site->company_id) {
            throw ValidationException::withMessages(['project_id' => 'PROJECT의 회사·현장 설정을 먼저 확인해 주세요.']);
        }
        $siteId = $site ? (int) $site->id : $siteId;
        $companyId = $project?->company_id ?: $site?->company_id ?: $companyId;

        if ($user && ! $this->canChooseAnyScope($user)) {
            if ($user->access_scope === 'site' && $user->allowed_site_id) {
                if ($project && (int) $project->site_id !== (int) $user->allowed_site_id) {
                    throw ValidationException::withMessages(['project_id' => '허용된 현장의 PROJECT만 선택할 수 있습니다.']);
                }
                if ($siteId && $siteId !== (int) $user->allowed_site_id) {
                    throw ValidationException::withMessages(['site_id' => '허용된 현장만 선택할 수 있습니다.']);
                }
                $allowedSite = Site::query()->findOrFail($user->allowed_site_id);
                if ($companyId && (int) $companyId !== (int) $allowedSite->company_id) {
                    throw ValidationException::withMessages(['company_id' => '허용된 회사만 선택할 수 있습니다.']);
                }
                $siteId = (int) $allowedSite->id;
                $companyId = $allowedSite->company_id;
            } elseif ($user->access_scope === 'company' && $user->allowed_company_id) {
                if ($companyId && (int) $companyId !== (int) $user->allowed_company_id) {
                    throw ValidationException::withMessages(['company_id' => '허용된 회사만 선택할 수 있습니다.']);
                }
                $companyId = $user->allowed_company_id;
            } else {
                throw ValidationException::withMessages(['files' => '문서를 등록할 수 있는 회사·현장 범위가 없습니다.']);
            }
        }

        return ['company_id' => $companyId ? (int) $companyId : null, 'site_id' => $siteId, 'project_id' => $projectId];
    }

    /** @return array{scope: array, review_reason: ?string} */
    public function resolveForAnalysis(IntelligentDocument $document, string $projectCode): array
    {
        $original = $this->scopeOf($document);
        $projectCode = trim($projectCode);
        $user = $document->uploadedBy;
        if (! $document->project_id && $projectCode === '') {
            return ['scope' => $original, 'review_reason' => null];
        }
        if (! $user || (! $this->canChooseAnyScope($user) && ! in_array($user->access_scope, ['company', 'site'], true))) {
            return ['scope' => $original, 'review_reason' => '등록자의 허용 범위를 확인한 뒤 PROJECT를 선택해 주세요.'];
        }

        try {
            if ($document->project_id) {
                return ['scope' => $this->normalize($original, $user), 'review_reason' => null];
            }
            $query = Project::query()
                ->when($document->company_id, fn (Builder $q) => $q->where('company_id', $document->company_id))
                ->when($document->site_id, fn (Builder $q) => $q->where('site_id', $document->site_id));
            if (! $this->canChooseAnyScope($user)) {
                if ($user->access_scope === 'company') {
                    $query->where('company_id', $user->allowed_company_id ?: 0);
                } else {
                    $query->where('site_id', $user->allowed_site_id ?: 0);
                }
            }
            // AI text is a value, never an ILIKE wildcard. Exact code takes precedence.
            $matches = (clone $query)->whereRaw('lower(project_code) = ?', [mb_strtolower($projectCode)])->limit(2)->get();
            if ($matches->isEmpty()) {
                $matches = $query->whereRaw('position(lower(?) in lower(name)) > 0', [$projectCode])->limit(2)->get();
            }
            if ($matches->count() !== 1) {
                return ['scope' => $original, 'review_reason' => 'AI가 제안한 PROJECT를 허용 범위에서 하나로 확인할 수 없습니다.'];
            }
            $scope = $this->normalize([...$original, 'project_id' => (int) $matches->first()->id], $user);

            return ['scope' => $scope, 'review_reason' => null];
        } catch (ValidationException $e) {
            return ['scope' => $original, 'review_reason' => collect($e->errors())->flatten()->first()];
        }
    }

    public function findDuplicate(string $sha256, array $scope, ?int $exceptId = null): ?IntelligentDocument
    {
        $query = IntelligentDocument::query()->where('sha256', $sha256)
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId));
        foreach (['company_id', 'site_id', 'project_id'] as $column) {
            $value = $scope[$column] ?? null;
            // Legacy AI records set only project_id. Treat their missing company/site as
            // the project's scope, without matching another company's explicit value.
            if ($column !== 'project_id' && ! empty($scope['project_id']) && $value) {
                $query->where(fn (Builder $q) => $q->where($column, $value)->orWhereNull($column));
            } else {
                $value ? $query->where($column, $value) : $query->whereNull($column);
            }
        }

        return $query->orderByRaw("case when ai_status = 'ready' then 0 else 1 end")->orderBy('id')->first();
    }

    /** The unique index remains the arbiter when another request wins after our lookup. */
    public function saveResolved(IntelligentDocument $document, array $scope, array $attributes = []): IntelligentDocument
    {
        $original = $this->scopeOf($document);
        $contractId = $document->getRawOriginal('project_contract_id');
        $duplicate = $this->findDuplicate($document->sha256, $scope, $document->id);
        if ($duplicate) {
            return $this->retainDuplicate($document, $duplicate, $attributes, $scope);
        }
        $hasFreshPayload = array_key_exists('ai_payload', $attributes);
        if (! empty($attributes['reviewed_by'])) {
            $payload = (array) ($attributes['ai_payload'] ?? $document->ai_payload);
            unset($payload['scope_review_reason']);
            $attributes['ai_payload'] = $payload;
        }
        if (! empty($document->ai_payload['duplicate_document_id'])) {
            if ($scope !== $original) {
                $payload = (array) ($attributes['ai_payload'] ?? $document->ai_payload);
                unset($payload['duplicate_document_id'], $payload['duplicate_target_scope'], $payload['duplicate_reason']);
                $attributes['ai_payload'] = $payload;
            } elseif (! $hasFreshPayload) {
                // Editing a title alone does not resolve the pending duplicate decision.
                $attributes['ai_status'] = 'review_required';
            }
        }
        try {
            // A savepoint is required: a PostgreSQL uniqueness error poisons the enclosing
            // transaction until it is rolled back, so catching around save() is insufficient.
            DB::transaction(function () use ($document, $scope, $attributes): void {
                $document->fill([...$attributes, ...$scope])->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            $duplicate = $this->findDuplicate($document->sha256, $scope, $document->id);
            if (! $duplicate) {
                throw $e;
            }
            // Discard the failed scope before writing the review result.
            $document->fill([...$original, 'project_contract_id' => $contractId]);

            return $this->retainDuplicate($document, $duplicate, $attributes, $scope);
        }

        return $document;
    }

    public function retainDuplicate(IntelligentDocument $document, IntelligentDocument $existing, ?array $analysis = null, ?array $targetScope = null): IntelligentDocument
    {
        if ($document->is($existing) || ! hash_equals((string) $document->sha256, (string) $existing->sha256)) {
            throw new \InvalidArgumentException('중복 보존은 내용 해시가 일치하는 서로 다른 문서에만 적용할 수 있습니다.');
        }
        $original = $this->scopeOf($document);
        $attributes = $analysis ?? [];
        $payload = (array) ($attributes['ai_payload'] ?? $document->ai_payload ?? []);
        $payload['duplicate_document_id'] = (int) $existing->id;
        $payload['duplicate_target_scope'] = $targetScope ?? $this->scopeOf($existing);
        $payload['duplicate_reason'] = '동일한 파일의 기존 문서가 있어 원본과 분석 결과를 보존하고 소속 변경을 보류했습니다.';
        $document->fill([
            ...$attributes,
            ...$original,
            'project_contract_id' => $document->getRawOriginal('project_contract_id'),
            'ai_status' => 'review_required',
            'ai_error' => null,
            'ai_payload' => $payload,
        ])->save();

        return $document;
    }

    /** @return array{company_id: ?int, site_id: ?int, project_id: ?int} */
    public function scopeOf(IntelligentDocument $document): array
    {
        return collect(['company_id', 'site_id', 'project_id'])->mapWithKeys(
            fn (string $key) => [$key => $document->getRawOriginal($key) ? (int) $document->getRawOriginal($key) : null],
        )->all();
    }

    private function canChooseAnyScope(User $user): bool
    {
        return in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites';
    }
}
