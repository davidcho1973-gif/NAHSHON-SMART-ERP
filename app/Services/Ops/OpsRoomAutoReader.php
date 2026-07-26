<?php

namespace App\Services\Ops;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 현장 상황실 자동 판독 — 방에 글이 올라오면 AI 가 읽고 반영 제안을 만든 뒤 답글을 단다.
 *
 * 사람이 버튼을 누를 필요가 없다. 다만 공정표 실제 반영은 여전히 확인 후 이뤄진다
 * (잘못 읽은 내용이 조용히 공정표를 흔들면 추적조차 안 되기 때문).
 */
class OpsRoomAutoReader
{
    public function __construct(private readonly OpsIntakeService $intake)
    {
    }

    /** AI 가 스스로 단 답글을 다시 판독하지 않도록 표시하는 값. */
    public const BOT_MARKER = 'ops_ai_reply';

    public function handle(CommunicationMessage $message): void
    {
        if (! $this->shouldRead($message)) {
            return;
        }

        try {
            $room = $message->room;
            $site = $room?->site_id ? Site::find($room->site_id) : null;

            $result = $this->intake->ingest(
                (string) $message->body,
                $site,
                $message->sender_user_id,
                [],
                'room',
                $message->id,
            );

            if (! ($result['success'] ?? false)) {
                return; // AI 실패는 조용히 넘긴다 — 대화 자체를 방해하면 안 된다.
            }

            $this->reply($message, $result);
        } catch (Throwable $e) {
            Log::warning('상황실 자동 판독 실패: ' . $e->getMessage(), ['message_id' => $message->id]);
        }
    }

    private function shouldRead(CommunicationMessage $message): bool
    {
        if (blank($message->body)) {
            return false;
        }
        // AI 답글은 다시 읽지 않는다(무한 루프 방지).
        if (is_array($message->payload) && ($message->payload['bot'] ?? null) === self::BOT_MARKER) {
            return false;
        }
        if ($message->kind === CommunicationMessage::KIND_SYSTEM) {
            return false;
        }

        $room = $message->room;

        return $room !== null && $room->type === CommunicationRoom::TYPE_SITE_OPS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reply(CommunicationMessage $message, array $result): void
    {
        $items = collect($result['items'] ?? [])->where('category', '!=', 'noise');
        if ($items->isEmpty()) {
            return; // 잡담뿐이면 답글로 방을 어지럽히지 않는다.
        }

        $lines = $items->take(5)->map(function (array $i): string {
            $head = $i['status'] === 'needs_input' ? '❓' : '•';
            $target = $i['targetName'] ? ' [' . $i['targetName'] . ']' : '';

            return $head . ' ' . ($i['summary'] ?: '(내용 없음)') . $target;
        })->implode("\n");

        $needs = $items->where('status', 'needs_input')->count();
        $foot = $needs > 0
            ? "\n\n❓ {$needs}건은 확인이 필요합니다. 현장 상황실 화면에서 확인해 주세요."
            : "\n\n확인 후 [공정표에 반영]을 누르면 적용됩니다.";

        CommunicationMessage::query()->create([
            'communication_room_id' => $message->communication_room_id,
            'parent_id' => $message->id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🤖 상황실 AI',
            'body' => mb_substr("읽었습니다 — {$items->count()}건 인식했습니다.\n{$lines}{$foot}", 0, 2000),
            'status' => 'active',
            'priority' => 'normal',
            'payload' => ['bot' => self::BOT_MARKER, 'intake_ids' => $items->pluck('id')->all()],
        ]);
    }
}
