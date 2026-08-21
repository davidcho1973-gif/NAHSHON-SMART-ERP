<?php

namespace App\Services\Push;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
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
 */
class ChatPushNotifier
{
    public function __construct(private readonly WebPushSender $sender) {}

    public function notify(CommunicationMessage $message): int
    {
        if (! $this->sender->available() || ! $this->shouldNotify($message)) {
            return 0;
        }

        $room = $message->room;
        if (! $room) {
            return 0;
        }

        $recipients = $this->recipients($room, $message);
        if ($recipients === []) {
            return 0;
        }

        return $this->sender->sendToUsers($recipients, [
            'title' => $this->title($room, $message),
            'body' => $this->preview($message),
            'url' => "/attendance-app/messages/{$room->id}",
            'tag' => "room-{$room->id}",
            'priority' => $message->priority,
        ]);
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
     * 그 방의 사람들 — 보낸 사람만 뺀다.
     *
     * @return array<int, int>
     */
    private function recipients(CommunicationRoom $room, CommunicationMessage $message): array
    {
        $employeeIds = $room->activeMembers()->pluck('employee_id')->filter()->unique();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('employee_id', $employeeIds->all())
            ->where('account_status', 'active')
            ->when($message->sender_user_id, fn ($q) => $q->where('id', '!=', $message->sender_user_id))
            ->pluck('id')
            ->all();
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
