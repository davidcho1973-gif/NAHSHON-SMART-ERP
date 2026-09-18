<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 점검 한 건 — 실제로 찍힌 기록.
 *
 * <b>answers 에 질문 문장까지 통째로 베껴 담는다.</b> 질문지는 나중에 고쳐진다.
 * 참조만 걸어 두면 질문을 고치는 순간 과거 기록의 뜻이 같이 바뀌어, 「그날 무엇을
 * 확인했는가」 에 아무도 답하지 못하게 된다. 사고 조사에서 필요한 것이 바로 그 답이다.
 */
class EquipmentChecklistLog extends Model
{
    use HasFactory;

    public const PASS = 'pass';

    /** 이상은 있지만 쓰는 것은 막지 않는다 — 반장이 본다. */
    public const FAIL = 'fail';

    /** 치명 항목이 걸렸다 — 그 장비는 못 쓴다. */
    public const BLOCKED = 'blocked';

    protected $fillable = [
        'equipment_id',
        'equipment_checklist_template_id',
        'equipment_rental_id',
        'company_id', 'site_id', 'team_id', 'employee_id', 'user_id',
        'stage',
        'result',
        'failed_count',
        'critical_failed_count',
        'answers',
        'photos',
        'notes',
        'latitude', 'longitude', 'accuracy_m',
        'submitted_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'photos' => 'array',
            'payload' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EquipmentChecklistTemplate::class, 'equipment_checklist_template_id');
    }

    /** @return array<int, array<string, mixed>> 이상으로 답한 항목만. */
    public function failedAnswers(): array
    {
        return array_values(array_filter(
            (array) $this->answers,
            fn (array $a): bool => ($a['ok'] ?? true) === false,
        ));
    }
}
