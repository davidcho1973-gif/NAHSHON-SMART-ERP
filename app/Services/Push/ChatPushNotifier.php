<?php

namespace App\Services\Push;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 방에 글이 올라오면 그 방 사람들의 폰을 울린다.
 *
 * 지키는 것:
 *  - 보낸 사람에게는 보내지 않는다(자기 글에 알림이 오면 앱을 끈다).
 *  - 로봇 답글은 울리지 않는다 — AI 가 문서를 읽을 때마다 폰이 울리면 아무도 안 본다.
 *    (사람이 필요한 경우, 즉 판독이 어긋났거나 확인이 필요할 때는 예외로 울린다.)
 *  - 미리보기는 짧게. 잠금화면에 급여·금액이 통째로 뜨면 곤란하다.
 *  - 알림이 실패해도 메시지 전송은 이미 끝나 있다 — 이 안에서 전부 삼킨다.
 *  - 사람마다 방별로 고른 수준(모든 글 / 부를 때만 / 끄기)을 따른다. 누가 울리는지는
 *    {@see rings()} 한 곳이 정한다.
 */
class ChatPushNotifier
{
    public function __construct(private readonly WebPushSender $sender) {}

    /**
     * @param  list<int>  $targeted  이 글이 콕 집은 사람(부름 · 내 글에 단 답글)의 계정 번호
     * @param  bool  $onlyTargeted  고친 글에서 새로 부른 사람만 울릴 때 — 나머지는 이미 알림을 받았다
     */
    public function notify(CommunicationMessage $message, array $targeted = [], bool $onlyTargeted = false): int
    {
        if (! $this->sender->available() || ! $this->shouldNotify($message)) {
            return 0;
        }

        $room = $message->room;
        if (! $room) {
            return 0;
        }

        $recipients = $this->recipients($room, $message, $targeted, $onlyTargeted);
        if ($recipients === []) {
            return 0;
        }

        $base = [
            'body' => $this->preview($message),
            'url' => "/attendance-app/messages/{$room->id}",
            'tag' => "room-{$room->id}",
            'priority' => $message->priority,
        ];

        // 나를 부른 글은 제목부터 다르게 — 잠금화면에서 "내 일" 인지 한눈에 보이게.
        $called = array_values(array_intersect($recipients, $targeted));
        $others = array_values(array_diff($recipients, $targeted));

        $sent = 0;
        if ($called !== []) {
            $sent += $this->sender->sendToUsers($called, ['title' => '@ '.$this->title($room, $message)] + $base);
        }
        if ($others !== []) {
            $sent += $this->sender->sendToUsers($others, ['title' => $this->title($room, $message)] + $base);
        }

        return $sent;
    }

    /**
     * 이 사람의 폰을 울리는가.
     *
     * 긴급(🚨)은 무조건 울린다 — 알림을 꺼 두는 것이 안전하려면 이 예외가 있어야 한다.
     * 그 밖에는 사람이 고른 수준을 따른다.
     */
    public static function rings(string $level, CommunicationMessage $message, bool $targeted): bool
    {
        if ($message->priority === 'urgent') {
            return true;
        }

        return match ($level) {
            CommunicationRoom::NOTIFY_ALL => true,
            CommunicationRoom::NOTIFY_MENTIONS => $targeted,
            default => false,
        };
    }

    private function shouldNotify(CommunicationMessage $message): bool
    {
        if ($message->status !== 'active') {
            return false;
        }

        if ($message->kind !== CommunicationMessage::KIND_SYSTEM) {
            return true;
        }

        // 로봇 답글 중에서도 사람의 확인이 필요한 것만 울린다.
        $body = (string) $message->body;

        return str_contains($body, '⚠️') || str_contains($body, '❓');
    }

    /**
     * 그 방의 사람들 중 울릴 사람 — 보낸 사람은 빼고, 각자 고른 알림 수준을 따른다.
     *
     * @param  list<int>  $targeted
     * @return array<int, int>
     */
    private function recipients(CommunicationRoom $room, CommunicationMessage $message, array $targeted, bool $onlyTargeted): array
    {
        $members = $room->activeMembers()->get(['employee_id', 'user_id', 'notify_level']);
        if ($members->isEmpty()) {
            return [];
        }

        $byEmployee = $members->filter(fn (CommunicationRoomMember $m) => $m->employee_id)->keyBy('employee_id');
        $byUser = $members->filter(fn (CommunicationRoomMember $m) => $m->user_id)->keyBy('user_id');

        $users = User::query()
            ->where('account_status', 'active')
            ->where(function ($q) use ($byEmployee, $byUser): void {
                $q->whereIn('employee_id', $byEmployee->keys()->all() ?: [0])
                    ->orWhereIn('id', $byUser->keys()->all() ?: [0]);
            })
            ->when($message->sender_user_id, fn ($q) => $q->where('id', '!=', $message->sender_user_id))
            ->get(['id', 'employee_id']);

        $ids = [];
        foreach ($users as $user) {
            $member = ($user->employee_id ? $byEmployee->get($user->employee_id) : null) ?? $byUser->get($user->id);
            $isTargeted = in_array((int) $user->id, $targeted, true);

            if ($onlyTargeted && ! $isTargeted) {
                continue;
            }

            if (self::rings($member?->effectiveNotifyLevel($room) ?? $room->defaultNotifyLevel(), $message, $isTargeted)) {
                $ids[] = (int) $user->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function title(CommunicationRoom $room, CommunicationMessage $message): string
    {
        $sender = $message->senderEmployee?->name
            ?? $message->senderUser?->name
            ?? ($message->kind === CommunicationMessage::KIND_SYSTEM ? 'AI' : 'SMART ERP');

        $prefix = match ($message->priority) {
            'urgent' => '🚨 ',
            'important' => '📢 ',
            default => '',
        };

        return $prefix.($room->name ?: '메시지').' · '.$sender;
    }

    /** 잠금화면에 통째로 뜨는 글이다 — 짧게 자른다. */
    private function preview(CommunicationMessage $message): string
    {
        $body = trim((string) $message->body);

        if ($body === '') {
            return $message->files()->exists() ? '📎 파일을 보냈습니다.' : '새 메시지가 있습니다.';
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $body) ?? '', 90);
    }
}
