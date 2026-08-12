<?php

namespace App\Services\Admin;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

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

    public const SOURCES = [
        'web_portal' => '웹 포탈',
        'team_qr' => 'QR 스캔',
        'nfc_reader' => 'NFC 리더',
        'gps' => 'GPS',
        'gate' => '게이트',
        'manual' => '수기 입력',
    ];

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
     * 목록. 기본은 최근 것부터 — 고칠 일이 생기는 건 대개 어제오늘 기록이다.
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

        $rows = $query->get()->map(fn (AttendanceLog $r): array => [
            'id' => $r->id,
            'employeeId' => $r->employee_id,
            'employee' => $r->employee?->name,
            'employeeNumber' => $r->employee?->employee_number,
            'date' => $r->attendance_date?->toDateString(),
            'eventAt' => $r->event_at?->toDateTimeString(),
            'eventTime' => $r->event_at?->format('H:i'),
            'eventType' => $r->event_type,
            'eventTypeLabel' => self::EVENT_TYPES[$r->event_type] ?? (string) $r->event_type,
            'status' => $r->status,
            'statusLabel' => self::STATUSES[$r->status] ?? (string) $r->status,
            'source' => $r->source,
            'sourceLabel' => self::SOURCES[$r->source] ?? (string) $r->source,
            'siteId' => $r->site_id,
            'site' => $r->site?->code,
            'company' => $r->company?->name,
            'notes' => $r->notes,
            'approvedBy' => $r->approvedBy?->name,
            // 고친 적이 있으면 목록에서 바로 보이게 한다 — 급여 담당이 되짚을 단서다.
            'editCount' => count($r->payload['admin_edits'] ?? []),
            // 지워진 기록인지. 화면이 그 줄을 다르게 그리고 '되살리기' 를 준다.
            'deleted' => $r->trashed(),
            'deletedAt' => $r->deleted_at?->toDateTimeString(),
            'canDelete' => $this->canDelete(),
        ])->values()->all();

        return ['success' => true, 'rows' => $rows, 'canManage' => $this->canManage(), 'canDelete' => $this->canDelete()];
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
            'sources' => $pairs(self::SOURCES),
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
        if (! array_key_exists($source, self::SOURCES)) {
            $source = 'manual';
        }

        $eventAt = null;
        if ($eventAtRaw === '') {
            $errors['eventAt'] = '기록 시각을 입력하세요.';
        } else {
            try {
                $eventAt = Carbon::parse($eventAtRaw);
            } catch (\Throwable) {
                $errors['eventAt'] = '시각 형식이 올바르지 않습니다.';
            }
        }

        // 미래 시각은 사실일 수 없다. 오타(2026 → 2027)를 여기서 잡는다.
        if ($eventAt && $eventAt->isAfter(Carbon::now()->addDay())) {
            $errors['eventAt'] = '미래 시각은 기록할 수 없습니다.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // 현장의 시간대로 날짜를 계산한다. 서버가 UTC 라 자정 근처 기록이
        // 하루 밀리면 그날 인원과 급여가 어긋난다.
        $siteId = $this->intOrNull($input['siteId'] ?? null) ?: $employee->site_id;
        $tz = $siteId ? (Site::find($siteId)?->timezone ?: config('app.timezone')) : config('app.timezone');
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
        if (in_array($user->access_role, ['super_admin', 'admin', 'hr_manager', 'payroll'], true)
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
