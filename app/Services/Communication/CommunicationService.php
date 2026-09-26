<?php

namespace App\Services\Communication;

use App\Models\AttendanceLog;
use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageReaction;
use App\Models\CommunicationMessageRead;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Services\Admin\CommunicationAdminService;
use App\Services\Push\ChatPushNotifier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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

        // 현장 상황실 — 형식 없이 올린 글·사진을 AI 가 읽고 공정표·조달에 반영한다.
        $ops = CommunicationRoom::query()->firstOrCreate(
            [
                'site_id' => $site->id,
                'type' => CommunicationRoom::TYPE_SITE_OPS,
            ],
            [
                'company_id' => $site->company_id,
                'scope' => 'site',
                'name' => $this->roomName($site, '현장 상황실'),
                'description' => '오늘 한 일 · 내일 할 일 · 자재 · 영수증 · 이슈를 그냥 올리세요. AI 가 읽고 공정표에 반영합니다.',
                'status' => 'active',
                'is_read_only' => false,
            ],
        );

        $this->syncSiteRoomMembers($site, collect([$chat, $announcements, $ops]));

        return ['chat' => $chat->fresh(), 'announcements' => $announcements->fresh(), 'ops' => $ops->fresh()];
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

        // 방금 대화가 오간 방이 맨 위 — 카카오톡·슬랙과 같은 규칙. PostgreSQL 은 내림차순에서
        // 빈 값(글이 한 번도 없는 방)을 맨 앞에 두므로, 빈 값은 뒤로 보낸다고 적어 둔다.
        return $this->roomQueryForUser($user)
            ->with(['site', 'team', 'latestMessage'])
            ->orderByRaw('last_message_at desc nulls last')
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

        return $this->isLead($user);
    }

    /**
     * 방을 이끄는 사람 — 공지 쓰기, "@모두" 부르기, 긴급(🚨) 보내기가 여기에 묶인다.
     *
     * 명단은 방 관리 권한과 같은 한 벌을 쓴다. 여기에 따로 적어 두면 한쪽에 역할이
     * 추가될 때 다른 쪽은 그대로 남는다.
     */
    public function isLead(?User $user): bool
    {
        return in_array($user?->access_role, CommunicationAdminService::MANAGE_ROLES, true);
    }

    /**
     * "@모두" 는 방 전원의 폰을 울린다 — 알림을 "부를 때만" 으로 줄여 둔 사람까지.
     * 아무나 쓰면 알림 설정이 무의미해지므로 방을 이끄는 사람만 쓴다. 다른 사람이 쓰면
     * 그냥 글자로 남는다.
     */
    public function canCallEveryone(?User $user): bool
    {
        return $this->isLead($user);
    }

    /** 긴급(🚨)은 알림을 꺼 둔 사람에게도 울린다 — 그래서 방을 이끄는 사람만 보낸다. */
    public function canPostUrgent(?User $user): bool
    {
        return $this->isLead($user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function postMessage(User $user, CommunicationRoom $room, string $body, array $attributes = []): CommunicationMessage
    {
        $calls = app(MentionResolver::class)->resolve($room, $body, $user, $this->canCallEveryone($user));
        $payload = $attributes['payload'] ?? null;
        if ($calls['people'] !== [] || $calls['everyone']) {
            $payload = array_merge((array) $payload, $this->mentionPayload($calls));
        }

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
            'payload' => $payload,
        ]);

        $this->markMessageRead($message, $user);

        if ($message->kind === CommunicationMessage::KIND_ANNOUNCEMENT && $message->parent_id === null) {
            $this->fanOutAnnouncement($message, $room);
        }

        $targeted = $this->deliverPersonal($message, $room, $user, $calls);

        // 폰이 주머니에 있어도 닿게 한다. 알림이 실패해도 글은 이미 올라갔다 —
        // 알림 때문에 전송이 죽으면 안 되므로 여기서 삼킨다.
        try {
            app(ChatPushNotifier::class)->notify($message, $targeted);
        } catch (\Throwable $e) {
            report($e);
        }

        return $message;
    }

    // ---- 부르기(@멘션) · 답글 알림 -------------------------------------------

    /**
     * @param  array{people: list<array{employee_id: int|null, user_id: int|null, name: string}>, everyone: bool}  $calls
     * @return array<string, mixed>
     */
    private function mentionPayload(array $calls): array
    {
        return ['mentions' => $calls['people'], 'mention_everyone' => $calls['everyone']];
    }

    /**
     * 이 글이 콕 집은 사람들에게 활동함 알림을 남기고, 그 사람들의 계정 번호를 돌려준다
     * (알림을 "부를 때만" 으로 둔 사람도 이 명단에 있으면 폰이 울린다).
     *
     * 콕 집는 길은 셋이다: "@이름", "@모두", 그리고 <b>내가 쓰거나 답한 글에 달린 답글</b>.
     * 세 번째가 빠지면 질문을 올려 두고 답이 달렸는지 몇 번이고 들어가 봐야 한다.
     *
     * 1:1 방에는 활동함 알림을 남기지 않는다 — 두 사람뿐이라 방 목록의 안 읽은 수가
     * 곧 그 알림이다. 같은 일을 두 곳에서 알리면 둘 다 안 본다.
     *
     * @param  array{people: list<array{employee_id: int|null, user_id: int|null, name: string}>, everyone: bool}  $calls
     * @return list<int>
     */
    private function deliverPersonal(CommunicationMessage $message, CommunicationRoom $room, User $author, array $calls): array
    {
        $calledIds = $this->deliverPersonalCalls($message, $room, $author, $calls);

        if (! $message->parent_id) {
            return $calledIds;
        }

        // 이미 이름으로 부른 사람에게 "답글이 달렸다" 를 또 보내지 않는다.
        $followers = $this->threadFollowers($message, $room, $author)
            ->reject(fn (User $u): bool => in_array((int) $u->id, $calledIds, true))
            ->values();

        if ($room->type !== CommunicationRoom::TYPE_DIRECT) {
            $this->notifyPeople(
                $message,
                $room,
                $author,
                $followers->map(fn (User $u): array => [
                    'employee_id' => $u->employee_id ? (int) $u->employee_id : null,
                    'user_id' => (int) $u->id,
                ])->all(),
                CommunicationNotification::TYPE_REPLY,
            );
        }

        return array_values(array_unique(array_merge(
            $calledIds,
            $followers->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        )));
    }

    /**
     * "@이름" · "@모두" 로 부른 사람들에게 알림을 남기고 그 계정 번호를 돌려준다.
     *
     * @param  array{people: list<array{employee_id: int|null, user_id: int|null, name: string}>, everyone: bool}  $calls
     * @return list<int>
     */
    private function deliverPersonalCalls(CommunicationMessage $message, CommunicationRoom $room, User $author, array $calls): array
    {
        $called = $calls['everyone']
            ? $this->memberIdentities($room, $author)
            : array_map(fn (array $p): array => ['employee_id' => $p['employee_id'], 'user_id' => $p['user_id']], $calls['people']);

        if ($room->type !== CommunicationRoom::TYPE_DIRECT) {
            $this->notifyPeople($message, $room, $author, $called, CommunicationNotification::TYPE_MENTION);
        }

        return $this->usersFor($called)
            ->reject(fn (User $u): bool => (int) $u->id === (int) $author->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 답글이 달린 글의 "구독자" — 원글을 쓴 사람과 그 아래 먼저 답한 사람들.
     * 지금 이 방에 못 들어오는 사람(현장을 옮겼거나 퇴사)에게는 보내지 않는다.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function threadFollowers(CommunicationMessage $reply, CommunicationRoom $room, User $author): Collection
    {
        $parent = CommunicationMessage::query()->find($reply->parent_id);
        if (! $parent) {
            return collect();
        }

        $senderIds = CommunicationMessage::query()
            ->where('parent_id', $parent->id)
            ->where('id', '!=', $reply->id)
            ->whereNotNull('sender_user_id')
            ->pluck('sender_user_id')
            ->push($parent->sender_user_id)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $author->id)
            ->unique()
            ->values();

        if ($senderIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $senderIds->all())
            ->where('account_status', 'active')
            ->get()
            ->filter(fn (User $u): bool => $this->canAccessRoom($u, $room))
            ->values();
    }

    /**
     * @param  list<array{employee_id: int|null, user_id: int|null}>  $people
     */
    private function notifyPeople(CommunicationMessage $message, CommunicationRoom $room, User $author, array $people, string $type): void
    {
        if ($people === []) {
            return;
        }

        $sender = $author->employee?->name ?? $author->name ?? '';
        $preview = trim($message->body) !== ''
            ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message->body)), 0, 160)
            : '📎';

        foreach ($people as $person) {
            if (! $person['employee_id'] && ! $person['user_id']) {
                continue;
            }

            // 알림의 제목은 부른 사람의 이름만 담는다. "…님이 불렀습니다" 같은 문장은
            // 화면이 보는 사람의 언어로 붙인다 — 저장된 한국어 문장은 번역되지 않는다.
            CommunicationNotification::query()->create([
                'user_id' => $person['user_id'],
                'employee_id' => $person['employee_id'],
                'communication_room_id' => $room->id,
                'communication_message_id' => $message->id,
                'type' => $type,
                'title' => mb_substr($sender !== '' ? $sender : 'SMART ERP', 0, 255),
                'body' => $preview,
            ]);
        }
    }

    /**
     * 방 사람 전원(글쓴이 제외)의 신원 — "@모두" 가 부르는 범위.
     *
     * @return list<array{employee_id: int|null, user_id: int|null}>
     */
    private function memberIdentities(CommunicationRoom $room, User $author): array
    {
        return $room->activeMembers()
            ->get(['employee_id', 'user_id'])
            ->reject(fn (CommunicationRoomMember $m): bool => ($m->user_id !== null && (int) $m->user_id === (int) $author->id)
                || ($m->employee_id !== null && $author->employee_id !== null && (int) $m->employee_id === (int) $author->employee_id))
            ->map(fn (CommunicationRoomMember $m): array => [
                'employee_id' => $m->employee_id ? (int) $m->employee_id : null,
                'user_id' => $m->user_id ? (int) $m->user_id : null,
            ])
            ->values()
            ->all();
    }

    /**
     * 신원(직원 번호 · 계정 번호)을 로그인 계정으로 — 폰은 계정에 묶여 있다.
     *
     * @param  list<array{employee_id: int|null, user_id: int|null}>  $people
     * @return Collection<int, User>
     */
    private function usersFor(array $people): Collection
    {
        $employeeIds = array_values(array_filter(array_column($people, 'employee_id')));
        $userIds = array_values(array_filter(array_column($people, 'user_id')));

        if ($employeeIds === [] && $userIds === []) {
            return collect();
        }

        return User::query()
            ->where('account_status', 'active')
            ->where(function (Builder $q) use ($employeeIds, $userIds): void {
                $q->whereIn('id', $userIds ?: [0])->orWhereIn('employee_id', $employeeIds ?: [0]);
            })
            ->get();
    }

    // ---- 반응(✅ 확인) · 고정 -------------------------------------------------

    /**
     * 반응을 누르거나(없으면) 거둔다(있으면). 지운 글에는 누를 수 없다.
     *
     * 글의 수정 시각을 건드려 둔다 — 열려 있는 다른 사람 화면이 "바뀐 글" 로 받아
     * 숫자를 고쳐 그리게 하려는 것이다.
     */
    public function toggleReaction(User $user, CommunicationMessage $message, string $emoji): bool
    {
        if ($message->isRemoved() || ! in_array($emoji, CommunicationMessageReaction::ALLOWED, true)) {
            return false;
        }

        $existing = CommunicationMessageReaction::query()
            ->where('communication_message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            CommunicationMessageReaction::query()->firstOrCreate(
                ['communication_message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji],
                ['communication_room_id' => $message->communication_room_id, 'employee_id' => $user->employee_id],
            );
        }

        $message->touch();

        return true;
    }

    /**
     * 글 하나의 반응 요약 — 종류별 수 · 누른 사람 · 내가 눌렀는지. ✅ 가 늘 맨 앞이다.
     * 화면(stream)과 누른 직후의 응답이 같은 모양을 받도록 여기 한 곳에서 만든다.
     *
     * @return list<array{emoji: string, count: int, mine: bool, names: list<string>}>
     */
    public function reactionSummary(CommunicationMessage $message, ?User $viewer): array
    {
        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->with(['employee:id,name', 'user:id,name'])->get();

        $summary = [];
        foreach (CommunicationMessageReaction::ALLOWED as $emoji) {
            $same = $reactions->where('emoji', $emoji);
            if ($same->isEmpty()) {
                continue;
            }
            $summary[] = [
                'emoji' => $emoji,
                'count' => $same->count(),
                'mine' => $viewer !== null && $same->contains(fn (CommunicationMessageReaction $r): bool => (int) $r->user_id === (int) $viewer->id),
                'names' => $same->map(fn (CommunicationMessageReaction $r): string => $r->personName())->filter()->values()->all(),
            ];
        }

        return $summary;
    }

    /**
     * 글을 방 위에 꽂아 둘 수 있는가 — 그 방에 새 글을 쓸 수 있는 사람이면.
     * 공지방에서는 관리자만 쓰므로 관리자만 꽂는다.
     */
    public function canPin(?User $user, CommunicationMessage $message, ?bool $canPostHere = null): bool
    {
        if ($message->isRemoved()) {
            return false;
        }

        // 대화 목록을 그릴 때는 글마다 방 권한을 다시 묻지 않도록 한 번 구한 답을 넘겨받는다.
        return $canPostHere ?? ($message->room !== null && $this->canPost($user, $message->room));
    }

    /** 꽂거나 뺀다. 누가 꽂았는지는 남긴다 — "이거 누가 올려 둔 거예요?" 를 없앤다. */
    public function togglePin(User $user, CommunicationMessage $message): bool
    {
        $payload = (array) ($message->payload ?? []);
        $pinned = ! $message->is_pinned;

        if ($pinned) {
            $payload['pinned_by'] = [
                'user_id' => (int) $user->id,
                'name' => (string) ($user->employee?->name ?? $user->name),
                'at' => now()->toIso8601String(),
            ];
        } else {
            unset($payload['pinned_by']);
        }

        $message->update(['is_pinned' => $pinned, 'payload' => $payload ?: null]);

        return $pinned;
    }

    /**
     * 방에 꽂아 둔 글 — 최근에 꽂은 것부터.
     *
     * @return list<array{id: int, sender: string, body: string, pinnedBy: string|null, sentAt: string|null}>
     */
    public function pinsFor(CommunicationRoom $room): array
    {
        return CommunicationMessage::query()
            ->with(['senderEmployee:id,name', 'senderUser:id,name'])
            ->where('communication_room_id', $room->id)
            ->active()
            ->whereNull('removed_at')
            ->where('is_pinned', true)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            // 꽂은 시각 순 — 공지처럼 올릴 때 자동으로 꽂힌 글은 올린 시각이 곧 꽂은 시각이다.
            ->sortByDesc(fn (CommunicationMessage $m): string => (string) ($m->payload['pinned_by']['at'] ?? $m->sent_at?->toIso8601String() ?? ''))
            ->take(30)
            ->values()
            ->map(fn (CommunicationMessage $m): array => [
                'id' => (int) $m->id,
                'sender' => (string) ($m->senderEmployee?->name ?? $m->senderUser?->name ?? ($m->kind === CommunicationMessage::KIND_SYSTEM ? '🤖 AI' : 'SMART ERP')),
                'body' => mb_substr(trim((string) ($m->title ? $m->title.' — '.$m->body : $m->body)), 0, 140),
                'pinnedBy' => $m->payload['pinned_by']['name'] ?? null,
                'sentAt' => $m->sent_at?->format('n/j H:i'),
            ])
            ->all();
    }

    // ---- 방별 알림 설정 ------------------------------------------------------

    /** 이 사람이 이 방에서 쓰는 알림 수준(고른 적 없으면 방 기본값). */
    public function notifyLevelFor(User $user, CommunicationRoom $room): string
    {
        $membership = $this->membershipForUser($room, $user)->first();

        return $membership?->effectiveNotifyLevel($room) ?? $room->defaultNotifyLevel();
    }

    /**
     * 방마다 언제 울릴지 고른다. 방 구성원이 아니어도 볼 수 있는 방(관리자·현장 범위)이면
     * 고르는 순간 구성원이 된다 — 설정을 둘 자리가 구성원 줄이기 때문이다.
     */
    public function setNotifyLevel(User $user, CommunicationRoom $room, string $level): bool
    {
        if (! in_array($level, CommunicationRoom::NOTIFY_LEVELS, true) || ! $this->canAccessRoom($user, $room)) {
            return false;
        }

        $membership = $this->membershipForUser($room, $user)->first();
        if (! $membership) {
            // 직원 기록이 없는 관리자 계정도 있다 — 그때는 계정으로 구성원이 된다.
            $membership = $user->employee
                ? $this->ensureRoomMember($room, $user->employee)
                : CommunicationRoomMember::query()->updateOrCreate(
                    ['communication_room_id' => $room->id, 'user_id' => $user->id],
                    ['role' => 'member', 'status' => 'active', 'joined_at' => Carbon::now()],
                );
        }

        $membership->update(['notify_level' => $level]);

        return true;
    }

    /**
     * 방마다 이 사람의 알림 수준 — 방 목록에서 조용히 해 둔 방을 흐리게 보이려고.
     *
     * @param  Collection<int, CommunicationRoom>  $rooms
     * @return array<int, string>
     */
    public function notifyLevelsForUser(User $user, Collection $rooms): array
    {
        $members = CommunicationRoomMember::query()
            ->whereIn('communication_room_id', $rooms->pluck('id')->all() ?: [0])
            ->tap(fn (Builder $q) => $this->applyMemberIdentity($q, $user))
            ->get()
            ->keyBy('communication_room_id');

        $levels = [];
        foreach ($rooms as $room) {
            $levels[$room->id] = $members->get($room->id)?->effectiveNotifyLevel($room) ?? $room->defaultNotifyLevel();
        }

        return $levels;
    }

    // ---- 본인 글 고치기·지우기 ---------------------------------------------

    /**
     * 잘못 쓴 글은 본인이 고칠 수 있다. 다만 고친 흔적은 남는다 —
     * 조용히 바뀌면 "분명히 다르게 봤는데" 가 되고, 그 다툼은 (수정됨) 한 마디로 끝난다.
     */
    public function canEdit(?User $user, CommunicationMessage $message): bool
    {
        if (! $user || $message->isRemoved()) {
            return false;
        }

        // 로봇이 쓴 글은 사람이 고치지 않는다 — 근거 기록이기 때문이다.
        if ($message->kind === CommunicationMessage::KIND_SYSTEM) {
            return false;
        }

        return (int) $message->sender_user_id === (int) $user->id;
    }

    /** 지우는 것은 본인과 관리자 — 관리자는 남의 부적절한 글을 내려야 할 때가 있다. */
    public function canRemove(?User $user, CommunicationMessage $message): bool
    {
        if (! $user || $message->isRemoved()) {
            return false;
        }

        // 로봇(AI·시스템) 글은 근거 기록이다 — 실수 클릭 한 번에 사라지면 안 되므로
        // 최고 관리자만 내릴 수 있다. (canEdit 는 이미 전면 금지)
        if ($message->kind === CommunicationMessage::KIND_SYSTEM) {
            return in_array($user->access_role, ['super_admin', 'admin'], true);
        }

        return (int) $message->sender_user_id === (int) $user->id
            || in_array($user->access_role, ['super_admin', 'admin', 'site_manager'], true);
    }

    /**
     * 고친 글에서 이름을 새로 부르면 그 사람에게만 알린다. 이미 불렀던 사람을 다시
     * 울리면 오타 하나 고칠 때마다 폰이 울린다.
     */
    public function editMessage(User $user, CommunicationMessage $message, string $body): CommunicationMessage
    {
        $room = $message->room;
        $before = collect($message->payload['mentions'] ?? [])
            ->map(fn (array $p): string => ($p['employee_id'] ?? '').':'.($p['user_id'] ?? ''));
        $everyoneBefore = (bool) ($message->payload['mention_everyone'] ?? false);

        $calls = $room
            ? app(MentionResolver::class)->resolve($room, $body, $user, $this->canCallEveryone($user))
            : ['people' => [], 'everyone' => false];

        $payload = (array) ($message->payload ?? []);
        unset($payload['mentions'], $payload['mention_everyone']);
        if ($calls['people'] !== [] || $calls['everyone']) {
            $payload = array_merge($payload, $this->mentionPayload($calls));
        }

        $message->update(['body' => trim($body), 'edited_at' => now(), 'payload' => $payload ?: null]);

        $fresh = [
            'people' => $everyoneBefore ? [] : array_values(array_filter(
                $calls['people'],
                fn (array $p): bool => ! $before->contains(($p['employee_id'] ?? '').':'.($p['user_id'] ?? '')),
            )),
            'everyone' => $calls['everyone'] && ! $everyoneBefore,
        ];

        if ($room && ($fresh['people'] !== [] || $fresh['everyone'])) {
            $targeted = $this->deliverPersonalCalls($message, $room, $user, $fresh);
            try {
                app(ChatPushNotifier::class)->notify($message->fresh(), $targeted, onlyTargeted: true);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $message->fresh();
    }

    /**
     * 글을 감춘다 — 지우지 않는다.
     *
     * 현장 지시는 나중에 분쟁의 증거가 된다. 데이터베이스에서 진짜로 지우면 그 증거가
     * 사라지고, 무엇이 있었는지조차 알 수 없게 된다. 카카오톡처럼 자리는 남기고
     * 내용만 감춘다.
     *
     * 첨부가 문서함으로 이미 넘어간 경우, 그 문서는 여기서 지우지 않는다 —
     * 경비·장비 원장이 그 문서를 근거로 삼고 있을 수 있다. 문서는 문서함에서 지운다.
     */
    public function removeMessage(User $user, CommunicationMessage $message): CommunicationMessage
    {
        $message->update(['removed_at' => now(), 'removed_by_user_id' => $user->id]);

        return $message->fresh();
    }

    /** 지금 이 방을 보고 있다고 표시한다 — 상대가 화면 앞에 있는지 알 수 있게. */
    public function touchPresence(User $user, CommunicationRoom $room): void
    {
        $query = CommunicationRoomMember::query()->where('communication_room_id', $room->id);
        $this->applyMemberIdentity($query, $user);

        $query->update(['last_seen_at' => now()]);
    }

    /**
     * 이 방에 누가 있는가 — 그리고 지금 보고 있는가.
     *
     * @return array<int, array{name: string, role: string, online: bool, lastSeen: string|null}>
     */
    public function presence(CommunicationRoom $room, int $onlineWithinMinutes = 3): array
    {
        $people = $room->activeMembers()
            ->with(['employee', 'user'])
            ->get()
            ->map(function (CommunicationRoomMember $m) use ($onlineWithinMinutes): array {
                $seen = $m->last_seen_at;

                return [
                    'name' => $m->employee?->name ?? $m->user?->name ?? '이름 없음',
                    'role' => (string) ($m->role ?: 'member'),
                    'online' => $seen !== null && $seen->gt(now()->subMinutes($onlineWithinMinutes)),
                    'lastSeen' => $seen?->diffForHumans(),
                    'bot' => false,
                ];
            })
            ->sortByDesc('online')
            ->values()
            ->all();

        // AI 도 이 방의 참여자다 — 참여자 목록에 없으면 아무도 부를 수 있는 줄 모른다.
        // 열쇠가 없는 배포에서는 넣지 않는다(불러도 답이 없는 이름이 가장 나쁘다).
        if (app(ChatAssistant::class)->available()) {
            array_unshift($people, [
                'name' => ChatAssistant::DISPLAY_NAME,
                'role' => 'assistant',
                'online' => true,
                'lastSeen' => ChatAssistant::HANDLE.' 로 부르면 답합니다',
                'bot' => true,
            ]);
        }

        return $people;
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

        // 협력사 관리자는 자기 회사 사람에게만 말을 걸 수 있다 — 명단 자체가 정보다.
        \App\Support\AccessPolicy::applyCompanyLock($query, $user);

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

    public function unreadNotificationCountForUser(User $user): int
    {
        return $this->notificationQueryForUser($user)->unread()->count();
    }

    /**
     * 활동함 — 나에게 온 알림(부름 · 답글 · 공지)을 종류별로.
     *
     * @return Collection<int, CommunicationNotification>
     */
    public function activityForUser(User $user, ?string $type = null, int $limit = 60): Collection
    {
        return $this->notificationQueryForUser($user)
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->with(['room', 'message'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** 이 알림이 이 사람 것인가 — 남의 알림 번호로 읽음 처리하거나 따라 들어가지 못하게. */
    public function ownsNotification(User $user, CommunicationNotification $notification): bool
    {
        return $this->notificationQueryForUser($user)->whereKey($notification->id)->exists();
    }

    public function markNotificationRead(CommunicationNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => Carbon::now()]);
        }
    }

    /**
     * 방마다 "나를 부른 것" 중 안 읽은 수 — 방 목록에 빨간 @ 로 보인다.
     * 알림을 줄여 둔 방이라도 나를 부른 글은 눈에 띄어야 한다.
     *
     * @return array<int, int>
     */
    public function personalUnreadByRoom(User $user): array
    {
        return $this->notificationQueryForUser($user)
            ->unread()
            ->whereIn('type', CommunicationNotification::PERSONAL_TYPES)
            ->whereNotNull('communication_room_id')
            ->selectRaw('communication_room_id, count(*) as n')
            ->groupBy('communication_room_id')
            ->pluck('n', 'communication_room_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
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

        // 방을 열어 봤으면 그 방에서 나를 부른 알림도 본 것이다 — 활동함에 남아 있으면
        // 이미 읽은 것을 또 열어 보게 된다.
        $this->notificationQueryForUser($user)
            ->where('communication_room_id', $room->id)
            ->unread()
            ->update(['read_at' => Carbon::now()]);

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
        // A receipt may have been written before the employee and login records
        // were linked. Reuse either identity instead of inserting a second row.
        $existing = fn () => CommunicationMessageRead::query()
            ->where('communication_message_id', $message->id)
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($user->employee_id) {
                    $query->orWhere('employee_id', $user->employee_id);
                }
            })
            ->first();

        $read = $existing();

        if (! $read) {
            try {
                $read = CommunicationMessageRead::query()->create([
                    'communication_message_id' => $message->id,
                    'communication_room_id' => $message->communication_room_id,
                    'user_id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'read_at' => Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent page and stream requests can mark the same message.
                $read = $existing();
            }
        }

        $read?->update([
            'communication_room_id' => $message->communication_room_id,
            'read_at' => Carbon::now(),
        ]);
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
        $eventAt = \App\Support\SiteClock::show($log->site_id, $log->event_at, 'Y-m-d H:i') ?: '-';

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
