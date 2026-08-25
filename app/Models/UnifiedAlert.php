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

        // 남 앞으로 발행된 알림은 거른다. 소유자 판정은 두 칸(user_id·employee_id)을
        // 모두 본다 — 한 칸만 보면 employee_id 로만 발행된 개인 알림(HR 만료 등)이
        // 본인에게 "남의 알림"으로 취급된다(연계 점검: orWhere 필요).
        $query->where(function (Builder $audience) use ($user): void {
            $audience->whereNull('user_id')->orWhere('user_id', $user->id);
            if ($user->employee_id) {
                $audience->orWhere('employee_id', $user->employee_id);
            }
        });

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        // 내 앞으로 온 알림은 현장·회사 스코프와 무관하게 보인다 — 파견을 가 있어도
        // 내 비자 만료 알림은 내게 와야 한다.
        $own = function (Builder $q) use ($user): void {
            $q->orWhere('user_id', $user->id);
            if ($user->employee_id) {
                $q->orWhere('employee_id', $user->employee_id);
            }
        };

        return match ($user->access_scope) {
            'company' => $user->allowed_company_id
                ? $query->where(function (Builder $co) use ($user, $own): void {
                    $co->where('company_id', $user->allowed_company_id);
                    $own($co);
                })
                : $query->whereRaw('1 = 0'),
            'site' => $user->allowed_site_id
                ? $query->where(function (Builder $site) use ($user, $own): void {
                    $companyId = Site::query()->whereKey($user->allowed_site_id)->value('company_id');
                    $site->where('site_id', $user->allowed_site_id)
                        ->when($companyId, fn (Builder $global): Builder => $global->orWhere(function (Builder $company) use ($companyId): void {
                            $company->whereNull('site_id')->where('company_id', $companyId);
                        }));
                    $own($site);
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
