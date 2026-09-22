<?php

namespace App\Services\Admin;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Support\AccessPolicy;
use App\Support\SiteClock;
use App\Support\WorkRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 출퇴근 기록 수정 — Filament AttendanceLogResource 를 SPA 로 옮긴 것.
 *
 * 이 표는 급여의 근거 자료다. 시각 하나를 고치면 그 사람의 그날 임금이 바뀌므로,
 * 다른 화면보다 두 가지를 더 지킨다.
 *
 *   1. 고친 흔적을 남긴다 — 누가·언제·무엇을 무엇으로 바꿨는지 payload 에 쌓는다.
 *      Filament 시절에는 흔적이 남지 않아 "왜 이 시각이 이렇게 됐지" 를 되짚을 수 없었다.
 *   2. 현장 범위를 지킨다 — 현장소장은 자기 현장 기록만 보고 고친다.
 *
 * 삭제를 일부러 좁게 열어 뒀다. 잘못 찍힌 기록은 지우는 것보다 "반려" 로 두는 편이
 * 낫다. 지워버리면 그날 그 사람이 왔었다는 사실 자체가 사라진다.
 */
class AttendanceLogAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    /** 지우는 것은 관리자만 — 급여 근거를 없애는 일이다. */
    public const DELETE_ROLES = ['super_admin', 'admin'];

    public const EVENT_TYPES = [
        'clock_in' => '출근',
        'clock_out' => '퇴근',
    ];

    public const STATUSES = [
        'approved' => '승인완료',
        'pending' => '대기중',
        'rejected' => '반려',
    ];

    /**
     * 어떻게 찍힌 기록인가 — <b>실제로 저장되는 값을 전부</b> 담는다.
     *
     * 빠진 값이 있으면 화면에 'gate_qr' 같은 날것이 그대로 뜬다. 급여 근거를 보는
     * 사람에게 그건 «내가 모르는 경로로 들어온 기록» 으로 읽히고, 그 한 줄을 믿을지
     * 말지 판단할 수 없게 된다.
     */
    public const SOURCES = [
        'gate_qr' => '게이트 QR',
        'geo_auto' => '자동(위치)',
        'auto_clockout' => '자동 마감',
        'web_portal' => '웹 포탈',
        'field_app' => '작업자 앱',
        'offline_gps_sync' => '오프라인 동기화',
        'team_qr' => 'QR 스캔',
        'nfc_reader' => 'NFC 리더',
        'gps' => 'GPS',
        'gate' => '게이트',
        'manual' => '수기 입력',
    ];

    /**
     * 사람이 <b>고를 수 있는</b> 방식. 자동 경로(게이트·위치·자동마감)는 여기 없다 —
     * 손으로 넣은 기록에 «게이트에서 찍혔다» 를 붙일 수 있으면, 기록의 출처가
     * 더 이상 증거가 아니게 된다.
     */
    public const MANUAL_SOURCES = ['manual', 'web_portal', 'team_qr', 'nfc_reader'];

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canDelete(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::DELETE_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * 목록 — <b>한 사람의 하루가 한 줄</b>이다. 출근과 퇴근이 나란히 선다.
     *
     * 예전에는 찍힌 것 하나가 한 줄이었다. 그러면 같은 사람의 출근과 퇴근이 목록
     * 여기저기에 흩어져서, 「이 사람 오늘 몇 시간 일했나」 를 보려면 눈으로 짝을
     * 맞춰야 한다. 그 질문이 이 표를 여는 이유인데도 그렇다.
     *
     * 짝이 안 맞는 날(퇴근이 없는 날)이 오히려 중요하다. 한 줄로 모으면 빈 칸이
     * 그대로 보인다 — 흩어져 있을 때는 «없는 줄» 이라 아예 눈에 띄지 않았다.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '출퇴근 기록 조회 권한이 없습니다.'];
        }

        $from = trim((string) ($filters['from'] ?? ''));
        $until = trim((string) ($filters['until'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $siteId = $this->intOrNull($filters['siteId'] ?? null);

        $query = AttendanceLog::query()
            ->with(['employee:id,name,employee_number', 'site:id,code', 'company:id,name', 'approvedBy:id,name'])
            ->orderByDesc('event_at')
            ->limit(500);

        $this->applyScope($query);

        if ($from !== '') {
            $query->whereDate('attendance_date', '>=', $from);
        }
        if ($until !== '') {
            $query->whereDate('attendance_date', '<=', $until);
        }
        // 삭제된 기록은 기본으로 안 보인다. 다만 볼 방법이 아예 없으면 "되살리기" 도
        // 없는 셈이고, 잘못 지운 것을 되돌릴 길이 사라진다.
        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif (array_key_exists($status, self::STATUSES)) {
            $query->where('status', $status);
        }
        if ($siteId) {
            $query->where('site_id', $siteId);
        }

        return [
            'success' => true,
            'rows' => $this->days($query->get()),
            'canManage' => $this->canManage(),
            'canDelete' => $this->canDelete(),
        ];
    }

    /**
     * 찍힌 기록들을 «사람 · 하루» 로 묶는다.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private function days($logs): array
    {
        $days = [];

        // 이른 것부터 훑는다. 출근은 <b>그날 처음</b> 찍은 것이, 퇴근은 <b>마지막</b>에
        // 찍은 것이 그날의 두 끝이다. 최근순으로 훑으면 이 둘이 뒤집힌다.
        foreach ($logs->sortBy(fn (AttendanceLog $l) => (string) $l->event_at) as $log) {
            $date = $log->attendance_date?->toDateString() ?? '';
            $key = $log->employee_id.'|'.$date;

            $days[$key] ??= [
                'key' => $key,
                'employeeId' => $log->employee_id,
                'employee' => $log->employee?->name,
                'employeeNumber' => $log->employee?->employee_number,
                'date' => $date,
                'siteId' => $log->site_id,
                'site' => $log->site?->code,
                // 어느 시계로 보고 있는지 화면에 적는다. 이 한 글자가 없어서
                // «3시간 차이» 를 발견하는 데 하루가 걸렸다.
                'zone' => SiteClock::label($log->site_id, $log->event_at),
                'company' => $log->company?->name,
                'clockIn' => null,
                'clockOut' => null,
                'extras' => [],
                // 근무 시간은 <b>급여가 보는 대로</b> 적는다. 화면이 따로 계산하면
                // 같은 하루가 화면 9시간 30분, 급여 8시간 30분이 된다.
                'workedLabel' => null,
                'breakLabel' => null,
                'overtimeLabel' => null,
                'rulesLabel' => WorkRules::forSite($log->site_id)->summary(),
                'canDelete' => $this->canDelete(),
            ];

            $event = $this->event($log);
            $isIn = $log->event_type === 'clock_in';
            $slot = $isIn ? 'clockIn' : 'clockOut';
            $held = $days[$key][$slot];

            // 출근은 먼저 찍은 것이, 퇴근은 나중에 찍은 것이 그날의 끝이다.
            // 밀려난 기록은 버리지 않는다 — 이 표는 급여의 근거라서, 화면에서
            // 사라진 줄은 없는 줄이 된다(반려·중복·되살린 기록이 여기로 온다).
            if ($held === null) {
                $days[$key][$slot] = $event;
            } elseif ($isIn) {
                $days[$key]['extras'][] = $event;
            } else {
                $days[$key][$slot] = $event;
                $days[$key]['extras'][] = $held;
            }
        }

        foreach ($days as $key => $day) {
            $days[$key] = array_merge($day, $this->worked($day));
        }

        // 화면은 최근 날짜부터 본다 — 고칠 일이 생기는 건 대개 어제오늘이다.
        $rows = array_values($days);
        usort($rows, fn (array $a, array $b): int => [$b['date'], (string) $a['employee']] <=> [$a['date'], (string) $b['employee']]);

        return $rows;
    }

    /**
     * 찍힌 기록 하나 — 화면이 그 줄에 대해 할 수 있는 일을 모두 담는다.
     *
     * @return array<string, mixed>
     */
    private function event(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            // 시각은 <b>현장 시계</b>로 쓴다. 서버 시계로 쓰면 사바나 아침 7시 50분이
            // 04:50 으로 뜬다 — 기록은 옳은데 화면만 거짓말을 한다.
            'time' => SiteClock::show($log->site_id, $log->event_at),
            'eventAt' => SiteClock::show($log->site_id, $log->event_at, 'Y-m-d H:i:s'),
            'eventType' => $log->event_type,
            'eventTypeLabel' => self::EVENT_TYPES[$log->event_type] ?? (string) $log->event_type,
            'status' => $log->status,
            'statusLabel' => self::STATUSES[$log->status] ?? (string) $log->status,
            'source' => $log->source,
            'sourceLabel' => self::SOURCES[$log->source] ?? (string) $log->source,
            'siteId' => $log->site_id,
            'notes' => $log->notes,
            'approvedBy' => $log->approvedBy?->name,
            // 고친 적이 있으면 목록에서 바로 보이게 한다 — 급여 담당이 되짚을 단서다.
            'editCount' => count($log->payload['admin_edits'] ?? []),
            // 지워진 기록인지. 화면이 그 줄을 다르게 그리고 '되살리기' 를 준다.
            'deleted' => $log->trashed(),
            'deletedAt' => $log->deleted_at?->toDateTimeString(),
        ];
    }

    /**
     * 그날 일한 시간 — <b>급여가 보는 대로</b>. 두 끝이 다 있고 둘 다 유효할 때만 센다.
     *
     * 한쪽만 있을 때 0 이나 추정치를 적지 않는다 — 비어 있는 것과 «0시간 일했다» 는
     * 전혀 다른 말이고, 그 차이가 임금이다.
     *
     * 무급 휴게(점심)를 뺀 값을 「근무」 로 적고, 뺀 사실을 옆에 적는다. 빼기만 하고
     * 말을 안 하면 «내 시간이 한 시간 없어졌다» 가 되고, 그건 매번 묻게 된다.
     *
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function worked(array $day): array
    {
        $usable = fn (?array $e): bool => $e !== null && ! $e['deleted'] && $e['status'] !== 'rejected';
        $blank = ['workedLabel' => null, 'breakLabel' => null, 'overtimeLabel' => null];

        if (! $usable($day['clockIn']) || ! $usable($day['clockOut'])) {
            return $blank;
        }

        $minutes = (int) round(Carbon::parse($day['clockIn']['eventAt'])
            ->diffInMinutes(Carbon::parse($day['clockOut']['eventAt']), false));
        if ($minutes <= 0) {
            return $blank;   // 퇴근이 출근보다 이르면 셈이 아니라 고칠 거리다.
        }

        $split = WorkRules::forSite($day['siteId'])->split($minutes);

        return [
            'workedLabel' => WorkRules::hours($split['payable']),
            'breakLabel' => $split['break'] > 0
                ? '점심 '.WorkRules::hours($split['break']).' 제외' : null,
            'overtimeLabel' => $split['overtime'] > 0
                ? '초과 '.WorkRules::hours($split['overtime']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '출퇴근 기록 조회 권한이 없습니다.'];
        }

        $pairs = fn (array $map): array => array_map(
            fn ($k, $v): array => ['value' => (string) $k, 'label' => $v],
            array_keys($map),
            array_values($map),
        );

        return [
            'success' => true,
            'eventTypes' => $pairs(self::EVENT_TYPES),
            'statuses' => $pairs(self::STATUSES),
            // 조회용에만 '삭제됨' 을 더한다. 수정 폼의 상태 목록에 넣으면 사람이
            // 상태를 골라서 삭제하게 되는데, 삭제는 상태가 아니라 별개의 일이다.
            'filterStatuses' => $pairs(self::STATUSES + ['deleted' => '삭제됨']),
            // 폼에는 사람이 고를 수 있는 것만 — 자동 경로는 목록에 두지 않는다.
            'sources' => $pairs(array_intersect_key(self::SOURCES, array_flip(self::MANUAL_SOURCES))),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' — '.$s->name])->all(),
            'employees' => Employee::query()->orderBy('name')->get(['id', 'name', 'employee_number'])
                ->map(fn (Employee $e): array => [
                    'value' => (string) $e->id,
                    'label' => $e->name.($e->employee_number ? ' ('.$e->employee_number.')' : ''),
                ])->all(),
        ];
    }

    /**
     * 만들거나 고친다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '출퇴근 기록 수정 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $row = $id > 0 ? AttendanceLog::find($id) : null;
        if ($id > 0 && ! $row) {
            return ['success' => false, 'error' => '기록을 찾을 수 없습니다.'];
        }
        if ($row && ! $this->inScope($row)) {
            return ['success' => false, 'error' => '다른 현장의 기록은 수정할 수 없습니다.'];
        }

        $errors = [];
        $employeeId = $this->intOrNull($input['employeeId'] ?? null);
        $eventType = (string) ($input['eventType'] ?? '');
        $status = (string) ($input['status'] ?? 'approved');
        $source = (string) ($input['source'] ?? 'manual');
        $eventAtRaw = trim((string) ($input['eventAt'] ?? ''));

        $employee = $employeeId ? Employee::find($employeeId) : null;
        if (! $employee) {
            $errors['employeeId'] = '직원을 선택하세요.';
        }
        if (! array_key_exists($eventType, self::EVENT_TYPES)) {
            $errors['eventType'] = '구분을 선택하세요.';
        }
        if (! array_key_exists($status, self::STATUSES)) {
            $errors['status'] = '상태를 선택하세요.';
        }
        // 손으로 넣거나 고친 기록이 «게이트에서 찍혔다» 를 달 수는 없다. 출처가
        // 증거인데, 사람이 아무 출처나 붙일 수 있으면 그 증거가 증거가 아니게 된다.
        // 이미 있는 기록은 자기 출처를 그대로 지킨다(고치려고 연 것뿐인데 바뀌면 안 된다).
        $keepable = array_merge(self::MANUAL_SOURCES, $row?->source ? [$row->source] : []);
        if (! in_array($source, $keepable, true)) {
            $source = $row?->source ?: 'manual';
        }

        // 어느 현장의 시계로 적힌 시각인가 — 읽기 전에 정해야 한다.
        // 화면이 현장 시계로 보여 주므로 입력도 현장 시계로 읽는다. 짝이 어긋나면
        // 기록을 열어 아무것도 안 고치고 저장만 해도 시각이 3시간 움직인다.
        $siteId = $this->intOrNull($input['siteId'] ?? null) ?: $employee?->site_id;
        $tz = SiteClock::zone($siteId);

        $eventAt = null;
        if ($eventAtRaw === '') {
            $errors['eventAt'] = '기록 시각을 입력하세요.';
        } else {
            try {
                $eventAt = SiteClock::read($eventAtRaw, $siteId);
            } catch (\Throwable) {
                $errors['eventAt'] = '시각 형식이 올바르지 않습니다.';
            }
        }

        // 미래 시각은 사실일 수 없다. 오타(2026 → 2027)를 여기서 잡는다.
        if ($eventAt && $eventAt->isAfter(Carbon::now()->addDay())) {
            $errors['eventAt'] = '미래 시각은 기록할 수 없습니다.';
        }

        // 하루에 출근 한 줄, 퇴근 한 줄. 자동 경로(GPS·게이트)에서는 중복이 조용히
        // 버려지지만, 사람이 손으로 넣을 때는 말해 줘야 한다 — 눌렀는데 아무 일도
        // 안 일어나면 저장이 안 된 줄 알고 또 누른다.
        if ($employee && $eventAt && array_key_exists($eventType, self::EVENT_TYPES)) {
            $clash = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $eventAt->copy()->toDateString())
                ->where('event_type', $eventType)
                ->where('status', '!=', 'rejected')
                ->when($row, fn ($q) => $q->whereKeyNot($row->id))
                ->first();

            if ($clash) {
                $errors['eventType'] = '그날 '.self::EVENT_TYPES[$eventType].' 기록이 이미 있습니다('
                    .SiteClock::show($clash->site_id, $clash->event_at).'). 새로 넣지 말고 그 기록을 수정하세요.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // 현장의 시간대로 날짜를 계산한다. 서버 시계로 계산하면 자정 근처 기록이
        // 하루 밀리고, 그날 인원과 급여가 통째로 어긋난다.
        $attendanceDate = $eventAt->copy()->setTimezone($tz)->toDateString();

        $data = [
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'site_id' => $siteId,
            'team_id' => $employee->team_id,
            'attendance_date' => $attendanceDate,
            'event_type' => $eventType,
            'event_at' => $eventAt,
            'source' => $source,
            'status' => $status,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        if (! $row) {
            $data['recorded_by_id'] = auth()->id();
            $data['payload'] = ['admin_edits' => [$this->stamp('created', [])]];
            $created = AttendanceLog::create($data);

            return ['success' => true, 'id' => $created->id];
        }

        // 무엇이 바뀌었는지만 남긴다. 안 바뀐 값까지 쌓으면 이력이 금세 읽기 어려워진다.
        $changes = [];
        foreach (['event_at', 'event_type', 'status', 'attendance_date', 'employee_id', 'site_id', 'notes'] as $f) {
            $before = $f === 'event_at' ? $row->event_at?->toDateTimeString()
                : ($f === 'attendance_date' ? $row->attendance_date?->toDateString() : $row->{$f});
            $after = $f === 'event_at' ? $eventAt->toDateTimeString() : ($data[$f] ?? null);
            if ((string) $before !== (string) $after) {
                $changes[$f] = ['from' => $before, 'to' => $after];
            }
        }

        if ($changes !== []) {
            $payload = $row->payload ?? [];
            $edits = $payload['admin_edits'] ?? [];
            $edits[] = $this->stamp('edited', $changes);
            $payload['admin_edits'] = array_slice($edits, -20);   // 최근 20건이면 되짚기 충분하다
            $data['payload'] = $payload;
        }

        $row->update($data);

        return ['success' => true, 'id' => $row->id, 'changed' => array_keys($changes)];
    }

    /**
     * 승인 / 반려.
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, string $status): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '출퇴근 기록 수정 권한이 없습니다.'];
        }
        if (! array_key_exists($status, self::STATUSES)) {
            return ['success' => false, 'error' => '올바른 상태가 아닙니다.'];
        }

        $row = AttendanceLog::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '기록을 찾을 수 없습니다.'];
        }
        if (! $this->inScope($row)) {
            return ['success' => false, 'error' => '다른 현장의 기록은 수정할 수 없습니다.'];
        }

        $payload = $row->payload ?? [];
        $edits = $payload['admin_edits'] ?? [];
        $edits[] = $this->stamp('status', ['status' => ['from' => $row->status, 'to' => $status]]);
        $payload['admin_edits'] = array_slice($edits, -20);

        $row->update([
            'status' => $status,
            'approved_by_id' => auth()->id(),
            'approved_at' => Carbon::now(),
            'payload' => $payload,
        ]);

        return ['success' => true, 'status' => $row->status];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        if (! $this->canDelete()) {
            return ['success' => false, 'error' => '출퇴근 기록 삭제 권한이 없습니다. 잘못된 기록은 "반려" 로 두세요.'];
        }

        $row = AttendanceLog::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '기록을 찾을 수 없습니다.'];
        }
        if (! $this->inScope($row)) {
            return ['success' => false, 'error' => '다른 현장의 기록은 삭제할 수 없습니다.'];
        }

        // 지운 흔적을 먼저 남기고 지운다. 순서가 반대면, 지우는 데 성공하고 흔적을
        // 남기는 데 실패했을 때 아무 기록도 안 남는다.
        $this->record($row, 'delete', ['deleted' => ['from' => '있음', 'to' => '삭제됨']]);

        // 진짜로 지우지 않는다 — 급여 다툼이 생겼을 때 근거가 통째로 사라진다.
        // 화면과 급여 계산에서는 즉시 빠지고, 표에는 남는다.
        $row->delete();

        return ['success' => true];
    }

    /**
     * 삭제한 기록 되살리기.
     *
     * 되살릴 방법이 없으면 삭제 버튼은 되돌릴 수 없는 버튼이 된다. 급여 근거를
     * 다루는 화면에서 그런 버튼은 아무도 편히 못 누른다.
     *
     * @return array<string, mixed>
     */
    public function restore(int $id): array
    {
        if (! $this->canDelete()) {
            return ['success' => false, 'error' => '출퇴근 기록 복구 권한이 없습니다.'];
        }

        $row = AttendanceLog::withTrashed()->find($id);
        if (! $row) {
            return ['success' => false, 'error' => '기록을 찾을 수 없습니다.'];
        }
        if (! $this->inScope($row)) {
            return ['success' => false, 'error' => '다른 현장의 기록은 복구할 수 없습니다.'];
        }
        if (! $row->trashed()) {
            return ['success' => false, 'error' => '이미 살아 있는 기록입니다.'];
        }

        $row->restore();
        $this->record($row, 'restore', ['deleted' => ['from' => '삭제됨', 'to' => '있음']]);

        return ['success' => true];
    }

    /**
     * 손댄 흔적 한 줄을 payload 에 쌓는다.
     *
     * @param  array<string, mixed>  $changes
     */
    private function record(AttendanceLog $row, string $action, array $changes): void
    {
        $payload = $row->payload ?? [];
        $edits = $payload['admin_edits'] ?? [];
        $edits[] = $this->stamp($action, $changes);
        $payload['admin_edits'] = array_slice($edits, -20);

        // saveQuietly — 흔적을 남기는 것뿐인데 타임시트 재계산이나 알림이 또 도는 것은
        // 낭비다. 실제 재계산은 delete()/restore() 가 일으킨다.
        $row->forceFill(['payload' => $payload])->saveQuietly();
    }

    /**
     * 한 기록의 수정 이력.
     *
     * @return array<string, mixed>
     */
    public function history(int $id): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '출퇴근 기록 조회 권한이 없습니다.'];
        }

        $row = AttendanceLog::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '기록을 찾을 수 없습니다.'];
        }

        return ['success' => true, 'edits' => array_reverse($row->payload['admin_edits'] ?? [])];
    }

    /** @param array<string, mixed> $changes */
    private function stamp(string $action, array $changes): array
    {
        return [
            'action' => $action,
            'by' => auth()->user()?->name,
            'byId' => auth()->id(),
            'at' => Carbon::now()->toDateTimeString(),
            'changes' => $changes,
        ];
    }

    /** 현장 담당자는 자기 현장만. 전체 권한이면 그대로 둔다. */
    private function applyScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }
        // 협력사 관리자는 자기 회사 사람의 출퇴근만 본다.
        if (AccessPolicy::lockedCompanyId($user) !== null) {
            AccessPolicy::applyCompanyLock($query, $user);

            return;
        }

        if (AccessPolicy::canManageMoney($user)
            || $user->access_scope === 'all_sites') {
            return;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            $query->where('site_id', $user->allowed_site_id);

            return;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            $query->where('company_id', $user->allowed_company_id);

            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function inScope(AttendanceLog $row): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager'], true)
            || $user->access_scope === 'all_sites') {
            return true;
        }
        if ($user->access_scope === 'site' && $user->allowed_site_id) {
            return (int) $row->site_id === (int) $user->allowed_site_id;
        }
        if ($user->access_scope === 'company' && $user->allowed_company_id) {
            return (int) $row->company_id === (int) $user->allowed_company_id;
        }

        return false;
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '' || $v === '0') ? null : (int) $v;
    }
}
