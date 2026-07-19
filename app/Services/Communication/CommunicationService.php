<?php

namespace App\Services\Communication;

use App\Models\AttendanceLog;
use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageRead;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CommunicationService
{
    /**
     * @return array{chat: CommunicationRoom, announcements: CommunicationRoom}
     */
    public function ensureSiteRooms(Site $site): array
    {
        $site->loadMissing('company');

        $chat = CommunicationRoom::query()->firstOrCreate(
            [
                'site_id' => $site->id,
                'type' => CommunicationRoom::TYPE_SITE_CHAT,
            ],
            [
                'company_id' => $site->company_id,
                'scope' => 'site',
                'name' => $this->roomName($site, '현장방'),
                'description' => '같은 현장 직원끼리 소통하는 내부 채팅방입니다.',
                'status' => 'active',
                'is_read_only' => false,
            ],
        );

        $announcements = CommunicationRoom::query()->firstOrCreate(
            [
                'site_id' => $site->id,
                'type' => CommunicationRoom::TYPE_SITE_ANNOUNCEMENT,
            ],
            [
                'company_id' => $site->company_id,
                'scope' => 'site',
                'name' => $this->roomName($site, '공지방'),
                'description' => '관리자와 현장관리자가 주요 공지를 등록하는 알림방입니다.',
                'status' => 'active',
                'is_read_only' => true,
            ],
        );

        $this->syncSiteRoomMembers($site, collect([$chat, $announcements]));

        return ['chat' => $chat->fresh(), 'announcements' => $announcements->fresh()];
    }

    /**
     * @return array{chat: CommunicationRoom, announcements: CommunicationRoom}|null
     */
    public function ensureEmployeeSiteRooms(Employee $employee): ?array
    {
        if (! $employee->site_id) {
            return null;
        }

        return $this->ensureSiteRooms($employee->site()->firstOrFail());
    }

    /**
     * Provision every standing room the employee belongs to: their site rooms
     * plus the company- and crew-wide chat rooms. Idempotent.
     */
    public function ensureEmployeeRooms(Employee $employee): void
    {
        $this->ensureEmployeeSiteRooms($employee);

        if ($employee->company_id) {
            $this->ensureCompanyRoom($employee->company()->firstOrFail());
        }

        if ($employee->team_id) {
            $this->ensureTeamRoom($employee->team()->firstOrFail());
        }
    }

    public function ensureCompanyRoom(Company $company): CommunicationRoom
    {
        $room = CommunicationRoom::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'type' => CommunicationRoom::TYPE_COMPANY,
            ],
            [
                'scope' => 'company',
                'name' => ($company->code ?: 'COMPANY') . ' 회사방',
                'description' => '같은 회사 소속 직원 전체가 소통하는 채팅방입니다.',
                'status' => 'active',
                'is_read_only' => false,
            ],
        );

        Employee::query()
            ->with('user')
            ->where('company_id', $company->id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->each(fn (Employee $employee) => $this->ensureRoomMember($room, $employee));

        return $room->fresh();
    }

    public function ensureTeamRoom(Team $team): CommunicationRoom
    {
        $team->loadMissing('company');

        $room = CommunicationRoom::query()->firstOrCreate(
            [
                'team_id' => $team->id,
                'type' => CommunicationRoom::TYPE_TEAM,
            ],
            [
                'company_id' => $team->company_id,
                'scope' => 'team',
                'name' => ($team->name ?: 'CREW') . ' 팀방',
                'description' => '같은 팀(크루) 직원끼리 소통하는 채팅방입니다.',
                'status' => 'active',
                'is_read_only' => false,
            ],
        );

        Employee::query()
            ->with('user')
            ->where('team_id', $team->id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->each(fn (Employee $employee) => $this->ensureRoomMember($room, $employee));

        return $room->fresh();
    }

    /**
     * @param  Collection<int, CommunicationRoom>|null  $rooms
     */
    public function syncSiteRoomMembers(Site $site, ?Collection $rooms = null): void
    {
        $rooms ??= CommunicationRoom::query()
            ->where('site_id', $site->id)
            ->whereIn('type', [CommunicationRoom::TYPE_SITE_CHAT, CommunicationRoom::TYPE_SITE_ANNOUNCEMENT])
            ->active()
            ->get();

        if ($rooms->isEmpty()) {
            return;
        }

        Employee::query()
            ->with('user')
            ->where('site_id', $site->id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->each(function (Employee $employee) use ($rooms): void {
                foreach ($rooms as $room) {
                    $this->ensureRoomMember($room, $employee);
                }
            });
    }

    public function ensureRoomMember(CommunicationRoom $room, Employee $employee, string $role = 'member'): CommunicationRoomMember
    {
        $employee->loadMissing('user');

        return CommunicationRoomMember::query()->updateOrCreate(
            [
                'communication_room_id' => $room->id,
                'employee_id' => $employee->id,
            ],
            [
                'user_id' => $employee->user?->id,
                'role' => $role,
                'status' => 'active',
                'joined_at' => Carbon::now(),
            ],
        );
    }

    /**
     * @return Collection<int, CommunicationRoom>
     */
    public function roomsForUser(User $user): Collection
    {
        if ($user->employee) {
            $this->ensureEmployeeRooms($user->employee);
        }

        return $this->roomQueryForUser($user)
            ->with(['site', 'team', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderBy('name')
            ->get();
    }

    public function roomQueryForUser(User $user): Builder
    {
        $query = CommunicationRoom::query()->active();

        // DMs are always private — even admins only see the ones they belong to.
        if ($this->hasAllSiteAccess($user)) {
            return $query->where(function (Builder $query) use ($user): void {
                $query->where('type', '!=', CommunicationRoom::TYPE_DIRECT)
                    ->orWhereHas('members', fn (Builder $memberQuery) => $this->applyMemberIdentity($memberQuery, $user));
            });
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereHas('members', fn (Builder $memberQuery) => $this->applyMemberIdentity($memberQuery, $user));

            // Scope-based visibility never covers DMs (they carry no company/site/team).
            match ($user->access_scope) {
                'company' => $query->orWhere(fn (Builder $q) => $q->where('type', '!=', CommunicationRoom::TYPE_DIRECT)->where('company_id', $user->allowed_company_id)),
                'site' => $query->orWhere(fn (Builder $q) => $q->where('type', '!=', CommunicationRoom::TYPE_DIRECT)->where('site_id', $user->allowed_site_id)),
                'team' => $query->orWhere(fn (Builder $q) => $q->where('type', '!=', CommunicationRoom::TYPE_DIRECT)->where('team_id', $user->allowed_team_id)),
                'self' => null,
                default => null,
            };
        });
    }

    private function applyMemberIdentity(Builder $memberQuery, User $user): void
    {
        $memberQuery->where('status', 'active')
            ->where(function (Builder $identityQuery) use ($user): void {
                $identityQuery->where('user_id', $user->id);

                if ($user->employee_id) {
                    $identityQuery->orWhere('employee_id', $user->employee_id);
                }
            });
    }

    public function canAccessRoom(?User $user, CommunicationRoom $room): bool
    {
        if (! $user || $room->status !== 'active') {
            return false;
        }

        // DMs bypass role/scope entirely — only the two participants may enter.
        if ($room->type === CommunicationRoom::TYPE_DIRECT) {
            return $this->membershipForUser($room, $user)->exists();
        }

        if ($this->hasAllSiteAccess($user)) {
            return true;
        }

        if ($this->membershipForUser($room, $user)->exists()) {
            return true;
        }

        return match ($user->access_scope) {
            'company' => filled($user->allowed_company_id) && (int) $room->company_id === (int) $user->allowed_company_id,
            'site' => filled($user->allowed_site_id) && (int) $room->site_id === (int) $user->allowed_site_id,
            'team' => filled($user->allowed_team_id) && (int) $room->team_id === (int) $user->allowed_team_id,
            default => false,
        };
    }

    public function canPost(?User $user, CommunicationRoom $room, ?CommunicationMessage $parent = null): bool
    {
        if (! $this->canAccessRoom($user, $room)) {
            return false;
        }

        if ($parent !== null) {
            return (int) $parent->communication_room_id === (int) $room->id;
        }

        if (! $room->is_read_only) {
            return true;
        }

        return in_array($user?->access_role, ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function postMessage(User $user, CommunicationRoom $room, string $body, array $attributes = []): CommunicationMessage
    {
        $message = CommunicationMessage::query()->create([
            'communication_room_id' => $room->id,
            'sender_user_id' => $user->id,
            'sender_employee_id' => $user->employee_id,
            'kind' => $attributes['kind'] ?? CommunicationMessage::KIND_MESSAGE,
            'title' => $this->nullableText($attributes['title'] ?? null),
            'body' => trim($body),
            'parent_id' => $attributes['parent_id'] ?? null,
            'related_type' => $attributes['related_type'] ?? null,
            'related_id' => $attributes['related_id'] ?? null,
            'is_pinned' => (bool) ($attributes['is_pinned'] ?? false),
            'priority' => $attributes['priority'] ?? 'normal',
            'status' => 'active',
            'payload' => $attributes['payload'] ?? null,
        ]);

        $this->markMessageRead($message, $user);

        if ($message->kind === CommunicationMessage::KIND_ANNOUNCEMENT && $message->parent_id === null) {
            $this->fanOutAnnouncement($message, $room);
        }

        return $message;
    }

    // ---- direct messages ---------------------------------------------------

    /**
     * Find or create the private 1:1 room for two employees. The dm_key is the
     * sorted employee id pair, so the room is the same regardless of who opens it.
     */
    public function directRoomFor(Employee $a, Employee $b): CommunicationRoom
    {
        $ids = [$a->id, $b->id];
        sort($ids);
        $key = $ids[0] . '-' . $ids[1];

        $room = CommunicationRoom::query()->firstOrCreate(
            ['dm_key' => $key],
            [
                'type' => CommunicationRoom::TYPE_DIRECT,
                'scope' => 'direct',
                'name' => 'DM',
                'status' => 'active',
                'is_read_only' => false,
            ],
        );

        $this->ensureRoomMember($room, $a);
        $this->ensureRoomMember($room, $b);

        return $room->fresh();
    }

    /**
     * The other participant of a DM room, from the given user's point of view.
     */
    public function directCounterpart(CommunicationRoom $room, ?User $user): ?Employee
    {
        if ($room->type !== CommunicationRoom::TYPE_DIRECT || ! filled($room->dm_key)) {
            return null;
        }

        $ids = array_map('intval', explode('-', (string) $room->dm_key));
        $otherId = collect($ids)->first(fn (int $id) => $id !== (int) $user?->employee_id) ?? $ids[0] ?? null;

        return $otherId ? Employee::query()->find($otherId) : null;
    }

    /**
     * Active employees the user may start a DM with — same site for scoped users,
     * everyone for admins. Excludes the user themselves.
     *
     * @return Collection<int, Employee>
     */
    public function directCandidatesForUser(User $user, ?string $search = null): Collection
    {
        $query = Employee::query()
            ->where('employment_status', 'active')
            ->when($user->employee_id, fn (Builder $q) => $q->where('id', '!=', $user->employee_id));

        if (! $this->hasAllSiteAccess($user)) {
            $siteId = $user->employee?->site_id ?? $user->allowed_site_id;

            if ($siteId) {
                $query->where('site_id', $siteId);
            } elseif ($user->allowed_company_id) {
                $query->where('company_id', $user->allowed_company_id);
            }
        }

        if (filled($search)) {
            $query->where('name', 'like', '%' . trim($search) . '%');
        }

        return $query->orderBy('name')->limit(30)->get();
    }

    // ---- notification feed (bell) ------------------------------------------

    /**
     * Push a notification to every active member of the announcement room
     * except the author. Feeds the top-bar bell.
     */
    private function fanOutAnnouncement(CommunicationMessage $message, CommunicationRoom $room): void
    {
        $title = trim((string) ($message->title ?: $room->name));
        $body = mb_substr(trim($message->body), 0, 160);

        CommunicationRoomMember::query()
            ->where('communication_room_id', $room->id)
            ->where('status', 'active')
            ->get()
            ->each(function (CommunicationRoomMember $member) use ($message, $room, $title, $body): void {
                if ($member->employee_id !== null && (int) $member->employee_id === (int) $message->sender_employee_id) {
                    return;
                }

                if ($member->employee_id === null && $member->user_id !== null && (int) $member->user_id === (int) $message->sender_user_id) {
                    return;
                }

                CommunicationNotification::query()->create([
                    'user_id' => $member->user_id,
                    'employee_id' => $member->employee_id,
                    'communication_room_id' => $room->id,
                    'communication_message_id' => $message->id,
                    'type' => CommunicationNotification::TYPE_ANNOUNCEMENT,
                    'title' => $title,
                    'body' => $body,
                ]);
            });
    }

    /**
     * @return Collection<int, CommunicationNotification>
     */
    public function notificationsForUser(User $user, int $limit = 20): Collection
    {
        return $this->notificationQueryForUser($user)
            ->with('room')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadNotificationCountForUser(User $user): int
    {
        return $this->notificationQueryForUser($user)->unread()->count();
    }

    public function markNotificationsRead(User $user): void
    {
        $this->notificationQueryForUser($user)->unread()->update(['read_at' => Carbon::now()]);
    }

    private function notificationQueryForUser(User $user): Builder
    {
        return CommunicationNotification::query()->where(function (Builder $query) use ($user): void {
            if ($user->employee_id) {
                $query->where('employee_id', $user->employee_id);
            } else {
                $query->where('user_id', $user->id);
            }
        });
    }

    public function publishAttendanceAlert(AttendanceLog $log): ?CommunicationMessage
    {
        $log->loadMissing(['employee', 'site', 'team', 'company']);

        $site = $log->site ?: $log->employee?->site;
        if (! $site) {
            return null;
        }

        $room = $this->ensureSiteRooms($site)['chat'];
        $employeeName = $log->employee?->name ?: 'Unknown employee';
        $eventLabel = $log->event_type === 'clock_out' ? '퇴근' : '출근';
        $statusLabel = match ($log->status) {
            'approved' => '완료',
            'pending' => '승인대기',
            'rejected' => '반려',
            default => (string) $log->status,
        };

        return CommunicationMessage::query()->firstOrCreate(
            [
                'communication_room_id' => $room->id,
                'kind' => CommunicationMessage::KIND_ATTENDANCE_ALERT,
                'related_type' => AttendanceLog::class,
                'related_id' => $log->id,
            ],
            [
                'company_id' => $log->company_id ?: $room->company_id,
                'site_id' => $log->site_id ?: $room->site_id,
                'team_id' => $log->team_id ?: $room->team_id,
                'title' => "{$employeeName} {$eventLabel} {$statusLabel}",
                'body' => $this->attendanceAlertBody($log, $employeeName, $eventLabel, $statusLabel),
                'priority' => $log->status === 'pending' ? 'important' : 'normal',
                'status' => 'active',
                'sent_at' => Carbon::now(),
                'payload' => [
                    'attendance_log_id' => $log->id,
                    'event_type' => $log->event_type,
                    'attendance_status' => $log->status,
                    'source' => $log->source,
                ],
            ],
        );
    }

    public function markRoomRead(User $user, CommunicationRoom $room): void
    {
        $messages = CommunicationMessage::query()
            ->where('communication_room_id', $room->id)
            ->active()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'communication_room_id']);

        $latestMessageId = $messages->max('id');

        foreach ($messages as $message) {
            $this->markMessageRead($message, $user);
        }

        $membership = $this->membershipForUser($room, $user)->first();
        if ($membership) {
            $membership->update([
                'last_read_message_id' => $latestMessageId,
                'last_read_at' => Carbon::now(),
            ]);
        } elseif ($user->employee_id) {
            CommunicationRoomMember::query()->updateOrCreate(
                [
                    'communication_room_id' => $room->id,
                    'employee_id' => $user->employee_id,
                ],
                [
                    'user_id' => $user->id,
                    'role' => 'member',
                    'status' => 'active',
                    'joined_at' => Carbon::now(),
                    'last_read_message_id' => $latestMessageId,
                    'last_read_at' => Carbon::now(),
                ],
            );
        }
    }

    public function markMessageRead(CommunicationMessage $message, User $user): void
    {
        $identity = $user->employee_id
            ? ['communication_message_id' => $message->id, 'employee_id' => $user->employee_id]
            : ['communication_message_id' => $message->id, 'user_id' => $user->id];

        CommunicationMessageRead::query()->updateOrCreate(
            $identity,
            [
                'communication_room_id' => $message->communication_room_id,
                'user_id' => $user->id,
                'employee_id' => $user->employee_id,
                'read_at' => Carbon::now(),
            ],
        );
    }

    /**
     * @return array<int, int>
     */
    public function unreadCountsForUser(User $user): array
    {
        $counts = [];

        $this->roomsForUser($user)->each(function (CommunicationRoom $room) use ($user, &$counts): void {
            $membership = $this->membershipForUser($room, $user)->first();
            $lastReadId = (int) ($membership?->last_read_message_id ?? 0);

            $counts[$room->id] = CommunicationMessage::query()
                ->where('communication_room_id', $room->id)
                ->active()
                ->where('id', '>', $lastReadId)
                ->when($user->employee_id, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($user): void {
                    $query->whereNull('sender_employee_id')
                        ->orWhere('sender_employee_id', '!=', $user->employee_id);
                }))
                ->when(! $user->employee_id, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($user): void {
                    $query->whereNull('sender_user_id')
                        ->orWhere('sender_user_id', '!=', $user->id);
                }))
                ->count();
        });

        return $counts;
    }

    public function unreadCountForUser(User $user): int
    {
        return array_sum($this->unreadCountsForUser($user));
    }

    private function membershipForUser(CommunicationRoom $room, User $user): Builder
    {
        return CommunicationRoomMember::query()
            ->where('communication_room_id', $room->id)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($user->employee_id) {
                    $query->orWhere('employee_id', $user->employee_id);
                }
            });
    }

    private function hasAllSiteAccess(User $user): bool
    {
        return in_array($user->access_role, ['super_admin', 'admin'], true)
            || $user->access_scope === 'all_sites';
    }

    private function roomName(Site $site, string $suffix): string
    {
        $code = $site->code ?: 'SITE';

        return "{$code} {$suffix}";
    }

    private function nullableText(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function attendanceAlertBody(AttendanceLog $log, string $employeeName, string $eventLabel, string $statusLabel): string
    {
        $siteName = $log->site?->name ?: $log->employee?->site?->name ?: '-';
        $teamName = $log->team?->name ?: $log->employee?->team?->name ?: '-';
        $eventAt = $log->event_at?->format('Y-m-d H:i') ?: '-';

        return implode("\n", [
            "[출석 알림]",
            "{$employeeName}님 {$eventLabel} {$statusLabel}",
            "현장: {$siteName}",
            "팀: {$teamName}",
            "시간: {$eventAt}",
            "기록방식: " . ($log->source ?: '-'),
        ]);
    }
}
