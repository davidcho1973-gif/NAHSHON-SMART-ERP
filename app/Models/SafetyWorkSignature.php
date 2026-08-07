<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyWorkSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'safety_work_item_id', 'employee_id', 'name', 'role', 'signed', 'signed_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'signed' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkItem::class, 'safety_work_item_id');
    }

    /**
     * 실제 배정된 직원. 이 링크가 있어야 서명이 인원관리/급여로 이어진다.
     *
     * name 과 중복처럼 보이지만 역할이 다르다: name 은 서명 당시의 법적 기록(불변),
     * employee 는 현재의 인사 레코드(직원이 지워지면 null 이 되지만 name 은 남는다).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
