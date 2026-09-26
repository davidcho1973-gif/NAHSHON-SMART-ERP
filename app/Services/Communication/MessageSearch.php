<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * 대화 검색 — "두 달 전에 누가 슬리브 얘기 했더라".
 *
 * 볼 수 있는 방의 글만 찾는다. 방 목록과 <b>같은 규칙</b>(CommunicationService::roomQueryForUser)
 * 을 쓴다 — 검색이 따로 권한을 판단하면 언젠가 목록에서는 안 보이는 방의 글이 검색에는
 * 나온다. 남의 1:1 대화는 관리자라도 나오지 않는다.
 *
 * 지운 글은 찾지 않는다. 기록으로는 남아 있지만, 쓴 사람이 거둔 말을 검색이 되살리면 안 된다.
 *
 * 띄어 쓴 낱말은 모두 들어 있어야 한다("3층 슬리브" → 둘 다 있는 글). 한국어는 조사가
 * 붙으므로 낱말 경계가 아니라 글자 조각으로 찾는다(ILIKE — 문서함 검색과 같은 방식).
 */
class MessageSearch
{
    public const MIN_LENGTH = 2;

    private const LIMIT = 50;

    public function __construct(private readonly CommunicationService $communication) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(User $user, string $query, ?CommunicationRoom $room = null): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $roomIds = $this->communication->roomQueryForUser($user)
            ->when($room !== null, fn (Builder $q) => $q->whereKey($room->id))
            ->pluck('id');

        if ($roomIds->isEmpty()) {
            return [];
        }

        $messages = CommunicationMessage::query()
            ->with(['room', 'senderEmployee', 'senderUser', 'files'])
            ->whereIn('communication_room_id', $roomIds->all())
            ->active()
            ->whereNull('removed_at')
            ->where(function (Builder $q) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.$this->escapeLike($term).'%';
                    // 글에 없어도 첨부 파일 이름에 있으면 찾는다 — "견적서.pdf" 는 파일 이름이 곧 내용이다.
                    $q->where(fn (Builder $one) => $one
                        ->where('body', 'ilike', $like)
                        ->orWhere('title', 'ilike', $like)
                        ->orWhereHas('files', fn (Builder $f) => $f->where('original_name', 'ilike', $like)));
                }
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $messages->map(fn (CommunicationMessage $m): array => [
            'id' => (int) $m->id,
            'roomId' => (int) $m->communication_room_id,
            'roomName' => $this->roomLabel($m->room, $user),
            'sender' => $m->senderEmployee?->name ?? $m->senderUser?->name
                ?? ($m->kind === CommunicationMessage::KIND_SYSTEM ? '🤖 AI' : 'SMART ERP'),
            'snippet' => $this->snippet((string) $m->body, $terms[0], $m->files->pluck('original_name')->all()),
            'sentAt' => $m->sent_at?->format('Y-m-d H:i'),
        ])->all();
    }

    /**
     * 찾을 낱말들. 한 글자 검색("관")은 거의 모든 글에 걸려 쓸모가 없으므로 전체가 두 글자는 돼야 한다.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/\s+/u', trim($query)) ?: [],
            fn (string $w): bool => $w !== '',
        )));

        return mb_strlen(implode('', $words)) >= self::MIN_LENGTH ? $words : [];
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * 찾은 말 앞뒤로 조금 — 긴 보고 한가운데 있는 한 줄이 목록에서 보여야 한다.
     *
     * @param  list<string>  $fileNames
     */
    private function snippet(string $body, string $term, array $fileNames): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($flat === '') {
            return $fileNames !== [] ? '📎 '.implode(', ', $fileNames) : '';
        }

        $at = mb_stripos($flat, $term);
        if ($at === false || mb_strlen($flat) <= 90) {
            return Str::limit($flat, 90);
        }

        $start = max(0, $at - 30);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($flat, $start), 90);
    }

    private function roomLabel(?CommunicationRoom $room, User $user): string
    {
        if (! $room) {
            return '';
        }

        if ($room->type === CommunicationRoom::TYPE_DIRECT) {
            return $this->communication->directCounterpart($room, $user)?->name ?? '1:1';
        }

        return (string) $room->name;
    }
}
