<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_code',
        'company_id',
        'site_id',
        'team_id',
        'plate_number',
        'vehicle_type',
        'model',
        'vendor',
        'rent_start',
        'rent_end',
        'insurance_expiry',
        'current_mileage',
        'next_oil_change_mileage',
        'status',
        'photo_front',
        'photo_rear',
        'photo_left',
        'photo_right',
        'contract_path',
        'registration_method',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'rent_start' => 'date',
            'rent_end' => 'date',
            'insurance_expiry' => 'date',
            'current_mileage' => 'integer',
            'next_oil_change_mileage' => 'integer',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle): void {
            if (blank($vehicle->vehicle_code)) {
                $vehicle->vehicle_code = self::makeVehicleCode();
            }
        });
    }

    private static function makeVehicleCode(): string
    {
        $max = self::query()
            ->where('vehicle_code', 'like', 'VH-%')
            ->get()
            ->map(function ($v) {
                preg_match('/VH-(\d+)/', $v->vehicle_code, $matches);
                return isset($matches[1]) ? (int)$matches[1] : 0;
            })
            ->max();

        $next = ($max ?? 0) + 1;
        return 'VH-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
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

    public function rentals(): HasMany
    {
        return $this->hasMany(VehicleRental::class)->orderByDesc('rented_at');
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(VehicleRental::class)->where('status', 'active')->whereNull('returned_at');
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
