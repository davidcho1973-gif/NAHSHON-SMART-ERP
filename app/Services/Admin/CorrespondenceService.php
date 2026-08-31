<?php

namespace App\Services\Admin;

use App\Models\MailMessage;
use App\Models\MailThread;
use App\Support\AccessPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * 서신 원장 — 우리가 무엇을 언제 누구에게 보냈는가.
 *
 * 이 화면의 값은 평소가 아니라 <b>다툼이 났을 때</b> 나온다. "8월 30일에 통보했습니다" 를
 * 말이 아니라 기록으로 대는 자리다. 그래서 성공만 보여주지 않는다 — 실패도, 메일 서버가
 * 없어 사람 메일앱으로 넘긴 것도 그대로 센다.
 *
 * <b>내부 전용이다.</b> 원청 계정(client·viewer)에게는 열지 않는다 — 우리가 다른 업체에
 * 보낸 서신까지 한 목록에 있기 때문이다. 원청이 자기 앞으로 온 것만 보는 창구는
 * 2단계(수신)에서 별도로 만든다.
 */
class CorrespondenceService
{
    /** 현장 일을 보는 사람이면 자기 현장 서신을 본다. 외부 열람 계정은 제외된다. */
    public const VIEW_ROLES = AccessPolicy::SITE_ROLES;

    /**
     * 실타래 목록.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function threads(?int $siteId, array $filters = []): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '서신 원장을 볼 권한이 없습니다.'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        $rows = MailThread::query()
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId))
            ->when($status !== '', fn ($b) => $b->where('status', $status))
            ->when($q !== '', function ($b) use ($q): void {
                $like = '%'.$q.'%';
                $b->where(function ($w) use ($like): void {
                    $w->where('subject', 'ilike', $like)
                        ->orWhere('ref_code', 'ilike', $like)
                        ->orWhere('counterparty_name', 'ilike', $like)
                        ->orWhere('counterparty_email', 'ilike', $like)
                        ->orWhere('counterparty_org', 'ilike', $like);
                });
            })
            ->with('site:id,name')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(120)
            ->get();

        // 목록에 마지막 봉투의 결과를 함께 보여준다 — 실패한 서신이 목록에서
        // 성공한 것과 똑같아 보이면 아무도 못 찾는다.
        $last = MailMessage::query()
            ->whereIn('mail_thread_id', $rows->pluck('id'))
            ->orderByDesc('occurred_at')->get()->keyBy('mail_thread_id');

        return [
            'success' => true,
            'stats' => $this->stats($siteId),
            'rows' => $rows->map(function (MailThread $t) use ($last): array {
                $m = $last->get($t->id);

                return [
                    'id' => $t->id,
                    'refCode' => $t->ref_code,
                    'subject' => $t->subject,
                    'related' => $t->relatedLabel(),
                    'counterparty' => $t->counterparty_name ?: $t->counterparty_email,
                    'counterpartyEmail' => $t->counterparty_email,
                    'org' => $t->counterparty_org,
                    'site' => $t->site?->name,
                    'status' => $t->status,
                    'count' => (int) $t->message_count,
                    'lastAt' => $t->last_message_at?->format('Y-m-d H:i'),
                    'lastStatus' => $m?->status,
                    'lastStatusLabel' => $m?->statusLabel(),
                ];
            })->all(),
        ];
    }

    /**
     * 실타래 하나 — 오간 봉투 전부.
     *
     * @return array<string, mixed>
     */
    public function thread(int $id): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '서신 원장을 볼 권한이 없습니다.'];
        }

        $t = MailThread::with(['site:id,name', 'createdBy:id,name'])->find($id);
        if (! $t) {
            return ['success' => false, 'error' => '서신을 찾을 수 없습니다.'];
        }

        return [
            'success' => true,
            'thread' => [
                'id' => $t->id,
                'refCode' => $t->ref_code,
                'subject' => $t->subject,
                'related' => $t->relatedLabel(),
                'relatedId' => $t->related_id,
                'counterparty' => $t->counterparty_name,
                'counterpartyEmail' => $t->counterparty_email,
                'org' => $t->counterparty_org,
                'site' => $t->site?->name,
                'status' => $t->status,
                'count' => (int) $t->message_count,
                'openedBy' => $t->createdBy?->name,
                'openedAt' => $t->created_at?->format('Y-m-d H:i'),
                'firstSentAt' => $t->first_sent_at?->format('Y-m-d H:i'),
                // 2단계에서 쓸 열쇠. 화면에는 있다는 사실만 — 값 전체를 뿌리면
                // 화면을 캡처해 공유하는 순간 아무나 그 실타래에 글을 넣을 수 있다.
                'replyReady' => $t->acceptsReply(),
            ],
            'messages' => $t->messages()->with('documents:id,title,original_file_name')
                ->orderByDesc('occurred_at')->get()
                ->map(fn (MailMessage $m): array => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'channel' => $m->channel,
                    'status' => $m->status,
                    'statusLabel' => $m->statusLabel(),
                    'subject' => $m->subject,
                    'to' => $m->recipientLine(),
                    'from' => $m->from_address,
                    'at' => $m->occurred_at?->format('Y-m-d H:i'),
                    'by' => $m->createdBy?->name,
                    'snippet' => $m->snippet,
                    'html' => $m->body_html,
                    'error' => $m->error,
                    'messageId' => $m->rfc_message_id,
                    'attachments' => $m->documents->map(fn ($d): array => [
                        'id' => $d->id,
                        'name' => $d->title ?: $d->original_file_name,
                    ])->all(),
                ])->all(),
        ];
    }

    /** @return array<string, int> */
    private function stats(?int $siteId): array
    {
        $base = fn () => MailMessage::query()->when($siteId, fn ($b) => $b->where('site_id', $siteId));

        return [
            'threads' => MailThread::query()->when($siteId, fn ($b) => $b->where('site_id', $siteId))->count(),
            'sent' => (clone $base())->where('status', MailMessage::SENT)->count(),
            'failed' => (clone $base())->where('status', MailMessage::FAILED)->count(),
            'skipped' => (clone $base())->where('status', MailMessage::SKIPPED)->count(),
            'awaiting' => MailThread::query()->when($siteId, fn ($b) => $b->where('site_id', $siteId))
                ->where('status', 'awaiting_reply')->count(),
        ];
    }

    private function canView(): bool
    {
        return in_array((string) Auth::user()?->access_role, self::VIEW_ROLES, true);
    }
}
