<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    public const STATUSES = [
        'submitted' => '요청됨', 'needs_info' => '정보 필요', 'reviewing' => '검토 중',
        'on_hold' => '보류', 'out_of_stock' => '품절', 'partially_ordered' => '일부 구매', 'ordered' => '구매완료',
        'supplier_confirmed' => '업체 납품 확정',
        'partial' => '일부 입고', 'received' => '입고완료', 'cancelled' => '취소',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approval_required' => 'boolean', 'approved_budget' => 'decimal:2', 'approved_at' => 'datetime', 'need_by' => 'date', 'eta' => 'date', 'version' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class)->orderBy('seq')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchaseRequestEvent::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseRequestAttachment::class)->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseRequestOrder::class)->orderBy('id');
    }
}
