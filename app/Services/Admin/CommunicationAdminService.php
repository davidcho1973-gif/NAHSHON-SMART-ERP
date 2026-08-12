<?php

namespace App\Services\Admin;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Communication\CommunicationService;

/**
 * 메신저 방 · 메시지 관리 — Filament CommunicationRoomResource / CommunicationMessageResource
 * 를 SPA 로 옮긴 것.
 *
 * 직원이 실제로 대화하는 곳은 /attendance-app/messages 다. 여기는 그 뒤편이다 —
 * 방을 만들고, 현장 직원을 넣고, 잘못 올라간 글을 지우는 관리 화면.
 *
 * 방 만들기는 대부분 자동이다(현장을 만들면 채팅방·공지방이 함께 생긴다). 그래서
 * 여기서 자주 하는 일은 "만들기"가 아니라 "구성원 동기화" 다 — 나중에 합류한 직원은
 * 방에 안 들어가 있어서 공지를 못 본다. 그 버튼을 표에서 바로 누를 수 있게 둔다.
 *
 * 방 삭제는 막는다. 방을 지우면 그 안의 대화가 전부 사라지는데, 현장에서 주고받은
 * 지시·확인은 나중에 "그때 뭐라고 했나" 를 따질 유일한 기록인 경우가 많다.
 */
class CommunicationAdminService
{
    public const VIEW_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];

    public const MANAGE_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'];

    /** 표에 한 번에 실을 메시지 수. 전부 부르면 화면이 멎는다. */
    private const MESSAGE_LIMIT = 300;

    public function __construct(private readonly CommunicationService $communication) {}

    public function canView(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null && $actor->account_status === 'active'
            && in_array($actor->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null && $actor->account_status === 'active'
            && in_array($actor->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '메신저 관리를 볼 권한이 없습니다.'];
        }

        $rooms = CommunicationRoom::query()
            ->with(['site:id,code', 'team:id,name'])
            ->withCount(['members', 'messages'])
            ->orderByRaw('last_message_at desc nulls last')
            ->orderBy('name')
            ->get()
            ->map(fn (CommunicationRoom $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'type' => $r->type,
                'typeLabel' => CommunicationRoom::TYPE_OPTIONS[$r->type] ?? (string) $r->type,
                'description' => $r->description,
                'siteId' => $r->site_id,
                'site' => $r->site?->code,
                'teamId' => $r->team_id,
                'team' => $r->team?->name,
                'isReadOnly' => (bool) $r->is_read_only,
                'status' => $r->status,
                'memberCount' => (int) $r->members_count,
                'messageCount' => (int) $r->messages_count,
                'lastMessageAt' => $r->last_message_at?->toDateTimeString(),
                // 현장 방만 "현장 직원 동기화" 가 의미 있다 — 넣을 명단의 기준이 현장이다.
                'canSyncMembers' => $r->site_id !== null,
            ])->values()->all();

        $messages = CommunicationMessage::query()
            ->with(['room:id,name', 'senderEmployee:id,name'])
            ->withCount('reads')
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->map(fn (CommunicationMessage $m): array => [
                'id' => $m->id,
                'roomId' => $m->communication_room_id,
                'room' => $m->room?->name,
                'kind' => $m->kind,
                'kindLabel' => CommunicationMessage::KIND_OPTIONS[$m->kind] ?? (string) $m->kind,
                'title' => $m->title,
                'body' => $m->body,
                'sender' => $m->senderEmployee?->name,
                'priority' => $m->priority,
                'isPinned' => (bool) $m->is_pinned,
                'isReply' => $m->parent_id !== null,
                'readCount' => (int) $m->reads_count,
                'sentAt' => $m->sent_at?->toDateTimeString(),
            ])->values()->all();

        return [
            'success' => true,
            'rooms' => $rooms,
            'messages' => $messages,
            'messageLimit' => self::MESSAGE_LIMIT,
            'canManage' => $this->canManage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '메신저 관리를 볼 권한이 없습니다.'];
        }

        return [
            'success' => true,
            'types' => $this->pairs(CommunicationRoom::TYPE_OPTIONS),
            'statuses' => $this->pairs(CommunicationRoom::STATUS_OPTIONS),
            'kinds' => $this->pairs(CommunicationMessage::KIND_OPTIONS),
            'priorities' => $this->pairs(CommunicationMessage::PRIORITY_OPTIONS),
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Site $s): array => ['value' => (string) $s->id, 'label' => $s->code.' · '.$s->name])->all(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Team $t): array => ['value' => (string) $t->id, 'label' => $t->name])->all(),
            'rooms' => CommunicationRoom::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (CommunicationRoom $r): array => ['value' => (string) $r->id, 'label' => $r->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveRoom(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '메신저 방을 만들거나 고칠 권한이 없습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);
        $room = $id > 0 ? CommunicationRoom::query()->find($id) : new CommunicationRoom;
        if ($id > 0 && ! $room) {
            return ['success' => false, 'error' => '메신저 방을 찾을 수 없습니다.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => '방 이름은 필수입니다.'];
        }

        $type = (string) ($input['type'] ?? '');
        if (! isset(CommunicationRoom::TYPE_OPTIONS[$type])) {
            return ['success' => false, 'error' => '방 유형을 선택해 주세요.'];
        }
        // 1:1 방은 두 직원의 조합(dm_key)으로만 만들어져야 짝이 어긋나지 않는다.
        if ($type === CommunicationRoom::TYPE_DIRECT && $id === 0) {
            return ['success' => false, 'error' => '1:1 메시지 방은 직원이 대화를 시작할 때 자동으로 만들어집니다. 여기서 만들 수 없습니다.'];
        }

        $siteId = ($input['site_id'] ?? '') !== '' ? (int) $input['site_id'] : null;
        if ($siteId !== null && ! Site::query()->whereKey($siteId)->exists()) {
            return ['success' => false, 'error' => '현장을 찾을 수 없습니다.'];
        }

        $site = $siteId !== null ? Site::query()->find($siteId) : null;

        $room->fill([
            'type' => $type,
            'name' => $name,
            'description' => $this->text($input['description'] ?? null),
            'site_id' => $siteId,
            'team_id' => ($input['team_id'] ?? '') !== '' ? (int) $input['team_id'] : null,
            'company_id' => $room->company_id ?: $site?->company_id,
            'is_read_only' => (bool) ($input['is_read_only'] ?? false),
            'status' => isset(CommunicationRoom::STATUS_OPTIONS[$input['status'] ?? '']) ? $input['status'] : ($room->status ?: 'active'),
            'created_by_id' => $room->created_by_id ?: auth()->id(),
        ]);
        $room->save();

        return ['success' => true, 'id' => $room->id];
    }

    /**
     * 현장 직원을 방 구성원으로 채운다.
     *
     * 나중에 합류한 직원은 방에 없어서 공지를 못 본다. 방을 다시 만들 필요는 없고
     * 명단만 맞추면 된다 — 이미 있는 사람은 그대로 두므로 몇 번 눌러도 안전하다.
     *
     * @return array<string, mixed>
     */
    public function syncRoomMembers(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '구성원을 동기화할 권한이 없습니다.'];
        }

        $room = CommunicationRoom::query()->with('site')->find($id);
        if (! $room) {
            return ['success' => false, 'error' => '메신저 방을 찾을 수 없습니다.'];
        }
        if (! $room->site) {
            return ['success' => false, 'error' => '현장에 속한 방만 직원을 동기화할 수 있습니다.'];
        }

        $before = $room->members()->count();
        $this->communication->syncSiteRoomMembers($room->site, collect([$room]));
        $after = $room->members()->count();

        return ['success' => true, 'added' => max($after - $before, 0), 'total' => $after];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRoom(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '메신저 방을 삭제할 권한이 없습니다.'];
        }

        $room = CommunicationRoom::query()->find($id);
        if (! $room) {
            return ['success' => false, 'error' => '메신저 방을 찾을 수 없습니다.'];
        }

        // 대화가 오간 방은 지우지 않는다 — 현장 지시·확인의 유일한 기록일 수 있다.
        $messages = $room->messages()->count();
        if ($messages > 0) {
            return ['success' => false, 'error' => "메시지 {$messages}건이 오간 방입니다. 삭제 대신 상태를 Archived 로 바꿔 주세요. 기록은 남고 목록에서는 내려갑니다."];
        }

        $room->members()->delete();
        $room->delete();

        return ['success' => true];
    }

    /**
     * 방에 글을 올린다 / 이미 올린 글을 고친다.
     *
     * 새 글은 CommunicationService 를 거친다 — 공지 확산·읽음 처리가 거기 붙어 있고,
     * 여기서 직접 만들면 그게 통째로 빠진다.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveMessage(array $input): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '메시지를 쓰거나 고칠 권한이 없습니다.'];
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            return ['success' => false, 'error' => '내용을 입력해 주세요.'];
        }

        $kind = (string) ($input['kind'] ?? CommunicationMessage::KIND_MESSAGE);
        if (! isset(CommunicationMessage::KIND_OPTIONS[$kind])) {
            return ['success' => false, 'error' => '메시지 유형이 올바르지 않습니다.'];
        }
        $priority = (string) ($input['priority'] ?? 'normal');
        if (! isset(CommunicationMessage::PRIORITY_OPTIONS[$priority])) {
            return ['success' => false, 'error' => '중요도가 올바르지 않습니다.'];
        }

        $id = (int) ($input['id'] ?? 0);

        if ($id > 0) {
            $message = CommunicationMessage::query()->find($id);
            if (! $message) {
                return ['success' => false, 'error' => '메시지를 찾을 수 없습니다.'];
            }
            // 방은 못 옮긴다. 옮기면 그 방 사람들이 읽지도 않은 글을 읽은 것으로 남는다.
            $message->fill([
                'kind' => $kind,
                'title' => $this->text($input['title'] ?? null),
                'body' => $body,
                'priority' => $priority,
                'is_pinned' => (bool) ($input['is_pinned'] ?? false),
            ]);
            $message->save();

            return ['success' => true, 'id' => $message->id];
        }

        $room = CommunicationRoom::query()->find((int) ($input['communication_room_id'] ?? 0));
        if (! $room) {
            return ['success' => false, 'error' => '메신저 방을 선택해 주세요.'];
        }

        $actor = auth()->user();
        if (! $actor) {
            return ['success' => false, 'error' => '로그인이 필요합니다.'];
        }

        $message = $this->communication->postMessage($actor, $room, $body, [
            'kind' => $kind,
            'title' => $this->text($input['title'] ?? null),
            'priority' => $priority,
            'is_pinned' => (bool) ($input['is_pinned'] ?? false),
        ]);

        return ['success' => true, 'id' => $message->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteMessage(int $id): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '메시지를 삭제할 권한이 없습니다.'];
        }

        $message = CommunicationMessage::query()->find($id);
        if (! $message) {
            return ['success' => false, 'error' => '메시지를 찾을 수 없습니다.'];
        }

        $replies = $message->replies()->count();
        if ($replies > 0) {
            return ['success' => false, 'error' => "댓글 {$replies}건이 달린 메시지입니다. 댓글을 먼저 정리해 주세요."];
        }

        $message->reads()->delete();
        $message->delete();

        return ['success' => true];
    }

    /** @param array<string, string> $map */
    private function pairs(array $map): array
    {
        return array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => $v], array_keys($map), array_values($map));
    }

    private function text(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
