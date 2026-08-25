<?php

namespace App\Services\Communication;

use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageFile;
use App\Models\MobileExpense;
use App\Models\OpsIntakeItem;

/**
 * 결정이 방(K-TALK)으로 돌아온다.
 *
 * 영수증을 방에 올리면 "재무 승인대기로 올렸습니다"까지는 답글이 갔다. 그런데 그
 * 다음 — 승인됐는지, 반려됐는지, 지급됐는지 — 는 영원히 돌아오지 않았다. 방 답글은
 * 늘 "승인대기"에 멈춰 있었고, 공정 반영·입고 확인도 마찬가지였다(연계 점검:
 * 되돌아오는 길). 올린 사람이 결과를 보려면 재무 화면을 열어야 했고, 그러면
 * "방에 올려도 소용없다"가 된다.
 *
 * 원칙: 멱등(같은 결정으로 두 번 답하지 않음), 실패해도 조용(결정 자체를 막지 않음).
 */
class DecisionReplyConnector
{
    public const BOT_MARKER = 'decision_reply';

    /** 경비 승인/반려/지급 — 그 경비가 태어난 방 메시지에 결과를 붙인다. */
    public function expenseDecided(MobileExpense $expense): void
    {
        try {
            $parent = $this->originOf($expense);
            if ($parent === null) {
                return; // 방에서 태어난 경비가 아니다 — 화면에서 직접 입력한 것.
            }

            $key = ['expense_id' => $expense->id, 'decision' => (string) $expense->status];
            if ($this->alreadyReplied($parent, $key)) {
                return;
            }

            $label = match ((string) $expense->status) {
                'approved' => '✅ 경비 승인',
                'rejected' => '⛔ 경비 반려',
                'paid' => '💸 경비 지급 완료',
                default => null,
            };
            if ($label === null) {
                return;
            }

            $body = $label.' — $'.number_format((float) $expense->amount, 2)
                .($expense->accounting_account ? ' ('.$expense->accounting_account.')' : '');
            if ($expense->status === 'rejected' && filled($expense->rejection_reason)) {
                $body .= "\n사유: ".$expense->rejection_reason;
            }

            $this->reply($parent, $body, $key);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 상황실 제안 반영(공정·조달) — 보고가 올라온 그 메시지에 결과를 붙인다. */
    public function intakeApplied(OpsIntakeItem $item, string $note): void
    {
        try {
            if (! $item->communication_message_id) {
                return;
            }
            $parent = CommunicationMessage::query()->find($item->communication_message_id);
            if ($parent === null) {
                return;
            }

            $key = ['intake_id' => $item->id, 'decision' => 'applied'];
            if ($this->alreadyReplied($parent, $key)) {
                return;
            }

            $this->reply($parent, '✅ '.$note, $key);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 이 경비가 태어난 방 메시지 — 문서함 경유(파일 첨부)와 상황실 경유 두 길 모두. */
    private function originOf(MobileExpense $expense): ?CommunicationMessage
    {
        $ref = (string) $expense->source_ref;

        if (str_starts_with($ref, 'document:')) {
            $documentId = (int) substr($ref, strlen('document:'));
            $attachment = CommunicationMessageFile::query()
                ->where('intelligent_document_id', $documentId)
                ->orderBy('id')
                ->first();

            return $attachment
                ? CommunicationMessage::query()->find($attachment->communication_message_id)
                : null;
        }

        if (str_starts_with($ref, 'ops:')) {
            $item = OpsIntakeItem::query()->find((int) substr($ref, strlen('ops:')));

            return $item?->communication_message_id
                ? CommunicationMessage::query()->find($item->communication_message_id)
                : null;
        }

        return null;
    }

    /** @param array<string, mixed> $key */
    private function alreadyReplied(CommunicationMessage $parent, array $key): bool
    {
        return CommunicationMessage::query()
            ->where('communication_room_id', $parent->communication_room_id)
            ->where('parent_id', $parent->id)
            ->where('kind', CommunicationMessage::KIND_SYSTEM)
            ->get()
            ->contains(function (CommunicationMessage $m) use ($key): bool {
                $p = (array) $m->payload;
                if (($p['bot'] ?? null) !== self::BOT_MARKER) {
                    return false;
                }
                foreach ($key as $k => $v) {
                    if (($p[$k] ?? null) != $v) {
                        return false;
                    }
                }

                return true;
            });
    }

    /** @param array<string, mixed> $key */
    private function reply(CommunicationMessage $parent, string $body, array $key): void
    {
        $reply = CommunicationMessage::query()->create([
            'communication_room_id' => $parent->communication_room_id,
            'company_id' => $parent->company_id,
            'site_id' => $parent->site_id,
            'parent_id' => $parent->id,
            'sender_user_id' => null,
            'sender_employee_id' => null,
            'kind' => CommunicationMessage::KIND_SYSTEM,
            'title' => '🤖 처리 결과',
            'body' => mb_substr($body, 0, 2000),
            'status' => 'active',
            'priority' => 'normal',
            'payload' => ['bot' => self::BOT_MARKER] + $key,
        ]);

        try {
            app(\App\Services\Push\ChatPushNotifier::class)->notify($reply);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
