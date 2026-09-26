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

    /** 처음 열 때 보여줄 최근 대화 수. 위로 올리면 이만큼씩 더 불러온다. */
    private const FIRST_PAGE = 60;

    /** 알림을 눌러 특정 글로 들어올 때, 그 글 위로 함께 보여줄 앞 대화 수. */
    private const FOCUS_CONTEXT = 20;

    /**
     * 바뀐 글을 찾을 때 겹쳐 보는 초. 시각은 초 단위로 저장되므로, 같은 초 안에 생긴
     * 변화를 놓치지 않으려고 조금 겹친다(겹쳐 온 글은 화면이 제자리에서 다시 그릴 뿐이다).
     */
    private const CHANGE_OVERLAP_SECONDS = 2;

    public function __construct(private readonly CommunicationService $communication) {}

    /**
     * @param  int  $focusId  알림에서 눌러 들어온 글 — 처음 열 때 그 글 주변을 준다
     * @param  string|null  $changedSince  지난번 응답의 cursor — 그 뒤로 바뀐(고침·지움·반응) 글도 준다
     * @return array<string, mixed>
     */
    public function since(CommunicationRoom $room, User $user, int $afterId, int $focusId = 0, ?string $changedSince = null): array
    {
        // 이 사람이 지금 이 방을 보고 있다고 표시한다 — 상대가 화면 앞에 있는지 알 수 있게.
        $this->communication->touchPresence($user, $room);

        $base = $this->baseQuery($room);
        $hasOlder = false;

        if ($afterId <= 0 && $focusId > 0) {
            // 알림을 눌러 들어온 경우 — 가장 최근이 아니라 <b>그 글</b>이 보여야 한다.
            $before = (clone $base)->where('id', '<', $focusId)->orderByDesc('id')->limit(self::FOCUS_CONTEXT)->get();
            $from = (clone $base)->where('id', '>=', $focusId)->orderBy('id')->limit(100)->get();
            $messages = $before->merge($from)->sortBy('id')->values();
        } elseif ($afterId <= 0) {
            // 처음 여는 화면 — 방의 첫 글이 아니라 <b>가장 최근</b> 대화가 보여야 한다.
            // 오래된 것부터 100개를 주면 1년 전 대화를 보며 스크롤을 내려야 한다.
            $messages = (clone $base)->orderByDesc('id')->limit(self::FIRST_PAGE)->get()->sortBy('id')->values();
        } else {
            // 새 글뿐 아니라 <b>그사이 바뀐 글</b>(고침·지움·반응)도 내려보낸다 —
            // 안 그러면 지운 글이 남의 화면에는 그대로 남아 있는다.
            $changed = $this->parseCursor($changedSince);
            $messages = $base->where(function ($q) use ($afterId, $changed): void {
                $q->where('id', '>', $afterId);

                if ($changed !== null) {
                    $q->orWhere('updated_at', '>=', $changed->subSeconds(self::CHANGE_OVERLAP_SECONDS));
                } else {
                    // 커서를 모르는 옛 화면(배포 전에 열어 둔 탭)을 위한 길.
                    $q->orWhere('edited_at', '>=', now()->subMinutes(2))
                        ->orWhere('removed_at', '>=', now()->subMinutes(2));
                }
            })->orderBy('id')->limit(100)->get();
        }

        if ($afterId <= 0 && $messages->isNotEmpty()) {
            $hasOlder = CommunicationMessage::query()
                ->where('communication_room_id', $room->id)
                ->active()
                ->where('id', '<', (int) $messages->min('id'))
                ->exists();
        }

        // 받아 간 순간 읽은 것으로 친다 — 화면에 떠 있는데 안 읽음으로 남으면
        // 미읽음 숫자가 영원히 줄지 않는다.
        if ($messages->isNotEmpty()) {
            $this->communication->markRoomRead($user, $room);
        }

        // 고쳐진 옛 글이 섞여 오므로 마지막 줄이 곧 최신 번호는 아니다 — 가장 큰 번호를 쓴다.
        $lastId = max($afterId, (int) $messages->max('id'));

        $presence = $this->communication->presence($room);

        // 사람 수를 셀 때 AI 는 빼고 센다 — "3명" 이 사람 셋을 뜻하지 않으면
        // 그 숫자를 보고 아무도 판단할 수 없다. AI 는 목록에만 보인다.
        $humans = array_values(array_filter($presence, fn (array $m): bool => ! ($m['bot'] ?? false)));

        return [
            'messages' => $this->rows($messages, $room, $user),
            'lastId' => $lastId,
            'hasOlder' => $hasOlder,
            'cursor' => now()->toIso8601String(),
            'nextPollMs' => $this->nextPollMs($room, $messages->isNotEmpty()) * 1000,
            'membersCount' => count($humans),
            'onlineCount' => count(array_filter($humans, fn (array $m): bool => $m['online'])),
            'members' => $presence,
        ];
    }

    /**
     * 위로 올려 더 오래된 대화를 본다 — 처음 60개 밖의 과거.
     *
     * 이것이 없으면 두 달 전 지시는 "있었는데 못 찾는" 것이 된다. 읽음 표시나 접속
     * 표시는 건드리지 않는다 — 과거를 뒤지는 것은 새 글을 받는 일이 아니다.
     *
     * @return array{messages: list<array<string, mixed>>, hasOlder: bool}
     */
    public function older(CommunicationRoom $room, User $user, int $beforeId): array
    {
        $messages = $this->baseQuery($room)
            ->where('id', '<', $beforeId)
            ->orderByDesc('id')
            ->limit(self::FIRST_PAGE)
            ->get()
            ->sortBy('id')
            ->values();

        $hasOlder = $messages->isNotEmpty() && CommunicationMessage::query()
            ->where('communication_room_id', $room->id)
            ->active()
            ->where('id', '<', (int) $messages->min('id'))
            ->exists();

        return [
            'messages' => $this->rows($messages, $room, $user),
            'hasOlder' => $hasOlder,
        ];
    }

    /**
     * 이 글이 보는 사람을 불렀는가. 내가 쓴 글의 "@모두" 는 나를 부른 것이 아니다.
     *
     * @param  array<int, array<string, mixed>>  $mentions
     */
    private function calls(?User $viewer, CommunicationMessage $message, array $mentions, bool $everyone): bool
    {
        if ($viewer === null || (int) $message->sender_user_id === (int) $viewer->id) {
            return false;
        }

        if ($everyone) {
            return true;
        }

        foreach ($mentions as $p) {
            if (isset($p['user_id']) && (int) $p['user_id'] === (int) $viewer->id) {
                return true;
            }
            if (isset($p['employee_id']) && $viewer->employee_id && (int) $p['employee_id'] === (int) $viewer->employee_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * 여러 글을 한 번에 — 방 권한(고정할 수 있나)은 글마다 묻지 않고 한 번만 구한다.
     *
     * @param  \Illuminate\Support\Collection<int, CommunicationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function rows($messages, CommunicationRoom $room, User $user): array
    {
        $canPostHere = $this->communication->canPost($user, $room);

        return $messages->map(fn (CommunicationMessage $m): array => $this->row($m, $room, $user, $canPostHere))->values()->all();
    }

    private function baseQuery(CommunicationRoom $room)
    {
        return CommunicationMessage::query()
            ->with(['senderEmployee', 'senderUser', 'files', 'room', 'reactions.employee:id,name', 'reactions.user:id,name'])
            ->where('communication_room_id', $room->id)
            ->active();
    }

    private function parseCursor(?string $cursor): ?\Illuminate\Support\Carbon
    {
        if (blank($cursor)) {
            return null;
        }

        try {
            // 표의 시각은 앱 시간대로 저장된다 — 같은 시간대로 맞춰 비교한다.
            return \Illuminate\Support\Carbon::parse($cursor)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
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
    private function row(CommunicationMessage $message, CommunicationRoom $room, ?User $viewer = null, ?bool $canPostHere = null): array
    {
        $removed = $message->isRemoved();
        $mentions = $removed ? [] : (array) ($message->payload['mentions'] ?? []);
        $everyone = ! $removed && (bool) ($message->payload['mention_everyone'] ?? false);

        return [
            'id' => (int) $message->id,
            'parentId' => $message->parent_id ? (int) $message->parent_id : null,
            'kind' => (string) $message->kind,
            'title' => $removed ? null : $message->title,
            'body' => $message->visibleBody(),
            'priority' => (string) $message->priority,
            'sender' => $message->senderEmployee?->name
                ?? $message->senderUser?->name
                ?? ($message->kind === CommunicationMessage::KIND_SYSTEM ? '🤖 AI' : 'SMART ERP'),
            // 내 말풍선은 오른쪽 노란색, 남의 것은 왼쪽 흰색 — 카카오톡과 같은 규칙.
            'mine' => $viewer !== null && (int) $message->sender_user_id === (int) $viewer->id,
            'sentAt' => $message->sent_at?->format('H:i'),
            'sentOn' => $message->sent_at?->toDateString(),
            'edited' => $message->edited_at !== null && ! $removed,
            'removed' => $removed,
            'canEdit' => $this->communication->canEdit($viewer, $message),
            'canRemove' => $this->communication->canRemove($viewer, $message),
            // 부른 이름 — 화면이 "@이름" 을 강조한다. 나를 부른 글은 말풍선째 눈에 띄게.
            'mentions' => array_values(array_filter(array_map(fn ($p): string => (string) ($p['name'] ?? ''), $mentions))),
            'mentionEveryone' => $everyone,
            'mentionsMe' => $this->calls($viewer, $message, $mentions, $everyone),
            // ✅ 확인 · 👍 … — 누가 눌렀는지까지. 지운 글에는 반응도 감춘다.
            'reactions' => $removed ? [] : $this->communication->reactionSummary($message, $viewer),
            'pinned' => (bool) $message->is_pinned && ! $removed,
            'pinnedBy' => $message->payload['pinned_by']['name'] ?? null,
            'canPin' => $this->communication->canPin($viewer, $message, $canPostHere),
            'files' => $removed ? [] : $message->files->map(fn (CommunicationMessageFile $f): array => [
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
