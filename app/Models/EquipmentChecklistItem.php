<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 점검 항목 한 줄.
 *
 * 항목은 모두 «그래야 하는 상태» 를 적은 평서문이다. 작업자는 「맞다」 아니면
 * 「아니다」 만 누른다 — 부정문을 섞으면 화면 쪽에 «이 항목은 답을 뒤집어 읽어라»
 * 는 표식이 필요해지고, 그 표식은 언젠가 틀린다(DefaultEquipmentChecklists 주석).
 */
class EquipmentChecklistItem extends Model
{
    use HasFactory;

    public const CRITICAL = 'critical';

    public const NORMAL = 'normal';

    protected $fillable = [
        'equipment_checklist_template_id',
        'sort_order',
        'label_ko', 'label_en', 'label_es',
        'help_ko', 'help_en', 'help_es',
        'severity',
        'requires_photo_on_fail',
        'stage',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'requires_photo_on_fail' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EquipmentChecklistTemplate::class, 'equipment_checklist_template_id');
    }

    /** 작업자 언어로 읽은 문장. 번역이 비면 한국어로 떨어뜨린다 — 빈 줄보다 낫다. */
    public function label(string $lang = 'ko'): string
    {
        return (string) ($this->{'label_'.$lang} ?: $this->label_ko);
    }

    public function help(string $lang = 'ko'): ?string
    {
        $value = $this->{'help_'.$lang} ?: $this->help_ko;

        return $value !== '' ? $value : null;
    }

    public function isCritical(): bool
    {
        return $this->severity === self::CRITICAL;
    }
}
