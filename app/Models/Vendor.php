<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vendor extends Model
{
    use HasFactory;

    /**
     * 이름 → 벤더 매칭. 정확히 한 곳일 때만 잇는다 — AP·1099 집계의 근거가 되므로
     * 애매한 매칭은 안 하느니만 못하다.
     */
    public static function matchByName(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $ids = self::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    protected $fillable = [
        'company_id',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'trade',
        'status',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** 이 거래처로 나간 발주들. */
    public function procurementItems()
    {
        return $this->hasMany(ProcurementItem::class, 'vendor_id');
    }

    /** 이 거래처와 맺은 발주(payable) 계약들. */
    public function contracts()
    {
        return $this->hasMany(ProjectContract::class, 'counterparty_vendor_id');
    }
}
