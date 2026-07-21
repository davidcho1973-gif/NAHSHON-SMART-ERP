<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnifiedAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_code', 'fingerprint', 'company_id', 'site_id', 'project_id', 'user_id', 'employee_id',
        'source_module', 'source_type', 'source_id', 'event_type', 'severity', 'status', 'title',
        'content', 'assignee', 'action_url', 'occurred_at', 'due_at', 'resolved_at',
        'last_detected_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $audience) use ($user): void {
            $audience->whereNull('user_id')->orWhere('user_id', $user->id);
        });

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site' => $user->allowed_site_id
                ? $query->where(function (Builder $site) use ($user): void {
                    $companyId = Site::query()->whereKey($user->allowed_site_id)->value('company_id');
                    $site->where('site_id', $user->allowed_site_id)
                        ->when($companyId, fn (Builder $global): Builder => $global->orWhere(function (Builder $company) use ($companyId): void {
                            $company->whereNull('site_id')->where('company_id', $companyId);
                        }));
                })
                : $query->whereRaw('1 = 0'),
            'self' => $query->where(function (Builder $self) use ($user): void {
                $self->where('user_id', $user->id);
                if ($user->employee_id) {
                    $self->orWhere('employee_id', $user->employee_id);
                }
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
