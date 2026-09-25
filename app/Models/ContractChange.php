<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RFI 한 건 — 계약을 바꾸는 유일한 길. 추가는 새 계약 줄, 감액은 기존 줄의 수량을 줄인다. */
class ContractChange extends Model
{
    public const KINDS = ['add', 'deduct'];

    public const STATUSES = ['submitted', 'approved', 'rejected'];

    protected $guarded = ['id'];

    // PostgreSQL timestamptz must receive the instant's offset, not local wall time.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'submitted_on' => 'date', 'decided_on' => 'date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'project_contract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractChangeLine::class)->orderBy('id');
    }
}
