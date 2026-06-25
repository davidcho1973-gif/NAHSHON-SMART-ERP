<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Equipment extends Model
{
    use HasFactory;

    protected $table = 'equipments';

    protected $fillable = [
        'equipment_code',
        'company_id',
        'site_id',
        'team_id',
        'employee_id',
        'equipment_type',
        'model',
        'vendor',
        'rent_start',
        'rent_end',
        'daily_rate',
        'delivery_fee',
        'status',
        'photo_front',
        'photo_rear',
        'photo_left',
        'photo_right',
        'contract_path',
        'registration_method',
        'payload',
        'quantity',
        'is_bulk',
    ];

    protected function casts(): array
    {
        return [
            'rent_start' => 'date',
            'rent_end' => 'date',
            'daily_rate' => 'integer',
            'delivery_fee' => 'integer',
            'payload' => 'array',
            'quantity' => 'integer',
            'is_bulk' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Equipment $equipment): void {
            if (blank($equipment->equipment_code)) {
                $equipment->equipment_code = self::makeEquipmentCode();
            }
        });
    }

    private static function makeEquipmentCode(): string
    {
        $max = self::query()
            ->where('equipment_code', 'like', 'EQ-%')
            ->get()
            ->map(function ($v) {
                preg_match('/EQ-(\d+)/', $v->equipment_code, $matches);
                return isset($matches[1]) ? (int)$matches[1] : 0;
            })
            ->max();

        $next = ($max ?? 0) + 1;
        return 'EQ-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(EquipmentRental::class)->orderByDesc('rented_at');
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(EquipmentRental::class)->where('status', 'active')->whereNull('returned_at');
    }

    /**
     * Scope visible query following access-scope rules in AGENTS.md §6-3.
     */
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
            'site' => $user->allowed_site_id
                ? $query->where('site_id', $user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            'team' => $user->allowed_team_id
                ? $query->where('team_id', $user->allowed_team_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
