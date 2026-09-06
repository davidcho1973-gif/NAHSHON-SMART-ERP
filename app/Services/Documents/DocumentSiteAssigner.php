<?php

namespace App\Services\Documents;

use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteFromText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 현장이 비어 있는 문서를 한 번에 정리한다.
 *
 * 문서함이 현장을 따르게 된 뒤로, 현장 없이 올라온 옛 문서는 어느 현장 화면에도
 * 뜨지 않는다(전체에서만 보인다). 한 건씩 열어 고치는 것은 수백 건에서 현실적이지
 * 않으므로 여기서 목록·제안·일괄 적용을 한 벌로 묶는다.
 *
 * 규칙 셋:
 *  1. 이미 현장이 있는 문서는 절대 덮지 않는다 — 일괄 작업이 멀쩡한 귀속을 망가뜨리면
 *     그 뒤로 아무도 이 버튼을 못 누른다.
 *  2. 제안은 확실할 때만 — PROJECT 가 이미 있으면 그 PROJECT 의 현장이 정답이고,
 *     없으면 제목·파일명·문서번호에서 현장 코드를 찾는다(영수증과 같은 해석기).
 *  3. 사람이 고른 현장이 제안보다 언제나 우선한다.
 */
class DocumentSiteAssigner
{
    /**
     * 현장이 비어 있는 문서 + 제안.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, suggested: int}
     */
    public function pending(User $actor, int $limit = 200): array
    {
        $total = $this->scope($actor)->count();

        $documents = $this->scope($actor)
            ->with(['project:id,site_id,project_code,name'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $sites = SiteFromText::sites();
        $rows = $documents->map(function (IntelligentDocument $document) use ($sites): array {
            $suggestion = $this->suggest($document, $sites);

            return [
                'id' => $document->id,
                'title' => $document->displayTitle(),
                'fileName' => $document->original_file_name,
                'documentNumber' => $document->document_number,
                'receivedAt' => $document->received_at?->toDateString(),
                'project' => $document->project?->project_code,
                'suggestedSiteId' => $suggestion['site']?->id,
                'suggestedSite' => $suggestion['site']?->code,
                'suggestedFrom' => $suggestion['from'],
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'suggested' => count(array_filter($rows, fn (array $r): bool => $r['suggestedSiteId'] !== null)),
        ];
    }

    /**
     * 이 문서의 현장은 어디로 보이는가.
     *
     * @param  Collection<int, Site>|null  $sites
     * @return array{site: ?Site, from: ?string}
     */
    public function suggest(IntelligentDocument $document, ?Collection $sites = null): array
    {
        $sites ??= SiteFromText::sites();

        // PROJECT 가 붙어 있으면 추측할 것이 없다 — PROJECT 는 현장에 속한다.
        if ($document->project_id) {
            $siteId = $document->relationLoaded('project')
                ? $document->project?->site_id
                : Project::query()->whereKey($document->project_id)->value('site_id');
            $site = $siteId ? $sites->firstWhere('id', (int) $siteId) : null;
            if ($site) {
                return ['site' => $site, 'from' => 'project'];
            }
        }

        // 사람이 적어 둔 글자에서 찾는다 — 제목·파일명·문서번호 순.
        foreach ([$document->title, $document->original_file_name, $document->document_number] as $text) {
            $site = SiteFromText::match($text, $sites);
            if ($site) {
                return ['site' => $site, 'from' => 'text'];
            }
        }

        return ['site' => null, 'from' => null];
    }

    /**
     * 고른 문서를 한 현장으로 — $siteId 가 null 이면 각 문서의 제안대로.
     *
     * @param  list<int>  $ids
     * @return array{assigned: int, skipped: int, unmatched: int}
     */
    public function assign(User $actor, array $ids, ?int $siteId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return ['assigned' => 0, 'skipped' => 0, 'unmatched' => 0];
        }

        $sites = SiteFromText::sites();
        $fixed = $siteId ? $sites->firstWhere('id', $siteId) : null;
        if ($siteId && ! $fixed) {
            return ['assigned' => 0, 'skipped' => count($ids), 'unmatched' => 0];
        }

        // 고른 것 중 "현장이 비어 있고 내가 볼 수 있는" 문서만 손댄다.
        $documents = $this->scope($actor)->with(['project:id,site_id'])->whereIn('id', $ids)->get();
        $skipped = count($ids) - $documents->count();
        $assigned = 0;
        $unmatched = 0;

        foreach ($documents as $document) {
            if ($document->ai_status === 'analyzing') {
                $skipped++;

                continue;
            }
            $site = $fixed ?: $this->suggest($document, $sites)['site'];
            if (! $site) {
                $unmatched++;

                continue;
            }
            // 내 권한 밖의 현장으로는 밀어 넣지 않는다 — 현장 담당이 남의 현장으로
            // 문서를 보내 버리면 본인도 다시 못 본다.
            if (! $this->allowsSite($actor, $site)) {
                $skipped++;

                continue;
            }

            $resolver = app(DocumentScope::class);
            try {
                $scope = $resolver->normalize([
                    'company_id' => $document->company_id,
                    'site_id' => $site->id,
                    'project_id' => $document->project_id,
                ], $actor);
            } catch (ValidationException) {
                $skipped++;

                continue;
            }
            $saved = $resolver->saveResolved($document, $scope);
            if ($saved->ai_payload['duplicate_document_id'] ?? null) {
                $skipped++;

                continue;
            }
            $assigned++;
        }

        return ['assigned' => $assigned, 'skipped' => $skipped, 'unmatched' => $unmatched];
    }

    /** 이 사람이 문서를 보낼 수 있는 현장인가. */
    private function allowsSite(User $actor, Site $site): bool
    {
        if (in_array($actor->access_role, ['super_admin', 'admin'], true) || $actor->access_scope === 'all_sites') {
            return true;
        }

        return match ($actor->access_scope) {
            'company' => (int) $site->company_id === (int) $actor->allowed_company_id,
            'site' => (int) $site->id === (int) $actor->allowed_site_id,
            default => false,
        };
    }

    /** 현장이 비어 있는 문서만 — 볼 수 있는 범위 안에서. */
    private function scope(User $actor): Builder
    {
        return IntelligentDocument::query()->visibleTo($actor)->whereNull('site_id');
    }
}
