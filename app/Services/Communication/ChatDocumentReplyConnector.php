<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use Illuminate\Support\Str;

/**
 * 방에 올린 파일이 어디로 갔는지 그 방에 알려준다.
 *
 * 파일을 던진 사람은 그것이 재무로 갔는지, 장비 대장에 올랐는지, 그냥 보관됐는지
 * 알 길이 없었다. 결과가 보이지 않으면 사람들은 "올려도 아무 일도 안 일어난다"고
 * 여기고 다시 각 화면에 손으로 입력한다 — 자동화가 있어도 쓰이지 않는다.
 *
 * 그래서 분석이 끝나면 파일이 올라온 그 자리(원본 메시지의 답글)에 결과를 붙인다.
 *
 * 지키는 것:
 *  - 멱등: 같은 문서로 두 번 답하지 않는다. 재분석해도 방이 도배되지 않는다.
 *  - 실패해도 조용하다: 답글을 못 달아도 분석·모듈 배달은 이미 끝나 있다.
 *  - 확정하지 않는다: "재무 승인대기로 올렸습니다" 까지다. 승인은 사람이 한다.
 */
class ChatDocumentReplyConnector
{
    /** AI 가 스스로 단 답글임을 나타내는 표시 — 다시 읽히지 않게. */
    public const BOT_MARKER = 'chat_document_reply';

    public function sync(IntelligentDocument $document): void
    {
        $attachment = CommunicationMessageFile::query()
            ->where('intelligent_document_id', $document->id)
            ->orderBy('id')
            ->first();

        if (! $attachment) {
            return; // 채팅에서 올라온 파일이 아니다.
        }

        $parent = CommunicationMessage::query()->find($attachment->communication_message_id);
        if (! $parent) {
            return;
        }

        // 이미 이 문서로 답한 적이 있으면 다시 말하지 않는다.
        $already = CommunicationMessage::query()
            ->where('communication_room_id', $parent->communication_room_id)
            ->where('parent_id', $parent->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->get()
            ->contains(fn (CommunicationMessage $m): bool => is_array($m->payload)
                && ($m->payload['bot'] ?? null) === self::BOT_MARKER
                && (int) ($m->payload['document_id'] ?? 0) === (int) $document->id);

        if ($already) {
            return;
        }

        $reply = CommunicationMessage::query()->create([
            'communication_room_id' => $parent->communication_room_id,
            'company_id' => $parent->company_id,
            'site_id' => $parent->site_id,
            'parent_id' => $parent->id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🤖 문서 AI',
            'body' => mb_substr($this->body($document), 0, 2000),
            'status' => 'active',
            'priority' => 'normal',
            'payload' => ['bot' => self::BOT_MARKER, 'document_id' => $document->id],
        ]);

        // 사람의 확인이 필요한 답글(⚠️·❓)만 폰을 울린다 — 잘 처리된 건까지 울리면
        // 알림이 소음이 되고, 그러면 정작 확인이 필요한 것도 묻힌다.
        try {
            app(\App\Services\Push\ChatPushNotifier::class)->notify($reply);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function body(IntelligentDocument $document): string
    {
        $title = $document->title ?: $document->original_file_name;
        $type = IntelligentDocument::TYPE_OPTIONS[$document->document_type] ?? ($document->document_type ?: '문서');

        $lines = ["읽었습니다 — {$type}: {$title}"];

        foreach ($this->destinations($document) as $destination) {
            $lines[] = '• '.$destination;
        }

        $lines[] = '• 문서함 보관: '.($document->virtual_path ?: '분류 대기');

        // 두 판독이 어긋났으면 그것부터 말한다 — 확인 없이 승인되면 안 되기 때문이다.
        $verification = (array) (($document->ai_payload ?? [])['verification'] ?? []);
        if (($verification['status'] ?? null) === 'disagreed') {
            $fields = implode(', ', array_map(
                fn (string $f): string => self::FIELD_LABELS[$f] ?? $f,
                (array) ($verification['disagreements'] ?? []),
            ));
            $lines[] = "⚠️ 두 AI 판독이 다릅니다({$fields}) — 승인 전에 원본을 확인해 주세요.";
        }

        if ($document->ai_status === 'review_required') {
            $lines[] = '❓ 확신이 낮아 확인이 필요합니다. 문서함에서 내용을 봐 주세요.';
        }

        return implode("\n", $lines);
    }

    private const FIELD_LABELS = [
        'amount' => '금액',
        'flow' => '돈의 방향',
        'paid_on' => '결제일',
        'payee' => '거래처',
        'category_hint' => '비용 분류',
        'equipment_involved' => '장비 여부',
    ];

    /**
     * 이 문서가 실제로 어느 모듈에 닿았는지 — 말이 아니라 만들어진 기록으로 확인한다.
     *
     * @return array<int, string>
     */
    private function destinations(IntelligentDocument $document): array
    {
        $out = [];

        $expense = MobileExpense::query()->where('source_ref', "document:{$document->id}")->first();
        if ($expense) {
            $status = ['pending' => '승인대기', 'approved' => '승인완료', 'paid' => '지급완료', 'rejected' => '반려됨'][$expense->status] ?? $expense->status;
            $out[] = '재무 · '.$expense->accounting_account.' '.number_format((float) $expense->amount, 2).' USD → '.$status;
        }

        $equipment = \App\Models\Equipment::query()->where('equipment_code', "DOC-{$document->id}")->first();
        if ($equipment) {
            $out[] = '장비 대장 · '.trim($equipment->acquisition_type.' '.($equipment->equipment_type ?: ''))
                .($equipment->rent_end ? ' (반납 '.$equipment->rent_end->toDateString().')' : '');
        }

        if ($out === []) {
            $summary = Str::limit((string) $document->summary, 120);
            $out[] = $summary !== '' ? '요약: '.$summary : '연결할 모듈이 없어 문서함에 보관했습니다.';
        }

        return $out;
    }
}
