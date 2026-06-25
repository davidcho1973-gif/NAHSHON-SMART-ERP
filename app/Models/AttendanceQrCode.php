<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttendanceQrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'site_contractor_id',
        'team_id',
        'name',
        'token',
        'token_hash',
        'mode',
        'status',
        'require_gps',
        'valid_from',
        'valid_until',
        'created_by_id',
        'payload',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'require_gps' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'payload' => 'array',
        ];
    }

    public static function makeToken(): string
    {
        return 'att_' . Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function activeForToken(string $token): ?self
    {
        return self::query()
            ->with(['site', 'team', 'siteContractor.company'])
            ->where('token_hash', self::hashToken($token))
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->first();
    }

    public static function forTeam(Team $team, ?int $createdById = null): self
    {
        $team->loadMissing(['site', 'company']);
        $siteContractor = SiteContractor::query()
            ->where('site_id', $team->site_id)
            ->when($team->company_id, fn ($query) => $query->where('company_id', $team->company_id))
            ->when(! $team->company_id && filled($team->contract_company_name), fn ($query) => $query->where('company_name', $team->contract_company_name))
            ->where('status', 'active')
            ->first();

        $qr = self::query()
            ->where('team_id', $team->id)
            ->where('status', 'active')
            ->first();

        if ($qr) {
            return $qr;
        }

        $token = self::makeToken();

        return self::query()->create([
            'site_id' => $team->site_id,
            'site_contractor_id' => $siteContractor?->id,
            'team_id' => $team->id,
            'name' => trim(($team->site?->code ? $team->site->code . ' / ' : '') . ($team->contract_company_name ?: $team->company?->name ?: 'Contract') . ' / ' . $team->name),
            'token' => $token,
            'token_hash' => self::hashToken($token),
            'mode' => 'auto',
            'status' => 'active',
            'created_by_id' => $createdById,
        ]);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function siteContractor(): BelongsTo
    {
        return $this->belongsTo(SiteContractor::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
