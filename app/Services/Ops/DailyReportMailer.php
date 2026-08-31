<?php

namespace App\Services\Ops;

use App\Mail\ReportMail;
use App\Models\DailyClosingReport;
use App\Models\IntelligentDocument;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Models\Site;
use App\Models\WbsPhoto;
use App\Support\MailReady;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 작성된 보고서를 정해진 사람에게 보낸다 — 손으로도, 정해진 시각에 저절로도.
 *
 * 세 가지를 지킨다.
 *
 * 1. <b>보낸 척하지 않는다.</b> 메일 설정이 아직 없으면(라라벨 기본값이 `log` 라
 *    설정 없이도 발송이 "성공" 한다) 보내지 않고 메일앱 링크를 돌려준다.
 *    채널을 `mailto` 로 기록해서 나중에도 사실이 남는다.
 *
 * 2. <b>빈 보고서를 자동으로 내보내지 않는다.</b> 자동 발송은 사람이 제출한
 *    보고서만 보낸다. 아무도 안 쓴 날 원청에 빈 종이가 가는 것이, 안 가는 것보다
 *    훨씬 나쁘다 — 한 번 그러면 그 뒤로 아무도 그 메일을 안 읽는다.
 *
 * 3. <b>보낸 것은 문서함에 편철한다.</b> "그날 뭐라고 보고했나" 를 나중에 찾을 수
 *    없으면 보고 자체가 증거로 서지 못한다.
 */
class DailyReportMailer
{
    /** 첨부 총량 상한. 메일 서버 대부분이 25MB 에서 막고, 여유를 둔다. */
    private const MAX_ATTACHMENT_BYTES = 18 * 1024 * 1024;

    /** 한 통에 붙일 사진 수. 다 붙이면 메일이 안 열린다. */
    private const MAX_PHOTOS = 12;

    public function __construct(
        private readonly DailyReportComposer $composer,
    ) {}

    /**
     * 보고서 한 통을 보낸다.
     *
     * @param  string  $kind  plan(아침 계획서) | closing(저녁 마감보고서)
     * @return array<string, mixed>
     */
    public function send(
        ?int $siteId,
        ?string $date,
        string $kind,
        ?int $userId = null,
        bool $auto = false,
    ): array {
        $kind = $kind === ReportRecipient::PLAN ? ReportRecipient::PLAN : ReportRecipient::CLOSING;
        $date = $date ?: $this->today($siteId);

        $report = DailyClosingReport::query()
            ->with('site')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $date)
            ->first();

        if (! $report) {
            return ['success' => false, 'error' => "{$date} 보고서가 아직 없습니다. 먼저 작성해 주세요."];
        }

        // 내용이 있는지 먼저 본다. 자동 발송에서는 더 엄격하게(제출된 것만).
        $guard = $this->guard($report, $kind, $auto);
        if ($guard !== null) {
            return $guard;
        }

        $recipients = $this->recipients($report->site_id, $kind);
        if ($recipients->isEmpty()) {
            return [
                'success' => false,
                'error' => '수신처가 없습니다. [수신처 관리] 에서 받는 사람을 먼저 등록해 주세요.',
                'needsRecipients' => true,
            ];
        }

        $composed = $kind === ReportRecipient::PLAN
            ? $this->composer->plan($report)
            : $this->composer->closing($report);

        $to = $recipients->where('is_cc', false);
        $cc = $recipients->where('is_cc', true);

        // 참조만 등록돼 있으면 그 사람이 수신자다 — 아무에게도 안 가는 것보다 낫다.
        if ($to->isEmpty()) {
            $to = $cc;
            $cc = $cc->take(0);
        }

        $document = $this->file($report, $kind, $composed, $to->pluck('name')->all());
        $files = $kind === ReportRecipient::CLOSING ? $this->photos($report) : [];

        if (! MailReady::ok()) {
            // 보내지 않았다. 사장님 메일앱을 열어 사람 손으로 보내게 한다.
            foreach ($to as $r) {
                $this->log($report, $kind, 'mailto', $r, $composed['subject'], $document?->id, $userId, 'skipped', MailReady::why());
            }

            return [
                'success' => true,
                'sent' => 0,
                'channel' => 'mailto',
                'mailto' => MailReady::mailto(
                    $to->pluck('email')->all(),
                    $composed['subject'],
                    $composed['text'],
                    $cc->pluck('email')->all(),
                ),
                'documentId' => $document?->id,
                'message' => MailReady::why().' 대신 메일앱을 엽니다 — 내용이 채워지면 확인하고 보내 주세요.',
            ];
        }

        $sent = 0;
        $failed = [];
        $ccEmails = $cc->pluck('email')->all();

        foreach ($to as $r) {
            try {
                Mail::to($r->email, $r->name)->send(
                    new ReportMail($composed['subject'], $composed['html'], $files, $ccEmails),
                );
                $sent++;
                $this->log($report, $kind, 'mail', $r, $composed['subject'], $document?->id, $userId, 'sent');
                // 참조는 첫 통에만 싣는다 — 안 그러면 참조자가 같은 메일을 수신자 수만큼 받는다.
                $ccEmails = [];
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $r->email;
                $this->log($report, $kind, 'mail', $r, $composed['subject'], $document?->id, $userId, 'failed', $e->getMessage());
            }
        }

        return [
            'success' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'channel' => 'mail',
            'documentId' => $document?->id,
            'photos' => count($files),
            'message' => $sent > 0
                ? sprintf('%d명에게 발송했습니다%s.', $sent, $failed !== [] ? ' ('.count($failed).'명 실패)' : '')
                : '발송에 실패했습니다: '.implode(', ', $failed),
        ];
    }

    /**
     * 보낼 내용이 있는가 — 없으면 보내지 않고 이유를 말한다.
     *
     * @return array<string, mixed>|null
     */
    private function guard(DailyClosingReport $report, string $kind, bool $auto): array|null
    {
        if ($kind === ReportRecipient::PLAN) {
            if (! $report->hasPlan()) {
                return ['success' => false, 'error' => '작업계획서 내용이 비어 있습니다. 먼저 작성해 주세요.'];
            }
            if ($auto && $report->plan_status !== DailyClosingReport::PLAN_SUBMITTED) {
                return ['success' => false, 'error' => '작업계획서가 아직 제출 전입니다(자동 발송 보류).', 'held' => true];
            }

            return null;
        }

        if ($report->status !== DailyClosingReport::DONE) {
            // 자동 발송은 사람이 없는 자리다. 아직 마감이 안 끝난 것은 사고가 아니라
            // 그냥 아직 안 한 것이므로 조용히 보류한다 — 매일 경고를 띄우면 무뎌진다.
            if ($auto) {
                return ['success' => false, 'error' => '마감 미완료(자동 발송 보류).', 'held' => true];
            }
            if (! $report->hasFieldReport()) {
                return ['success' => false, 'error' => '마감이 아직 끝나지 않았습니다. [일일 마감] 을 먼저 실행해 주세요.'];
            }
        }

        return null;
    }

    /**
     * 이 현장의 수신처. 현장 전용 + 전 현장 공통을 합친다.
     *
     * @return \Illuminate\Support\Collection<int, ReportRecipient>
     */
    public function recipients(?int $siteId, string $kind): \Illuminate\Support\Collection
    {
        return ReportRecipient::query()
            ->where('active', true)
            ->where(function ($q) use ($siteId): void {
                $q->whereNull('site_id');           // 전 현장 공통(본사)
                if ($siteId) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->orderBy('is_cc')->orderBy('id')
            ->get()
            ->filter(fn (ReportRecipient $r): bool => $r->wants($kind))
            // 같은 주소가 공통·현장 양쪽에 있으면 두 번 보내게 된다.
            ->unique(fn (ReportRecipient $r): string => Str::lower($r->email))
            ->values();
    }

    /**
     * 그날 작업 사진을 첨부로 모은다.
     *
     * 현장 사진은 `wbs_photos` 가 정본이다 — 공정 코드와 날짜로 이미 쌓여 있고
     * 축소본이 함께 저장돼 있다. 원본을 붙이면 한 장에 5MB 라 메일이 막히므로
     * <b>축소본</b>을 쓴다.
     *
     * @return list<array{data: string, name: string, mime: string}>
     */
    private function photos(DailyClosingReport $report): array
    {
        if (! $report->site_id) {
            return [];
        }

        $rows = WbsPhoto::query()
            ->where('site_id', $report->site_id)
            ->whereDate('photo_date', $report->report_date->toDateString())
            ->orderBy('id')
            ->limit(self::MAX_PHOTOS)
            ->get();

        $files = [];
        $total = 0;

        foreach ($rows as $i => $p) {
            try {
                $disk = Storage::disk($p->disk ?: config('filesystems.default'));
                $path = $p->path;   // 1600px 축소본이 이미 정본이다(원본은 따로 보관하지 않는다)
                if (! $path || ! $disk->exists($path)) {
                    continue;
                }
                $data = (string) $disk->get($path);
                $total += strlen($data);
                if ($total > self::MAX_ATTACHMENT_BYTES) {
                    break;
                }

                $ext = pathinfo((string) ($p->original_name ?: $path), PATHINFO_EXTENSION) ?: 'jpg';
                $files[] = [
                    'data' => $data,
                    'name' => sprintf('%02d_%s.%s', $i + 1,
                        Str::slug((string) ($p->caption ?: $p->wbs_code ?: 'photo')) ?: 'photo', $ext),
                    'mime' => (string) ($p->mime ?: 'image/jpeg'),
                ];
            } catch (\Throwable $e) {
                Log::warning('일일 보고 사진 첨부 실패 (photo '.$p->id.'): '.$e->getMessage());
            }
        }

        return $files;
    }

    /**
     * 보낸 보고서를 문서함에 편철한다.
     *
     * @param  array{subject: string, html: string, text: string}  $composed
     * @param  list<string>  $recipientNames
     */
    private function file(
        DailyClosingReport $report,
        string $kind,
        array $composed,
        array $recipientNames,
    ): ?IntelligentDocument {
        try {
            $date = $report->report_date->toDateString();
            $label = $kind === ReportRecipient::PLAN ? '작업계획서' : '작업보고서';
            $siteCode = $report->site?->code ?: 'ALL';

            // 같은 날 같은 종류를 두 번 보내면 문서가 두 벌 쌓인다 — 덮어쓴다.
            $number = sprintf('%s-%s-%s', $kind === ReportRecipient::PLAN ? 'DWP' : 'DCR',
                $siteCode, str_replace('-', '', $date));

            $existing = IntelligentDocument::query()->where('document_number', $number)->first();

            $uuid = $existing?->uuid ?: (string) Str::uuid();
            $name = "{$date}_{$label}_{$siteCode}.html";
            $diskName = (string) config('document-intelligence.disk');
            $path = "document-intelligence/inbox/{$uuid}/{$name}";
            Storage::disk($diskName)->put($path, $composed['html']);

            $attributes = [
                'uuid' => $uuid,
                'disk' => $diskName,
                'site_id' => $report->site_id,
                'file_path' => $path,
                'original_file_name' => $name,
                'stored_file_name' => $name,
                'mime_type' => 'text/html',
                'extension' => 'html',
                'file_size' => strlen($composed['html']),
                'sha256' => hash('sha256', $composed['html']),
                'title' => sprintf('%s 일일 %s (%s)', $date, $label, $report->site?->name ?: '전 현장'),
                // 문서함이 이미 아는 분류를 쓴다 — 새 이름을 지어내면 서랍 밖에 떨어져
                // 목록·검색에 안 잡힌다. 일보는 '공정·일정' 서랍의 '일보' 종류다.
                'category' => 'schedule',
                'document_type' => 'daily_report',
                'direction' => 'outgoing',
                'document_number' => $number,
                'recipients' => $recipientNames,
                'summary' => Str::limit(strip_tags($composed['text']), 400),
                // 우리가 만든 문서라 AI 분류를 다시 돌릴 이유가 없다.
                'ai_status' => 'ready',
                'ai_confidence' => 100,
                'received_at' => now(),
                'analyzed_at' => now(),
                'document_date' => $date,
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return IntelligentDocument::create($attributes);
        } catch (\Throwable $e) {
            // 편철에 실패해도 발송은 계속한다 — 보고가 나가는 것이 먼저다.
            report($e);
            Log::warning('일일 보고 문서함 편철 실패: '.$e->getMessage());

            return null;
        }
    }

    private function log(
        DailyClosingReport $report,
        string $kind,
        string $channel,
        ReportRecipient $r,
        string $subject,
        ?int $documentId,
        ?int $userId,
        string $status,
        ?string $error = null,
    ): void {
        ReportDispatch::create([
            'daily_closing_report_id' => $report->id,
            'kind' => $kind,
            'channel' => $channel,
            'to_email' => $r->email,
            'to_name' => $r->name,
            'subject' => Str::limit($subject, 245, ''),
            'status' => $status,
            'error' => $error,
            'intelligent_document_id' => $documentId,
            'created_by_id' => $userId,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    /**
     * 이 보고서의 발송 이력.
     *
     * @return array<string, mixed>
     */
    public function history(?int $siteId, ?string $date = null): array
    {
        $date = $date ?: $this->today($siteId);

        $report = DailyClosingReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $date)
            ->first();

        if (! $report) {
            return ['success' => true, 'rows' => [], 'mailReady' => MailReady::ok()];
        }

        return [
            'success' => true,
            'mailReady' => MailReady::ok(),
            'mailNote' => MailReady::ok() ? null : MailReady::why(),
            'rows' => $report->dispatches()->limit(40)->get()->map(fn (ReportDispatch $d): array => [
                'id' => $d->id,
                'kind' => $d->kind === ReportRecipient::PLAN ? '작업계획서' : '작업보고서',
                'channel' => $d->channel,
                'to' => $d->to_name ? "{$d->to_name} <{$d->to_email}>" : $d->to_email,
                'status' => $d->status,
                'error' => $d->error,
                'sentAt' => $d->sent_at?->format('m-d H:i') ?: $d->created_at?->format('m-d H:i'),
                'documentId' => $d->intelligent_document_id,
            ])->all(),
        ];
    }

    /** 미리보기 HTML — 화면과 메일이 같은 것을 쓰는지 눈으로 확인하는 자리. */
    public function preview(?int $siteId, ?string $date, string $kind): array
    {
        $date = $date ?: $this->today($siteId);

        $report = DailyClosingReport::query()->with('site')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId), fn ($q) => $q->whereNull('site_id'))
            ->whereDate('report_date', $date)
            ->first();

        if (! $report) {
            return ['success' => false, 'error' => "{$date} 보고서가 아직 없습니다."];
        }

        $composed = $kind === ReportRecipient::PLAN
            ? $this->composer->plan($report)
            : $this->composer->closing($report);

        return [
            'success' => true,
            'subject' => $composed['subject'],
            'html' => $composed['html'],
            'recipients' => $this->recipients($report->site_id, $kind)
                ->map(fn (ReportRecipient $r): string => $r->name.($r->is_cc ? ' (참조)' : ''))->all(),
        ];
    }

    private function today(?int $siteId): string
    {
        $tz = ($siteId ? Site::find($siteId)?->timezone : null) ?: config('app.timezone');

        return Carbon::now($tz)->toDateString();
    }
}
