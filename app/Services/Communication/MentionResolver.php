<?php

namespace App\Services\Communication;

use App\Models\CommunicationRoom;
use App\Models\CommunicationRoomMember;
use App\Models\User;

/**
 * 글이 누구를 불렀는가 — "@이름" 을 방 사람으로 바꾸는 유일한 곳.
 *
 * 화면이 고른 사람 번호를 따로 보내게 하지 않고 <b>글자 자체</b>에서 읽는다.
 * 부르는 길이 둘(목록에서 고르기 / 손으로 치기)이면 한쪽만 알림이 가는 일이 생긴다.
 * 목록은 "@이름 " 을 대신 써 줄 뿐이고, 판단은 늘 여기서 한다.
 *
 * 이름은 긴 것부터 맞춘다. "@김철수" 안에 "@김철" 이 들어 있어도 김철은 부르지 않는다 —
 * 맞춘 부분은 지우고 다음 이름을 찾기 때문이다. 조사("@김철수님")가 붙어도 부른 것으로
 * 친다. 현장에서 띄어쓰기를 정확히 하라고 하면 그 기능은 안 쓰인다.
 */
class MentionResolver
{
    /**
     * 방 전체를 부르는 말. 영어는 뒤에 알파벳이 이어지면 이름으로 본다("@allen" ≠ "@all").
     * 화면도 이 목록으로 강조한다 — 두 벌이면 강조와 알림이 어긋난다.
     */
    public const EVERYONE = ['모두', '전체', 'all', 'everyone', 'channel', 'todos'];

    /**
     * @return array{people: list<array{employee_id: int|null, user_id: int|null, name: string}>, everyone: bool}
     */
    public function resolve(CommunicationRoom $room, string $body, ?User $author, bool $mayCallEveryone): array
    {
        $none = ['people' => [], 'everyone' => false];

        if (! str_contains($body, '@')) {
            return $none;
        }

        $text = mb_strtolower($body);
        $everyone = $mayCallEveryone && $this->callsEveryone($text);

        $people = [];
        $seen = [];

        foreach ($this->candidates($room, $author) as $candidate) {
            $needle = '@'.mb_strtolower($candidate['name']);
            if (! str_contains($text, $needle)) {
                continue;
            }

            // 맞춘 자리는 지운다 — 더 짧은 이름이 그 안에서 또 맞지 않게.
            $text = str_replace($needle, ' ', $text);

            $key = ($candidate['employee_id'] ?? 'u').':'.($candidate['user_id'] ?? 'e');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $people[] = $candidate;
        }

        return ['people' => $people, 'everyone' => $everyone];
    }

    private function callsEveryone(string $lowerText): bool
    {
        foreach (self::EVERYONE as $word) {
            if (preg_match('/@'.preg_quote($word, '/').'(?![a-z0-9])/u', $lowerText) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 부를 수 있는 사람 — 이 방의 사람 중 글쓴이를 뺀 모두, 이름 긴 순.
     *
     * @return list<array{employee_id: int|null, user_id: int|null, name: string}>
     */
    private function candidates(CommunicationRoom $room, ?User $author): array
    {
        return $room->activeMembers()
            ->with(['employee:id,name', 'user:id,name,employee_id'])
            ->get()
            ->reject(fn (CommunicationRoomMember $m): bool => $author !== null && $this->isAuthor($m, $author))
            ->map(fn (CommunicationRoomMember $m): array => [
                'employee_id' => $m->employee_id ? (int) $m->employee_id : null,
                'user_id' => $m->user_id ? (int) $m->user_id : null,
                'name' => trim((string) preg_replace('/\s+/u', ' ', (string) ($m->employee?->name ?? $m->user?->name ?? ''))),
            ])
            // "@AI" 는 AI 도우미를 부르는 말이다 — 사람 이름으로 잡으면 둘 다 울린다.
            ->filter(fn (array $c): bool => $c['name'] !== '' && mb_strtolower($c['name']) !== 'ai')
            ->sortByDesc(fn (array $c): int => mb_strlen($c['name']))
            ->values()
            ->all();
    }

    private function isAuthor(CommunicationRoomMember $member, User $author): bool
    {
        if ($member->user_id !== null && (int) $member->user_id === (int) $author->id) {
            return true;
        }

        return $member->employee_id !== null && $author->employee_id !== null
            && (int) $member->employee_id === (int) $author->employee_id;
    }
}
