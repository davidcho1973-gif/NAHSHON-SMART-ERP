<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\CommunicationRoom;
use App\Models\User;

/**
 * "내가 마지막으로 본 뒤로 새로 온 것" 만 돌려준다.
 *
 * 새로고침해야 새 글이 보이는 대화창은 대화창이 아니다. 그렇다고 방 전체를 몇 초마다
 * 다시 내려받으면 현장 통신망과 요금이 함께 죽는다 — 그래서 <b>마지막으로 받은
 * 메시지 번호 이후</b>만 준다.
 *
 * 유튜브 라이브 채팅도 웹소켓이 아니라 이 방식이다: 서버가 응답에 "다음엔 몇 초 뒤에
 * 물어봐" 를 함께 실어 보내고, 클라이언트는 그 간격을 따른다. 연결을 계속 붙들고
 * 있는 것보다 싸고, 어떤 환경에서도 동작한다.
 *
 * 폴링 간격을 <b>서버가 정하는</b> 이유가 여기 있다. 화면에 3초를 박아 두면 나중에
 * 요금이 문제가 됐을 때 앱을 새로 배포해야 바꿀 수 있다. 서버가 정하면 조용한 방은
 * 알아서 느려지고, 대화가 오가는 방만 빨라진다.
 *
 * 나중에 WebSocket(Reverb)으로 갈아탈 때 화면은 그대로 둔다 — 화면은 "새 메시지
 * 목록" 만 받을 뿐, 그것이 폴링으로 왔는지 소켓으로 왔는지 모른다.
 */
class RoomStreamService
{
    /** 대화가 방금 오간 방 — 빠르게 본다. */
    private const HOT_SECONDS = 3;

    /** 조용한 방 — 천천히 본다. 조용할수록 더 천천히. */
    private const WARM_SECONDS = 10;

    private const COLD_SECONDS = 30;

    private const IDLE_SECONDS = 60;

    public function __construct(private readonly CommunicationService $communication) {}

    /**
     * @return array{messages: array<int, array<string, mixed>>, lastId: int, nextPollMs: int, membersCount: int}
     */
    public function since(CommunicationRoom $room, User $user, int $afterId): array
    {
        $messages = CommunicationMessage::query()
            ->with(['senderEmployee', 'senderUser', 'files'])
            ->where('communication_room_id', $room->id)
            ->where('id', '>', max(0, $afterId))
            ->active()
            ->orderBy('id')
            ->limit(100)
            ->get();

        // 받아 간 순간 읽은 것으로 친다 — 화면에 떠 있는데 안 읽음으로 남으면
        // 미읽음 숫자가 영원히 줄지 않는다.
        if ($messages->isNotEmpty()) {
            $this->communication->markRoomRead($user, $room);
        }

        $lastId = (int) ($messages->last()->id ?? $afterId);

        return [
            'messages' => $messages->map(fn (CommunicationMessage $m): array => $this->row($m, $room))->all(),
            'lastId' => $lastId,
            'nextPollMs' => $this->nextPollMs($room, $messages->isNotEmpty()) * 1000,
            'membersCount' => $room->activeMembers()->count(),
        ];
    }

    /**
     * 다음에 언제 다시 물어볼지. 방금 대화가 오갔으면 빠르게, 조용하면 천천히.
     * 앱이 잠들 수 있는 배포(scale to zero)에서는 이 간격이 곧 요금이다.
     */
    private function nextPollMs(CommunicationRoom $room, bool $gotSomething): int
    {
        if ($gotSomething) {
            return self::HOT_SECONDS;
        }

        $lastAt = $room->last_message_at ?? $room->updated_at;
        $quietMinutes = $lastAt ? $lastAt->diffInMinutes(now()) : 999;

        return match (true) {
            $quietMinutes < 3 => self::HOT_SECONDS,
            $quietMinutes < 20 => self::WARM_SECONDS,
            $quietMinutes < 120 => self::COLD_SECONDS,
            default => self::IDLE_SECONDS,
        };
    }

    /**
     * 화면이 그대로 그릴 수 있는 모양으로. 여기서 정한 모양이 나중에 소켓으로
     * 바뀌어도 그대로 쓰이도록, 전송 방식과 무관한 순수한 내용만 담는다.
     *
     * @return array<string, mixed>
     */
    private function row(CommunicationMessage $message, CommunicationRoom $room): array
    {
        return [
            'id' => (int) $message->id,
            'parentId' => $message->parent_id ? (int) $message->parent_id : null,
            'kind' => (string) $message->kind,
            'title' => $message->title,
            'body' => (string) $message->body,
            'priority' => (string) $message->priority,
            'sender' => $message->senderEmployee?->name
                ?? $message->senderUser?->name
                ?? ($message->kind === CommunicationMessage::KIND_SYSTEM ? '🤖 AI' : 'SMART ERP'),
            'sentAt' => $message->sent_at?->format('m/d H:i'),
            'files' => $message->files->map(fn (CommunicationMessageFile $f): array => [
                'id' => (int) $f->id,
                'name' => (string) $f->original_name,
                'size' => $f->humanSize(),
                'isImage' => $f->isImage(),
                'url' => ($f->disk && $f->path)
                    ? route('communication.file', ['room' => $room, 'file' => $f], false)
                    : null,
            ])->all(),
        ];
    }
}
