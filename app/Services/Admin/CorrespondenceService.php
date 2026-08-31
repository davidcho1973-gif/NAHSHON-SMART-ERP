<?php

namespace App\Services\Admin;

use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\Site;
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
     * <b>이 사람이 볼 수 있는 실타래로 좁힌다.</b>
     *
     * 역할 게이트(VIEW_ROLES)만으로는 부족하다. 그건 "서신 화면에 들어올 수 있는가" 이고,
     * 여기서 정하는 것은 "어느 줄을 볼 수 있는가" 다. 둘을 구분하지 않으면 현장소장 계정이
     * 실타래 번호를 1부터 훑어 다른 법인·다른 현장의 서신 제목·상대 주소·본문 전문을
     * 그대로 읽는다 — 단가와 클레임 문구가 그 안에 있다.
     *
     * 문서함은 같은 상황을 visibleTo() 로 막고 있다. 원장만 그 규약을 건너뛰고 있었다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<MailThread>  $q
     */
    private function applyScope($q): void
    {
        $user = Auth::user();

        // 협력사 계정처럼 회사에 갇힌 역할 — 정본 규칙을 그대로 쓴다.
        AccessPolicy::applyCompanyLock($q, $user);

        if (! $user) {
            $q->whereRaw('1 = 0');

            return;
        }

        if (in_array($user->access_role, AccessPolicy::SYSTEM_ROLES, true)
            || $user->access_scope === 'all_sites') {
            return;
        }

        match ($user->access_scope) {
            // 회사 범위 — 그 회사의 현장에 달린 서신과, 현장이 안 붙은 그 회사 서신.
            'company' => $user->allowed_company_id
                ? $q->where(function ($w) use ($user): void {
                    $w->where('company_id', $user->allowed_company_id)
                        ->orWhereIn('site_id', Site::query()
                            ->where('company_id', $user->allowed_company_id)->select('id'));
                })
                : $q->whereRaw('1 = 0'),

            'site' => $user->allowed_site_id
                ? $q->where('site_id', $user->allowed_site_id)
                : $q->whereRaw('1 = 0'),

            // team·self 는 서신을 볼 범위가 아니다. 넓게 열어 두느니 닫는다.
            default => $q->whereRaw('1 = 0'),
        };
    }

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
            // 화면이 준 현장은 <b>좁히는 데만</b> 쓴다. 넓히는 것은 applyScope 가 막는다.
            ->tap(fn ($b) => $this->applyScope($b))
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

        // 반드시 <b>스코프가 걸린 질의에서</b> 찾는다. 찾은 뒤에 검사하면 «있다/없다» 만으로도
        // 남의 법인에 어떤 번호가 존재하는지가 새고, 실수로 검사를 빠뜨리면 본문이 통째로 샌다.
        $t = MailThread::query()
            ->tap(fn ($b) => $this->applyScope($b))
            ->with(['site:id,name', 'createdBy:id,name'])
            ->find($id);

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

    /**
     * 위쪽 숫자판.
     *
     * 목록과 <b>같은 범위</b>로 세야 한다. 숫자만 전체를 세면 "목록엔 3건인데 위엔 120건"
     * 이 되고, 그 차이 자체가 남의 서신이 몇 건 있는지를 알려 준다.
     *
     * @return array<string, int>
     */
    private function stats(?int $siteId): array
    {
        $threads = fn () => MailThread::query()
            ->tap(fn ($b) => $this->applyScope($b))
            ->when($siteId, fn ($b) => $b->where('site_id', $siteId));

        // 봉투는 자기 실타래를 통해 좁힌다 — mail_messages 에도 site_id 가 있지만
        // 실타래가 정본이라 둘이 어긋나면 실타래를 따른다.
        $envelopes = fn () => MailMessage::query()
            ->whereIn('mail_thread_id', (clone $threads())->select('id'));

        return [
            'threads' => (clone $threads())->count(),
            'sent' => $envelopes()->where('status', MailMessage::SENT)->count(),
            'failed' => $envelopes()->where('status', MailMessage::FAILED)->count(),
            'skipped' => $envelopes()->where('status', MailMessage::SKIPPED)->count(),
            'awaiting' => (clone $threads())->where('status', 'awaiting_reply')->count(),
        ];
    }

    private function canView(): bool
    {
        return in_array((string) Auth::user()?->access_role, self::VIEW_ROLES, true);
    }
}
