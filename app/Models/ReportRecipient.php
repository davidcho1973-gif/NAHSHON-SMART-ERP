<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 일일 보고를 받는 사람 — 현장별로 저장해 두는 주소록.
 *
 * 원청 공사팀, 감리, 협력사 소장, 본사 공사부장은 <b>같은 보고서를 받지 않는다.</b>
 * 아침 계획서만 받는 사람이 있고 마감만 받는 사람이 있다. 그래서 주소만이 아니라
 * "무엇을 받는가"(`receives`)를 함께 둔다.
 *
 * `site_id` 가 비면 모든 현장의 보고를 받는다는 뜻이다(본사 임원·공사부장).
 */
class ReportRecipient extends Model
{
    /** 아침 작업계획서 */
    public const PLAN = 'plan';

    /** 저녁 일일 마감보고서 */
    public const CLOSING = 'closing';

    protected $fillable = [
        'site_id', 'name', 'email', 'org', 'role',
        'receives', 'is_cc', 'active', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'receives' => 'array',
            'is_cc' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** 이 사람이 그 보고서를 받는가. `receives` 가 비어 있으면 둘 다 받는 것으로 본다. */
    public function wants(string $kind): bool
    {
        $list = $this->receives ?: [];

        return $list === [] || in_array($kind, $list, true);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'siteId' => $this->site_id,
            'siteName' => $this->site?->name,
            'name' => $this->name,
            'email' => $this->email,
            'org' => $this->org,
            'role' => $this->role,
            'roleLabel' => self::ROLE_LABELS[$this->role] ?? $this->role,
            'receives' => $this->receives ?: [self::PLAN, self::CLOSING],
            'isCc' => (bool) $this->is_cc,
            'active' => (bool) $this->active,
        ];
    }

    public const ROLE_LABELS = [
        'owner' => '발주처',
        'gc' => '원청',
        'partner' => '협력사',
        'internal' => '본사',
    ];
}
