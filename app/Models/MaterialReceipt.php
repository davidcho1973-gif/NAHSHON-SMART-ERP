<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 트럭 한 대가 실어 온 것 — 납품서 한 장.
 *
 * 공정표와 무관하다. 현장에 물건이 왔다는 사실은 공정표에 그 줄이 있든 없든 일어난다.
 */
class MaterialReceipt extends Model
{
    /** 사진에서 읽기만 한 상태. 사람이 확인해야 장부가 된다. */
    public const STATUS_DRAFT = 'draft';

    /** 사람이 눈으로 보고 맞다고 한 상태. */
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'company_id', 'site_id', 'received_on', 'vendor', 'vendor_id',
        'po_no', 'delivery_no', 'note', 'status',
        'photo_disk', 'photo_path', 'photo_name', 'analysis',
        'created_by_id', 'confirmed_by_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'confirmed_at' => 'datetime',
            'analysis' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialReceiptLine::class)->orderBy('seq')->orderBy('id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vendorRecord(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    /** 접근제어 — 다른 안전·장비 표와 같은 규칙(AGENTS.md §6-3). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true)
            || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site', 'team' => $user->allowed_site_id
                ? $query->where('site_id', $user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
