<?php

namespace App\Mcp\Read;

use App\Models\Site;
use App\Models\User;
use App\Support\AccessPolicy;
use App\Support\AiInformationAccess;

/** Request-local authority. Never trust a client-supplied user, role or company scope. */
final class ErpReadContext
{
    public readonly array $siteIds;

    public function __construct(
        public readonly User $actor,
        public readonly int $companyId,
        public readonly ?int $siteId = null,
    ) {
        abort_unless($actor->exists && $actor->account_status === 'active', 403);
        abort_unless($actor->canAccessCompany($companyId), 403, 'Company access denied.');
        abort_unless(AccessPolicy::canSeeCompany($actor, $companyId), 403, 'Company access denied.');
        $this->siteIds = Site::query()->where('company_id', $companyId)->get()
            ->filter(fn (Site $site): bool => AiInformationAccess::canUseSite($actor, $site))
            ->modelKeys();
        abort_if($siteId !== null && ! in_array($siteId, $this->siteIds, true), 403, 'Site access denied.');
    }

    public function selectedSiteIds(): array
    {
        return $this->siteId === null ? $this->siteIds : [$this->siteId];
    }
}
