<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 그날 그 현장의 <b>단 하나의 보고서</b>. `(site_id, report_date)` 가 고유키다.
 *
 * 한 줄에 저자가 셋이고, 셋의 역할이 다르다:
 *
 *   현장이 쓴 것   — weather · trades · work_today · work_tomorrow · progress_rate · safety_*
 *                    (현장앱에서 입력. 사람이 본 것이라 이게 1차 사실이다)
 *   시스템이 센 것 — metrics (인원·진도·자재·지출 집계. 숫자는 전부 DB 에서 뽑는다)
 *   AI 가 요약한 것 — narrative (위 둘을 근거로 쓴 문장. 숫자를 새로 만들지 않는다)
 *
 * 예전에는 현장이 쓴 것이 `field_daily_reports` 라는 별도 표에 있었다. 고유키가 똑같아서
 * "그날 그 현장의 보고서" 에 답이 둘이었고, 현장이 쓴 "오늘 한 일" 을 AI 가 못 봐서
 * 같은 것을 두 번 쓰는 일이 벌어졌다. 2026-08-18 에 한 표로 합쳤다.
 */
class DailyClosingReport extends Model
{
    /** 마감 상태(`status`) — 현장 제출 상태(`field_status`)와 뜻이 다르다. */
    public const OPEN = 'open';          // 아직 마감을 안 눌렀다(현장만 썼거나 비어 있다)

    public const WRITING = 'writing';    // 마감 작성 중(AI)

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'site_id', 'report_date', 'status', 'error',
        'metrics', 'narrative', 'closed_by_id', 'closed_at',

        // 현장이 직접 쓴 것
        'weather', 'temperature', 'trades', 'work_title', 'work_today', 'work_tomorrow',
        'progress_rate', 'tbm_completed', 'safety_checks', 'safety_notes',
        'field_status', 'field_submitted_at',

        // 아침 작업계획서 — 같은 날 같은 현장이니 같은 줄에 있다.
        'plan', 'plan_status', 'plan_submitted_at', 'plan_by_id',
    ];

    /** 아침 계획서 상태 — 마감 상태(`status`)와도, 현장 제출 상태(`field_status`)와도 다르다. */
    public const PLAN_DRAFT = 'draft';

    public const PLAN_SUBMITTED = 'submitted';

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'metrics' => 'array',
            'narrative' => 'array',
            'closed_at' => 'datetime',
            'trades' => 'array',
            'safety_checks' => 'array',
            'tbm_completed' => 'boolean',
            'field_submitted_at' => 'datetime',
            'plan' => 'array',
            'plan_submitted_at' => 'datetime',
        ];
    }

    /** 아침 계획서를 쓰긴 했는가 — 빈 껍데기를 "계획서 있음" 으로 세지 않기 위해. */
    public function hasPlan(): bool
    {
        $plan = $this->plan ?: [];

        return filled($plan['workScope'] ?? null)
            || ! empty($plan['crews'] ?? [])
            || ! empty($plan['equipment'] ?? [])
            || ! empty($plan['hazards'] ?? []);
    }

    public function dispatches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReportDispatch::class)->latest('id');
    }

    public function planBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plan_by_id');
    }

    /**
     * 현장이 직접 쓴 부분만 추려 돌려준다.
     *
     * 마감 집계와 AI 서술은 이 사람이 쓴 것이 아니다. 섞어 놓으면 나중에
     * "이 문장 누가 썼나" 를 못 가린다 — 그래서 읽는 쪽도 여기로만 읽는다.
     *
     * @return array<string, mixed>
     */
    public function fieldReport(): array
    {
        return [
            'weather' => $this->weather,
            'temperature' => $this->temperature,
            'trades' => $this->trades ?: [],
            'workTitle' => $this->work_title,
            'workToday' => $this->work_today,
            'workTomorrow' => $this->work_tomorrow,
            'progressRate' => (int) $this->progress_rate,
            'tbmCompleted' => (bool) $this->tbm_completed,
            'safetyChecks' => $this->safety_checks ?: [],
            'safetyNotes' => $this->safety_notes,
            'status' => $this->field_status,
            'submittedAt' => $this->field_submitted_at?->toIso8601String(),
        ];
    }

    /** 현장이 무언가 쓰긴 했는가 — 빈 칸만 있는 줄을 보고서로 취급하지 않기 위해. */
    public function hasFieldReport(): bool
    {
        return filled($this->work_today) || filled($this->work_tomorrow)
            || filled($this->work_title) || filled($this->safety_notes)
            || (int) $this->progress_rate > 0 || ! empty($this->trades);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}
