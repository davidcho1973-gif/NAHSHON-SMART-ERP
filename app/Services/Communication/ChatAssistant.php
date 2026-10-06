<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Push\ChatPushNotifier;
use App\Services\Wbs\CpmEngine;
use App\Support\AiAssistantBudget;
use App\Support\AiInformationAccess;
use App\Support\AnthropicChat;
use App\Support\Org;
use DomainException;
use Throwable;

/**
 * 대화방의 AI 도우미 — 사람이 <b>먼저 부를 때만</b> 답한다.
 *
 * 방의 모든 말을 AI 가 읽고 스스로 낄지 판단하게 만들 수도 있었다. 그렇게 하면
 * 요금이 대화량에 그대로 비례하고, 무엇보다 끼어들기가 잦으면 사람들이 그 방을
 * 떠난다. 대화방은 사람의 것이고 AI 는 부를 때 오는 쪽이 낫다.
 *
 * 그래서 부르는 방법을 하나로 정했다: <b>@AI</b>. 입력창의 [AI] 버튼이 이 글자를
 * 넣어 준다 — 규칙을 외우게 하지 않기 위해서다.
 *
 * ── 지키는 것 ──────────────────────────────────────────────────────────
 *
 *  1. <b>멱등</b>: 같은 질문에 두 번 답하지 않는다(payload 의 question_id 로 확인).
 *  2. <b>권한을 넘지 않는다</b>: 조회는 전부 ChatFactFinder 를 거치고, 그 안은
 *     화면과 같은 AccessPolicy 다. 못 보는 것은 못 본다고 말한다 — 조용히 빼면
 *     "그런 자료 없습니다" 라는 거짓말이 된다.
 *  3. <b>자기 말을 다시 읽지 않는다</b>: 답글에 @AI 가 들어가도 되묻지 않는다.
 *  4. <b>조용히 실패한다</b>: 키가 없거나 호출이 실패해도 대화는 그대로 흐른다.
 */
class ChatAssistant
{
    /** AI 가 스스로 단 답글임을 나타내는 표시 — 다시 읽히지 않게. */
    public const BOT_MARKER = 'chat_ask';

    /** 방에서 AI 를 부르는 이름. 화면의 [AI] 버튼이 넣는 글자와 같아야 한다. */
    public const HANDLE = '@AI';

    /** 참여자 목록에 보이는 이름. */
    public const DISPLAY_NAME = '🤖 AI 도우미';

    private const PRIVATE_ASK = '개인 자료와 대화방 참여자의 열람 범위를 보호하기 위해 이 질문은 개인 물어보기에서 확인해 주세요: /attendance-app/ask';

    // A shared reply must never republish a personal document, roster, or old bot answer.
    private const SHARED_TOPICS = ['wbs', 'procurement', 'equipment', 'boq', 'submittals', 'inspection'];

    private const DELAY_PATTERN = '/(.+?)\s*(?:이|가|은|는)?\s*(하루|이틀|사흘|나흘|\d+\s*일)\s*(?:정도\s*)?(밀리|늦|지연)/u';

    private const SHARED_FACTS = ['현장', '공정', '조달·발주', '장비', '물량/BOQ', '제출물 대장', '검사·검측'];

    public function __construct(
        private readonly AnthropicChat $claude,
        private readonly ChatFactFinder $facts,
        private readonly AiAssistantBudget $budget,
    ) {}

    /** 이 배포에 AI 도우미가 살아 있는가(열쇠가 있는가). */
    public function available(): bool
    {
        return $this->budget->enabled() && $this->claude->available();
    }

    /**
     * 이 글이 AI 를 부른 것인가.
     *
     * "@AI", "@ai", "@에이아이" 를 모두 받는다 — 현장에서 대문자를 정확히 치라고
     * 요구하면 그 기능은 안 쓰이는 기능이 된다.
     */
    public function mentioned(?string $body): bool
    {
        if (blank($body)) {
            return false;
        }

        return (bool) preg_match('/@\s*(ai|에이아이)\b/iu', (string) $body);
    }

    /** 부르는 말을 떼어 낸 질문 본문. */
    public function questionOf(string $body): string
    {
        return trim((string) preg_replace('/@\s*(ai|에이아이)\b/iu', '', $body));
    }

    /** 이 글에 답해야 하는가 — 부름을 받았고, 사람이 쓴 글이고, 아직 안 답했을 때. */
    public function shouldAnswer(CommunicationMessage $message): bool
    {
        if (! $this->available() || $message->isRemoved()) {
            return false;
        }
        if (is_array($message->payload) && filled($message->payload['bot'] ?? null)) {
            return false;   // AI 자신의 말 — 되묻지 않는다.
        }
        if ($message->kind === CommunicationMessage::KIND_SYSTEM) {
            return false;
        }
        if (! $this->mentioned($message->body)) {
            return false;
        }

        return ! $this->alreadyAnswered($message);
    }

    /**
     * 질문에 답하고 그 자리에 답글을 단다.
     *
     * 답을 못 만들면 null 을 돌려주고 방에는 아무것도 남기지 않는다 — 빈 말풍선이
     * 붙는 것보다 아무 일도 안 일어난 편이 낫다. 다만 열쇠가 없거나 권한 문제로
     * 답을 못 하는 것은 <b>사람에게 알려야</b> 하므로 그때는 말을 남긴다.
     */
    public function answer(CommunicationMessage $message): ?CommunicationMessage
    {
        if (! $this->shouldAnswer($message)) {
            return null;
        }

        $room = CommunicationRoom::find($message->communication_room_id);
        $asker = User::with('employee')->find($message->sender_user_id);

        if (! $room || ! $asker || $asker->account_status !== 'active'
            || ! app(CommunicationService::class)->canAccessRoom($asker, $room)) {
            return null;
        }

        $question = $this->questionOf((string) $message->body);

        // Room membership is not financial clearance. Never publish a privileged
        // answer to a shared stream (including its push notifications).
        if (AiInformationAccess::financial($question)) {
            return $this->reply($message, '금전·급여 관련 질문은 개인 물어보기 화면에서 확인해 주세요: /attendance-app/ask');
        }

        if ($question === '') {
            return $this->reply($message, '무엇을 도와드릴까요? '.self::HANDLE.' 뒤에 질문을 적어 주세요.');
        }

        $topics = $this->facts->topicsIn($question);
        if (($topics === [] && preg_match(self::DELAY_PATTERN, $question) !== 1)
            || array_diff($topics, self::SHARED_TOPICS) !== []) {
            return $this->reply($message, self::PRIVATE_ASK);
        }

        try {
            $audience = $this->sharedAudience($room, $asker);
            if ($audience === null) {
                return $this->reply($message, self::PRIVATE_ASK);
            }
            $gathered = $this->facts->gather($question, $room, $asker, localOnly: true, sharedOnly: true);
            $gathered['facts'] = AiInformationAccess::technicalFacts(array_intersect_key(
                $gathered['facts'], array_flip(self::SHARED_FACTS),
            ));

            // What-if — "A작업 3일 밀리면 뭐가 밀려?" 는 CPM 엔진이 계산한 사실로 답한다.
            // A question only simulates. Writes require the explicit preview/confirm flow.
            $whatIf = $gathered['site'] ? $this->tryWhatIf($question, $room) : null;
            if ($whatIf !== null) {
                $gathered['facts']['지연 시뮬레이션(CPM 엔진 계산)'] = $whatIf;
            }

            $text = $this->ask($question, $room, $asker, $gathered);
            if ($whatIf !== null && ! blank($text)) {
                $text .= "\n\n📌 시뮬레이션 결과입니다. 실제 공정표는 변경되지 않았습니다.";
            }
        } catch (DomainException $e) {
            return $this->reply($message, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->reply($message, '지금은 답을 만들지 못했습니다. 잠시 뒤에 다시 불러 주세요.');
        }

        // The audience and sender can change while the provider is answering. Never
        // publish a previously authorized answer into a changed room or push audience.
        $currentRoom = $room->fresh();
        $currentAsker = $asker->fresh(['employee']);
        $currentMessage = $message->fresh();
        if (! $currentRoom || ! $currentAsker || ! $currentMessage || $currentMessage->isRemoved()
            || $currentMessage->body !== $message->body
            || $currentMessage->sender_user_id !== $message->sender_user_id
            || $currentMessage->communication_room_id !== $message->communication_room_id
            || $this->alreadyAnswered($currentMessage)
            || ! app(CommunicationService::class)->canAccessRoom($currentAsker, $currentRoom)) {
            return null;
        }
        if ($currentAsker->account_status !== 'active'
            || AiInformationAccess::context($currentAsker) !== AiInformationAccess::context($asker)
            || $this->sharedAudience($currentRoom, $currentAsker) !== $audience) {
            return $this->reply($message, self::PRIVATE_ASK);
        }

        if (AiInformationAccess::financial((string) $text)) {
            $text = '금전 정보가 포함된 답변은 개인 물어보기 화면에서 확인해 주세요: /attendance-app/ask';
        }

        return blank($text) ? null : $this->reply($message, $text);
    }

    /**
     * "…이 N일 밀리면/늦으면" 질문이면 읽기 전용 CPM 시뮬레이션을 돌린다.
     *
     * 답은 AI 가 말로 풀지만 숫자는 전부 엔진이 계산한 사실이다 — 지어낸 날짜가 아니다.
     *
     * @return array<string, mixed>|null
     */
    private function tryWhatIf(string $question, CommunicationRoom $room): ?array
    {
        if ($room->site_id === null) {
            return null;
        }
        if (! preg_match(self::DELAY_PATTERN, $question, $m)) {
            return null;
        }

        $days = match (trim($m[2])) {
            '하루' => 1, '이틀' => 2, '사흘' => 3, '나흘' => 4,
            default => (int) $m[2],
        };
        $target = trim((string) preg_replace('/^(만약|혹시|근데|그런데|그럼)\s+/u', '', trim($m[1])));
        $target = trim((string) preg_replace('/(작업|공정)$/u', '', $target));
        if ($target === '' || $days <= 0) {
            return null;
        }

        $codes = WbsItem::query()
            ->where('site_id', $room->site_id)
            ->whereNotNull('project_code')
            ->distinct()->pluck('project_code');

        foreach ($codes as $code) {
            // The CPM engine resolves a project globally. Ambiguous cross-site codes
            // must not pull another project's tasks into a shared-room simulation.
            if (WbsItem::query()->where('project_code', $code)->where(function ($query) use ($room): void {
                $query->whereNull('site_id')->orWhere('site_id', '!=', $room->site_id)
                    ->orWhere(fn ($q) => $q->whereNotNull('company_id')->where('company_id', '!=', $room->company_id));
            })->exists()) {
                continue;
            }
            $sim = app(CpmEngine::class)->simulate((string) $code, $target, $days);
            if (! ($sim['success'] ?? false)) {
                continue;
            }

            return $sim;
        }

        return null;
    }

    // ── Claude 에게 묻기 ───────────────────────────────────────────────

    private function ask(string $question, CommunicationRoom $room, User $asker, array $gathered): string
    {
        $payload = [
            'max_tokens' => 1200,
            'system' => $this->rules($room, $asker, $gathered['denied']),
            'messages' => [[
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    '[조회한 사실]',
                    $gathered['facts'] === []
                        ? '(이 질문으로는 조회한 자료가 없습니다)'
                        : json_encode($gathered['facts'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    '[질문]',
                    $question,
                ])),
            ]],
        ];

        return $this->budget->run($asker, $room->company_id, 'chat_ask', $payload,
            fn (array $bounded): string => trim($this->claude->textOf($this->claude->raw($bounded))));
    }

    /**
     * AI 에게 주는 규칙.
     *
     * "모르면 모른다고 하라" 를 맨 앞에 두는 이유 — 현장에서 그럴듯한 오답은
     * 답이 없는 것보다 나쁘다. 그 숫자로 자재를 주문하고 사람을 부른다.
     */
    private function rules(CommunicationRoom $room, User $asker, array $denied): string
    {
        $lines = [
            'You are the AI assistant inside a construction field messenger for '.Org::name().'.',
            '',
            '가장 중요한 규칙: [조회한 사실] 에 없는 숫자·날짜·이름을 지어내지 마세요.',
            '자료가 없으면 "그 자료는 확인되지 않습니다" 라고 분명히 말하고, 어디서',
            '확인하면 되는지 한 줄로 알려 주세요. 그럴듯한 오답은 답이 없는 것보다 나쁩니다 —',
            '현장은 그 숫자로 자재를 주문하고 사람을 부릅니다.',
            '',
            '답하는 방식:',
            '- 한국어로, 현장에서 폰으로 읽는 글입니다. 3~5줄 안쪽으로 짧게.',
            '- 숫자는 근거와 함께("공정표 기준 62%").',
            '- 표나 마크다운 문법은 쓰지 마세요. 줄바꿈과 · 만 씁니다.',
            '- 지시나 승인을 대신하지 마세요. 판단이 필요한 일은 누가 결정해야 하는지만 짚어 줍니다.',
        ];

        if ($denied !== []) {
            $lines[] = '';
            $lines[] = '이 사람의 권한으로는 볼 수 없어 조회하지 못한 것:';
            foreach ($denied as $item) {
                $lines[] = '- '.$item;
            }
            $lines[] = '질문이 이것과 관련되면, 없는 척하지 말고 "권한이 없어 알려드릴 수 없습니다" 라고';
            $lines[] = '분명히 말한 뒤 누구에게 물어야 하는지 알려 주세요.';
        }

        $lines[] = '';
        $lines[] = '방: '.($room->name ?: '이름 없는 방').' ('.($room->type).')';
        $lines[] = '오늘: '.now()->toDateString();

        return implode("\n", $lines);
    }

    /**
     * A room's readers include scope-authorized users, not just its member list.
     * Unknown employee-only identities and overly large/unverifiable audiences fail
     * closed. Documents, knowledge cards and conversation history are excluded even
     * for a verified audience, so later members cannot inherit someone's private data.
     */
    private function sharedAudience(CommunicationRoom $room, User $asker): ?string
    {
        $site = $room->site;
        if ($room->status !== 'active' || ! $room->company_id || ! $site
            || (int) $site->company_id !== (int) $room->company_id || $site->status !== 'active') {
            return null;
        }

        $users = User::query()->where('account_status', 'active')->with('employee')->orderBy('id')->limit(1001)->get();
        if ($users->count() > 1000) {
            return null;
        }
        $members = $room->activeMembers()->orderBy('id')->get(['id', 'user_id', 'employee_id', 'role']);
        foreach ($members as $member) {
            $identities = $users->filter(fn (User $user): bool => ($member->user_id && (int) $user->id === (int) $member->user_id)
                || ($member->employee_id && (int) $user->employee_id === (int) $member->employee_id));
            if ($identities->count() !== 1) {
                return null;
            }
            $identity = $identities->first();
            if (($member->user_id && (int) $identity->id !== (int) $member->user_id)
                || ($member->employee_id && (int) $identity->employee_id !== (int) $member->employee_id)) {
                return null;
            }
        }

        $service = app(CommunicationService::class);
        $readers = $users->filter(fn (User $user): bool => $service->canAccessRoom($user, $room));
        if (! $readers->contains('id', $asker->id)) {
            return null;
        }
        foreach ($readers as $reader) {
            if (! AiInformationAccess::canUseSite($reader, $site)
                || $this->budget->companyId($reader, $room->company_id) !== (int) $room->company_id) {
                return null;
            }
        }

        return hash('sha256', json_encode([
            $room->only(['id', 'type', 'company_id', 'site_id', 'team_id', 'status']),
            $site->only(['id', 'company_id', 'status']),
            $members->toArray(),
            $readers->map(fn (User $user): array => [$user->id, AiInformationAccess::context($user)])->values()->all(),
        ]));
    }

    // ── 방에 답글 남기기 ───────────────────────────────────────────────

    private function reply(CommunicationMessage $question, string $body): CommunicationMessage
    {
        $reply = CommunicationMessage::query()->create([
            'communication_room_id' => $question->communication_room_id,
            'company_id' => $question->company_id,
            'site_id' => $question->site_id,
            // 답글은 원글에만 달린다 — 질문이 이미 답글이면 그 원글에 붙인다.
            'parent_id' => $question->parent_id ?: $question->id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => self::DISPLAY_NAME,
            'body' => mb_substr($body, 0, 2000),
            'status' => 'active',
            'priority' => 'normal',
            'payload' => ['bot' => self::BOT_MARKER, 'question_id' => (int) $question->id],
        ]);

        // 물어본 사람은 답이 온 것을 알아야 한다 — 화면을 닫고 있어도.
        try {
            app(ChatPushNotifier::class)->notify($reply);
        } catch (Throwable $e) {
            report($e);
        }

        return $reply;
    }

    /** 이 질문에 이미 답했는가. 재시도·중복 발행에도 방이 도배되지 않게. */
    private function alreadyAnswered(CommunicationMessage $question): bool
    {
        return CommunicationMessage::query()
            ->where('communication_room_id', $question->communication_room_id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->where('id', '>', $question->id)
            ->get()
            ->contains(fn (CommunicationMessage $m): bool => is_array($m->payload)
                && ($m->payload['bot'] ?? null) === self::BOT_MARKER
                && (int) ($m->payload['question_id'] ?? 0) === (int) $question->id);
    }
}
