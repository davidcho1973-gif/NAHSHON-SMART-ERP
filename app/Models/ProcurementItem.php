<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 조달 항목의 납기 추적 상태(발주·ETA·벤더·금액). WBS 조달 공정 1건당 1행(project_code + wbs_code).
 */
class ProcurementItem extends Model
{
    /** 조달 상태 파이프라인(순서 = 진행 단계). */
    public const STATUSES = ['발주대기', '발주완료', '생산중', '선적중', '통관중', '입고완료'];

    /** 각 단계의 진행률(%) — 파이프라인 시각화·집계용. */
    public const STATUS_PROGRESS = [
        '발주대기' => 0,
        '발주완료' => 20,
        '생산중' => 45,
        '선적중' => 70,
        '통관중' => 88,
        '입고완료' => 100,
    ];

    protected $fillable = [
        'project_code', 'site_id', 'wbs_code', 'wbs_item_id', 'item_id',
        'status', 'vendor', 'vendor_id', 'contract_id',
        'po_no', 'amount', 'currency', 'ordered_on', 'eta', 'note', 'created_by_id',
        'document_disk', 'document_path', 'document_name',
    ];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',
            'eta' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** 품목 마스터 연결 — 표준단가 대비 실발주 비교의 근거. */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * 거래처 마스터 연결. vendor 문자열은 이 행의 이름 사본일 뿐, 정본은 여기다.
     * 예전에는 문자열뿐이라 "Graybar" / "Graybar Inc." 가 다른 회사로 집계됐다.
     */
    public function vendorRef(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** 이 발주가 걸린 협력사 발주 계약 — 계약 대비 발주 누계의 근거. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'contract_id');
    }

    public function wbsItem(): BelongsTo
    {
        return $this->belongsTo(WbsItem::class);
    }

    public static function progressFor(?string $status): int
    {
        return self::STATUS_PROGRESS[$status] ?? 0;
    }

    /** 다음 단계 상태(마지막이면 그대로). */
    public static function nextStatus(?string $status): string
    {
        $i = array_search($status, self::STATUSES, true);
        if ($i === false) {
            return self::STATUSES[1]; // 발주대기 아님/미지정 → 발주완료
        }

        return self::STATUSES[min($i + 1, count(self::STATUSES) - 1)];
    }
}
