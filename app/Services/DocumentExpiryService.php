<?php

namespace App\Services;

use App\Models\CommunicationNotification;
use App\Models\IntegratedDocument;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 문서 만료 감시 — 보험증서(COI)·면허·인허가·비자처럼 기한이 있는 서류가 만료되기 전에 알린다.
 *
 * 미국 현장에서 COI/면허 만료는 공사중단·과태료로 직결되므로, 지나간 뒤가 아니라
 * 정해진 시점(D-60/30/14/7/1)에 미리 관리자에게 알림을 보낸다.
 */
class DocumentExpiryService
{
    /** 알림을 보낼 잔여일 임계값. 이 날짜에 딱 걸릴 때만 보내 중복 알림을 막는다. */
    public const ALERT_DAYS = [60, 30, 14, 7, 1];

    /** 알림 대상 관리자 역할. */
    private const MANAGER_ROLES = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    /**
     * 만료 임박/경과 문서 현황.
     *
     * @return array<string, mixed>
     */
    public function overview(?int $siteId = null, int $withinDays = 60): array
    {
        $today = Carbon::today();

        $rows = IntegratedDocument::query()
            ->whereNotNull('expires_on')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('expires_on', '<=', $today->copy()->addDays($withinDays))
            ->orderBy('expires_on')
            ->limit(200)->get();

        $items = $rows->map(function (IntegratedDocument $d) use ($today): array {
            $days = (int) $today->diffInDays($d->expires_on, false);

            return [
                'id' => $d->id,
                'title' => $d->title,
                'type' => IntegratedDocument::typeLabel($d->document_type),
                'folder' => $d->folderName(),
                'issuer' => $d->issuer ?: ($d->counterparty ?: '—'),
                'expiresOn' => $d->expires_on?->toDateString(),
                'daysLeft' => $days,
                'state' => $days < 0 ? 'expired' : ($days <= 14 ? 'critical' : 'soon'),
            ];
        })->values();

        return [
            'success' => true,
            'expired' => $items->where('state', 'expired')->count(),
            'critical' => $items->where('state', 'critical')->count(),
            'soon' => $items->where('state', 'soon')->count(),
            'items' => $items->all(),
        ];
    }

    /**
     * 오늘 알림을 보내야 하는 문서를 찾아 관리자에게 알림을 생성한다.
     *
     * @return array{sent: int, documents: int}
     */
    public function dispatchAlerts(?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        // D-60/30/14/7/1 에 정확히 걸리는 문서 + 오늘 만료된 문서.
        $targets = collect(self::ALERT_DAYS)->map(fn (int $d) => $today->copy()->addDays($d)->toDateString())
            ->push($today->toDateString())->unique()->all();

        $docs = IntegratedDocument::query()
            ->whereNotNull('expires_on')
            ->whereIn('expires_on', $targets)
            ->get();

        if ($docs->isEmpty()) {
            return ['sent' => 0, 'documents' => 0];
        }

        $managers = User::query()
            ->whereIn('access_role', self::MANAGER_ROLES)
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get();

        if ($managers->isEmpty()) {
            return ['sent' => 0, 'documents' => $docs->count()];
        }

        $sent = 0;
        foreach ($docs as $doc) {
            $days = (int) $today->diffInDays($doc->expires_on, false);
            $title = $days < 0
                ? sprintf('[문서 만료] %s', $doc->title)
                : ($days === 0
                    ? sprintf('[오늘 만료] %s', $doc->title)
                    : sprintf('[만료 D-%d] %s', $days, $doc->title));
            $body = sprintf(
                '%s · %s · 만료일 %s%s',
                IntegratedDocument::typeLabel($doc->document_type),
                $doc->folderName(),
                $doc->expires_on?->toDateString() ?? '-',
                $doc->issuer ? ' · ' . $doc->issuer : '',
            );

            foreach ($managers as $m) {
                // 같은 문서·같은 날 중복 알림 방지.
                $exists = CommunicationNotification::query()
                    ->where('user_id', $m->id)
                    ->where('type', 'document_expiry')
                    ->where('title', $title)
                    ->whereDate('created_at', $today->toDateString())
                    ->exists();
                if ($exists) {
                    continue;
                }

                CommunicationNotification::create([
                    'user_id' => $m->id,
                    'employee_id' => $m->employee_id,
                    'type' => 'document_expiry',
                    'title' => mb_substr($title, 0, 255),
                    'body' => mb_substr($body, 0, 255),
                ]);
                $sent++;
            }
        }

        return ['sent' => $sent, 'documents' => $docs->count()];
    }
}
