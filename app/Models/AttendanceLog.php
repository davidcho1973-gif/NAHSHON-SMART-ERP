<?php

namespace App\Models;

use App\Services\Communication\CommunicationService;
use App\Services\Payroll\AttendanceTimesheetSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class AttendanceLog extends Model
{
    use HasFactory;
    // 급여의 근거라 진짜로 지우지 않는다. 지우면 "그날 그 사람이 왔었다" 는 사실
    // 자체가 사라지고, 누가 언제 지웠는지도 남지 않는다. 표시만 하고 화면·급여
    // 계산에서는 즉시 빠진다.
    use SoftDeletes;

    protected static function booted(): void
    {
        // attendance_date는 event_at의 날짜와 항상 일치해야 한다(관리자 패널 직접 등록/수정 대비).
        static::saving(function (self $log): void {
            if ($log->event_at && empty($log->attendance_date)) {
                $log->attendance_date = Carbon::parse($log->event_at)->toDateString();
            }
        });

        // 하루에 출근 한 줄, 퇴근 한 줄.
        //
        // 예전에는 같은 사람의 같은 날에 퇴근이 두 줄, 출근·퇴근이 같은 분에 찍히기도
        // 했다. GPS 자동 기록(geo_auto)에 중복 검사가 아예 없었기 때문이다 — 게이트와
        // QR 에는 있었는데 그쪽만 없어서, 한 사람이 이미 퇴근한 뒤에도 퇴근이 또 생겼다.
        //
        // 검사를 각 화면에 하나씩 붙이지 않고 여기 둔다. 기록을 만드는 곳이 여덟
        // 군데(웹·GPS·게이트·QR·수기·자동마감·현장앱·오프라인)라, 한 군데만 빠뜨려도
        // 그 경로로만 중복이 들어온다 — 그리고 그건 급여를 뽑을 때에야 드러난다.
        static::creating(fn (self $log) => self::keepOnePerDay($log));

        // 출퇴근 → 급여 타임시트 유기적 연동: 어떤 경로의 출퇴근 기록이든
        // 저장/삭제되면 해당 일자의 payroll_timesheets를 자동 재계산한다.
        static::saved(fn (self $log) => self::syncTimesheet($log));
        static::deleted(fn (self $log) => self::syncTimesheet($log));
        // 되살리면 그날 근무시간도 같이 돌아와야 한다. 이걸 빠뜨리면 기록은
        // 보이는데 급여는 0 인 상태가 되고, 화면상으로는 멀쩡해 보인다.
        static::restored(fn (self $log) => self::syncTimesheet($log));
        static::created(fn (self $log) => self::publishCommunicationAlert($log));
    }

    /**
     * 그날 이미 같은 종류가 있으면 새 줄을 만들지 않는다.
     *
     *   출근  <b>먼저 찍은 것</b>이 그날의 출근이다. 나중 것은 버린다 — 점심 먹고
     *         돌아와 다시 찍어도 그날 처음 온 시각이 출근이어야 한다.
     *   퇴근  <b>나중에 찍은 것</b>이 그날의 퇴근이다. 새 줄을 만드는 대신 있던 줄의
     *         시각을 늦춘다 — 오후에 나갔다 다시 들어와 저녁에 진짜로 퇴근하면
     *         그 시각까지가 그날 일한 시간이다.
     *
     * 버려도 흔적은 남긴다. 아무 말 없이 사라지면 급여 담당이 "분명히 찍었는데
     * 왜 없냐" 를 확인할 방법이 없다 — 목록의 "수정 N회" 에 함께 뜬다.
     *
     * 반려된 기록은 세지 않는다. 잘못 찍힌 것을 반려해 뒀는데 그것 때문에 제대로 된
     * 기록을 못 넣으면, 고칠 방법이 사라진다.
     *
     * @return bool|null false 면 새 줄을 만들지 않는다
     */
    private static function keepOnePerDay(self $log): ?bool
    {
        $date = $log->attendance_date
            ?: ($log->event_at ? Carbon::parse($log->event_at)->toDateString() : null);

        if (! $log->employee_id || ! $date || ! in_array($log->event_type, ['clock_in', 'clock_out'], true)) {
            return null;
        }

        $existing = self::query()
            ->where('employee_id', $log->employee_id)
            ->whereDate('attendance_date', Carbon::parse($date)->toDateString())
            ->where('event_type', $log->event_type)
            ->where('status', '!=', 'rejected')
            ->orderByDesc('event_at')
            ->first();

        if (! $existing) {
            return null;
        }

        $incoming = $log->event_at ? Carbon::parse($log->event_at) : null;

        if ($log->event_type === 'clock_out' && $incoming && $existing->event_at
            && $incoming->greaterThan($existing->event_at)) {
            $existing->forceFill([
                'event_at' => $incoming,
                'source' => $log->source ?: $existing->source,
                'payload' => self::withMergeNote(
                    $existing,
                    '퇴근 '.$existing->event_at->format('H:i').' → '.$incoming->format('H:i'),
                    $log->source,
                ),
            ])->save();

            return false;
        }

        $existing->forceFill([
            'payload' => self::withMergeNote(
                $existing,
                ($log->event_type === 'clock_in' ? '출근' : '퇴근').' '
                    .($incoming?->format('H:i') ?? '').' 중복 — 버림',
                $log->source,
            ),
        ])->saveQuietly();

        return false;
    }

    /**
     * 합쳐진 사실을 수정 이력에 한 줄 남긴다.
     *
     * @return array<string, mixed>
     */
    private static function withMergeNote(self $existing, string $what, ?string $source): array
    {
        $payload = $existing->payload ?? [];
        $edits = $payload['admin_edits'] ?? [];
        $edits[] = [
            'action' => 'merge',
            'by' => '자동 ('.($source ?: '알 수 없음').')',
            'byId' => null,
            'at' => Carbon::now()->toDateTimeString(),
            'changes' => ['하루 한 번' => ['from' => '', 'to' => $what]],
        ];
        $payload['admin_edits'] = array_slice($edits, -20);

        return $payload;
    }

    private static function syncTimesheet(self $log): void
    {
        if (! $log->employee_id || ! $log->attendance_date) {
            return;
        }

        try {
            app(AttendanceTimesheetSync::class)
                ->syncDay((int) $log->employee_id, Carbon::parse($log->attendance_date)->toDateString());
        } catch (\Throwable $e) {
            // 급여 동기화 실패가 출퇴근 기록 자체를 막지 않도록 격리한다.
            report($e);
        }
    }

    private static function publishCommunicationAlert(self $log): void
    {
        try {
            app(CommunicationService::class)->publishAttendanceAlert($log);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected $fillable = [
        'employee_id',
        'company_id',
        'site_id',
        'team_id',
        'photo_upload_id',
        'daily_work_assignment_id',
        'attendance_qr_code_id',
        'employee_badge_qr_token_id',
        'site_contractor_id',
        'employer_company_id',
        'recorded_by_id',
        'attendance_date',
        'event_type',
        'event_at',
        'source',
        'status',
        'approved_by_id',
        'approved_at',
        'notes',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'event_at' => 'datetime',
            'approved_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dailyWorkAssignment(): BelongsTo
    {
        return $this->belongsTo(DailyWorkAssignment::class);
    }

    public function attendanceQrCode(): BelongsTo
    {
        return $this->belongsTo(AttendanceQrCode::class);
    }

    public function employeeBadgeQrToken(): BelongsTo
    {
        return $this->belongsTo(EmployeeBadgeQrToken::class);
    }

    public function siteContractor(): BelongsTo
    {
        return $this->belongsTo(SiteContractor::class);
    }

    public function employerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'employer_company_id');
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

    /** 승인·반려를 누른 사람. 급여 근거라 누가 손댔는지 남아야 한다. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function photoUpload(): BelongsTo
    {
        return $this->belongsTo(PhotoUpload::class);
    }

    public function approvalHistories(): MorphMany
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }
}
