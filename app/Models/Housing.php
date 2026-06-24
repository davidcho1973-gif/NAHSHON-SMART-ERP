<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Housing extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'code',
        'name',
        'address',
        'beds',
        'occupied',
        'monthly_rent',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'beds' => 'integer',
            'occupied' => 'integer',
            'monthly_rent' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Restrict the query to the housings a user is allowed to see, following the
     * access-scope rules in AGENTS.md §6-3. Housing has only company_id/site_id
     * scope columns, so a team-scoped user is resolved to their site, and a
     * self-scoped user has no personal housing linkage (sees nothing).
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Full access: privileged roles or an explicit all-sites scope.
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
}
