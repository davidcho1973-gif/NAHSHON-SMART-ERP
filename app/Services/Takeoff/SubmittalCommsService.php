<?php

namespace App\Services\Takeoff;

use App\Mail\SubmittalMail;
use App\Models\IntelligentDocument;
use App\Models\Submittal;
use App\Models\SubmittalEvent;
use App\Services\Mail\OutboundMailer;
use App\Support\MailReady;
use App\Support\Org;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 제출물 소통 회로 — 자료 제공자(업체) → 우리 → 최종 수신자(원청·감리).
 *
 * 담당자 이메일만 넣으면 요청·전달 메일이 만들어지고, 보낸 것·받은 것·전달한
 * 것이 전부 조항에 기록으로 남는다. 단계와 상태를 한 몸으로 묶는다:
 *
 *   요청 보냄        → 미착수 → 작성중   (업체가 자료를 만들기 시작)
 *   받은 자료 연결   → (상태 유지)       (자료가 손에 들어옴 — 아직 원청엔 안 감)
 *   원청에 전달      → 제출 + 제출일     (진짜 "제출" 은 원청에 간 순간이다)
 *   승인본 연결      → 승인 + 승인일
 *
 * 메일 서버가 없으면 거짓말하지 않는다 — mailto 링크를 돌려주어 사장님 메일앱에서
 * 보내게 하고, 그 사실("메일앱에서 작성")을 그대로 기록한다.
 */
class SubmittalCommsService
{

    public function __construct(
        private readonly OutboundMailer $outbound,
    ) {}

    // 첨부 한도는 MailReady::attachmentBudget() 이 정본이다 — 메일러마다 다르다.
    // 일부만 보내고 전체 제출로 표시하지 않는다. 원본·용량을 모두 확인한 뒤 발송한다.

    /* ─── 담당자 ────────────────────────────────────────────────────────── */

    /**
     * 담당자 저장. $applyToCsi 면 같은 프로젝트·같은 공종(CSI) 전체에 적는다 —
     * 도어하드웨어 업체는 08 7100 전 줄의 담당이지 5번 줄만의 담당이 아니다.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, applied: int}
     */
    public function saveContacts(Submittal $submittal, array $data, bool $applyToCsi = false): array
    {
        $fields = [
            'vendor_name' => Str::limit(trim((string) ($data['vendorName'] ?? '')), 120, ''),
            'vendor_email' => Str::lower(trim((string) ($data['vendorEmail'] ?? ''))),
            'vendor_phone' => Str::limit(trim((string) ($data['vendorPhone'] ?? '')), 40, ''),
            'recipient_name' => Str::limit(trim((string) ($data['recipientName'] ?? '')), 120, ''),
            'recipient_email' => Str::lower(trim((string) ($data['recipientEmail'] ?? ''))),
        ];
        foreach (['vendor_email', 'recipient_email'] as $key) {
            if ($fields[$key] !== '' && ! filter_var($fields[$key], FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'applied' => 0, 'error' => '이메일 형식이 올바르지 않습니다: '.$fields[$key]];
            }
        }
        $fields = array_map(fn (string $v): ?string => $v !== '' ? $v : null, $fields);

        $submittal->forceFill($fields)->save();
        $applied = 1;

        if ($applyToCsi && $submittal->csi) {
            // 빈 값은 퍼뜨리지 않는다 — 5번 줄에서 전화번호를 안 적었다고
            // 7번 줄에 이미 있던 번호를 지우면 안 된다.
            $spread = array_filter($fields, fn (?string $v): bool => $v !== null);
            if ($spread !== []) {
                $applied += Submittal::query()
                    ->where('project_id', $submittal->project_id)
                    ->where('csi', $submittal->csi)
                    ->whereKeyNot($submittal->id)
                    ->update($spread);
            }
        }

        return ['success' => true, 'applied' => $applied];
    }

    /* ─── 한눈 요약 (소통 모달이 연다) ──────────────────────────────────── */

    /** @return array<string, mixed> */
    public function overview(Submittal $submittal): array
    {
        $submittal->load(['documents:id,title,original_file_name', 'events' => fn ($q) => $q->with('document:id,title,original_file_name')]);

        return [
            'success' => true,
            'mailReady' => $this->mailReady(),
            'contacts' => [
                'vendorName' => $submittal->vendor_name,
                'vendorEmail' => $submittal->vendor_email,
                'vendorPhone' => $submittal->vendor_phone,
                'recipientName' => $submittal->recipient_name,
                'recipientEmail' => $submittal->recipient_email,
            ],
            'status' => $submittal->status,
            'documents' => $submittal->documents->map(fn (IntelligentDocument $d): array => [
                'id' => $d->id,
                'label' => $d->title ?: $d->original_file_name,
                'kind' => (string) $d->pivot->kind,
            ])->values()->all(),
            'events' => $submittal->events->map(fn (SubmittalEvent $e): array => [
                'kind' => $e->kind,
                'label' => SubmittalEvent::KIND_LABELS[$e->kind] ?? $e->kind,
                'channel' => $e->channel,
                'to' => trim(($e->to_name ?: '').' '.($e->to_email ? '<'.$e->to_email.'>' : '')),
                'subject' => $e->subject,
                'document' => $e->document?->title ?: $e->document?->original_file_name,
                'at' => $e->created_at?->format('Y-m-d H:i'),
            ])->values()->all(),
            'linkable' => $this->linkableDocuments($submittal),
        ];
    }

    /* ─── ① 업체에 요청 ─────────────────────────────────────────────────── */

    /**
     * @return array{success: bool, error?: string, sent?: bool, mailto?: string, message?: string}
     */
    public function sendRequest(Submittal $submittal, ?int $userId = null): array
    {
        if (! $submittal->vendor_email) {
            return ['success' => false, 'error' => '자료 제공 업체의 이메일을 먼저 넣어 주세요.'];
        }

        $subject = $this->requestSubject($submittal);
        $html = $this->requestHtml($submittal);

        // 발송은 OutboundMailer 한 문으로만 나간다 — 그래야 서신 원장이 저절로 채워진다.
        // 예전에는 여기서 Mail::to 를 직접 불러서, 무엇을 보냈는지 본문이 어디에도 안 남았다.
        $thread = $this->outbound->threadFor(
            $submittal, $subject, $submittal->vendor_email, $submittal->vendor_name,
            $submittal->site_id, $submittal->company_id, $submittal->project_id,
        );

        $result = $this->outbound->send(
            $thread,
            [['email' => $submittal->vendor_email, 'name' => $submittal->vendor_name ?: null]],
            [],
            $subject, $html, $this->requestText($submittal),
            fn (?string $mid, array $refs) => new SubmittalMail($subject, $html, [], $mid, $refs),
        );

        $channel = ($result['channel'] ?? 'mail') === 'mailto' ? 'mailto' : 'email';

        // <b>나갔는지를 채널 이름이 아니라 결과로 판정한다.</b>
        // OutboundMailer 는 발송이 실패해도 channel='mail' 을 돌려준다(채널은 «어느 길로
        // 시도했나» 이지 «나갔나» 가 아니다). 예전에는 그 둘을 같은 것으로 보고 무조건
        // 성공으로 적었다 — 메일이 안 나갔는데 화면에는 초록 토스트가 뜨고 제출물 상태가
        // 앞으로 나아갔다. 703K 처럼 정지 조항이 걸린 현장에서 그건 버그가 아니라
        // 준수 기록의 위조다.
        if ($channel === 'email' && ! ($result['success'] ?? false)) {
            $this->log($submittal, 'request_failed', 'email', $submittal->vendor_name,
                $submittal->vendor_email, $subject, null, $userId, $result['messageId'] ?? null);

            return [
                'success' => false,
                'error' => ($submittal->vendor_email ?: '거래처').' 로 보내지 못했습니다 — '
                    .($result['error'] ?? '알 수 없는 실패').' (서신 원장 '.$thread->ref_code.' 에 실패로 남았습니다)',
                'refCode' => $thread->ref_code,
            ];
        }

        $message = $channel === 'email'
            ? ($submittal->vendor_name ?: $submittal->vendor_email).' 에게 요청 메일을 보냈습니다. ('.$thread->ref_code.')'
            : '메일 서버가 없어 메일앱으로 엽니다 — 보내기를 누르면 발송됩니다.';

        $this->log($submittal, $channel === 'email' ? 'request_sent' : 'request_draft', $channel, $submittal->vendor_name, $submittal->vendor_email, $subject, null, $userId, $result['messageId'] ?? null);

        if ($channel === 'email' && $submittal->status === '미착수') {
            $submittal->forceFill(['status' => '작성중'])->save();
        }

        return [
            'success' => true,
            'sent' => $channel === 'email',
            'mailto' => $result['mailto'] ?? null,
            'refCode' => $thread->ref_code,
            'message' => $message,
        ];
    }

    /* ─── ② 받은 자료 연결 ──────────────────────────────────────────────── */

    /**
     * @param  list<int>  $documentIds
     * @return array{success: bool, linked: int, error?: string}
     */
    public function linkDocuments(Submittal $submittal, array $documentIds, string $kind, ?int $userId = null, array $decision = []): array
    {
        if (! in_array($kind, ['received', 'approval'], true)) {
            return ['success' => false, 'linked' => 0, 'error' => '문서 연결 종류를 확인하세요.'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($ids === []) {
            return ['success' => false, 'linked' => 0, 'error' => '연결할 문서를 고르세요.'];
        }
        if ($kind === 'approval') {
            $validator = Validator::make($decision, [
                'disposition' => 'required|in:승인,조건부승인,반려',
                'decisionOn' => 'required|date_format:Y-m-d|before_or_equal:today',
                'decisionNote' => 'nullable|string|max:200',
            ]);
            if ($validator->fails() || (in_array($decision['disposition'] ?? '', ['조건부승인', '반려'], true) && blank($decision['decisionNote'] ?? null))) {
                return ['success' => false, 'linked' => 0, 'error' => '회신 문서의 실제 판정·회신일을 입력하세요. 조건부승인·반려는 조건 또는 사유(200자 이내)도 필요합니다.'];
            }
        }

        return DB::transaction(function () use ($submittal, $ids, $kind, $userId, $decision): array {
            $submittal = Submittal::query()->lockForUpdate()->findOrFail($submittal->id);
            // Visibility alone is insufficient for an operator who manages several projects.
            $documents = IntelligentDocument::query()->visibleTo(auth()->user())->whereIn('id', $ids)->get();
            if ($documents->count() !== count($ids) || $documents->contains(fn ($d) => ! $this->sameProject($submittal, $d) || $d->access_level === 'private')) {
                return ['success' => false, 'linked' => 0, 'error' => '같은 프로젝트의 접근 가능한 공유 문서만 연결할 수 있습니다. 다른 현장·프로젝트 또는 개인 문서를 확인하세요.'];
            }
            if ($kind === 'approval') {
                foreach ($documents as $document) {
                    try {
                        $this->documentBytes($document);
                    } catch (\Throwable $e) {
                        return ['success' => false, 'linked' => 0, 'error' => '회신 원본을 확인할 수 없습니다: '.$document->displayTitle()];
                    }
                }
            }
            $linked = $submittal->documents()->pluck('intelligent_documents.id')->all();
            foreach ($documents as $document) {
                // A received file may also contain the stamped response. Keep its existing role;
                // the immutable event records the response instead of rewriting previous links.
                if (! in_array($document->id, $linked, true)) {
                    $submittal->documents()->attach($document->id, ['kind' => $kind, 'created_by' => $userId]);
                }
                $subject = $kind === 'approval'
                    ? $decision['disposition'].' · '.$decision['decisionOn'].' · '.trim((string) ($decision['decisionNote'] ?? ''))
                    : null;
                $this->log($submittal, $kind === 'approval' ? 'response_recorded' : 'materials_linked', 'manual',
                    null, null, $subject, $document->id, $userId);
            }

            if ($kind === 'approval') {
                $submittal->forceFill([
                    'status' => $decision['disposition'],
                    'approved_on' => in_array($decision['disposition'], ['승인', '조건부승인'], true) ? $decision['decisionOn'] : null,
                ])->save();
            } elseif ($submittal->status === '미착수') {
                $submittal->forceFill(['status' => '작성중'])->save();
            }

            return ['success' => true, 'linked' => $documents->count(), 'status' => $submittal->status];
        });
    }

    /* ─── ③ 원청에 전달 ─────────────────────────────────────────────────── */

    /**
     * @return array{success: bool, error?: string, sent?: bool, mailto?: string, message?: string}
     */
    public function sendTransmit(Submittal $submittal, ?int $userId = null): array
    {
        if (! $submittal->recipient_email) {
            return ['success' => false, 'error' => '최종 수신자(원청·감리)의 이메일을 먼저 넣어 주세요.'];
        }

        $materials = $submittal->documents()->wherePivot('kind', 'received')->get();
        if ($materials->isEmpty()) {
            return ['success' => false, 'error' => '전달할 자료가 없습니다. 받은 자료를 먼저 연결해 주세요.'];
        }

        $subject = $this->transmitSubject($submittal);
        // Root cause: the old path silently omitted files, then certified the whole packet as submitted.
        $visible = IntelligentDocument::query()->visibleTo(auth()->user())->whereIn('id', $materials->modelKeys())->pluck('id');
        if ($materials->contains(fn ($d) => ! $visible->contains($d->id) || ! $this->sameProject($submittal, $d) || $d->access_level === 'private')) {
            return ['success' => false, 'error' => '제출 자료에 다른 프로젝트 또는 접근할 수 없는 개인 문서가 있습니다. 연결을 확인하세요.'];
        }
        [$files, $problems] = $this->collectAttachments($materials);
        if ($problems !== []) {
            $this->log($submittal, 'transmit_preflight_failed', 'manual', $submittal->recipient_name,
                $submittal->recipient_email, implode(' · ', $problems), null, $userId);

            return ['success' => false, 'sent' => false, 'error' => '자료를 모두 첨부할 수 없어 보내지 않았습니다: '.implode(' · ', $problems)];
        }
        $html = $this->transmitHtml($submittal, $materials);

        $thread = $this->outbound->threadFor(
            $submittal, $subject, $submittal->recipient_email, $submittal->recipient_name,
            $submittal->site_id, $submittal->company_id, $submittal->project_id,
        );

        $result = $this->outbound->send(
            $thread,
            [['email' => $submittal->recipient_email, 'name' => $submittal->recipient_name ?: null]],
            [],
            $subject, $html, $this->transmitText($submittal, $materials),
            fn (?string $mid, array $refs) => new SubmittalMail($subject, $html, $files, $mid, $refs),
            $materials->pluck('id')->all(),
            count($files),
        );

        $channel = ($result['channel'] ?? 'mail') === 'mailto' ? 'mailto' : 'email';

        // <b>나가지 않았으면 «제출» 로 만들지 않는다.</b> 여기가 특히 위험한 자리다 —
        // 상태를 «제출» 로 바꾸고 submitted_on 에 오늘 날짜를 박는데, 그 날짜가 곧
        // "우리가 언제 냈는가" 의 근거가 된다. 실제로 나가지 않은 전달에 그 날짜가
        // 남으면 나중에 원청과 다툴 때 우리가 대는 증거가 거짓이 된다.
        if ($channel === 'email' && ! ($result['success'] ?? false)) {
            $this->log($submittal, 'transmit_failed', 'email', $submittal->recipient_name,
                $submittal->recipient_email, $subject, null, $userId, $result['messageId'] ?? null);

            return [
                'success' => false,
                'error' => ($submittal->recipient_email ?: '수신처').' 로 전달하지 못했습니다 — '
                    .($result['error'] ?? '알 수 없는 실패')
                    .' 제출물 상태는 그대로 두었습니다(나가지 않은 것을 «제출» 로 적지 않습니다). '
                    .'서신 원장 '.$thread->ref_code.' 에 실패로 남았습니다.',
                'refCode' => $thread->ref_code,
            ];
        }

        $message = $channel === 'email'
            ? ($submittal->recipient_name ?: $submittal->recipient_email).' 에게 전달했습니다 — 자료 '.$materials->count().'건'
                .'.'
            : '메일 서버가 없어 메일앱으로 엽니다. 자료 파일은 문서함에서 내려받아 직접 첨부해 주세요.';

        $this->log($submittal, $channel === 'email' ? 'transmitted' : 'transmit_draft', $channel, $submittal->recipient_name, $submittal->recipient_email, $subject, null, $userId, $result['messageId'] ?? null);
        // Opening the mail app is a draft, not evidence that the GC received a submission.
        if ($channel === 'email') {
            $submittal->forceFill([
                'status' => ($submittal->submitted_on || in_array($submittal->status, ['승인', '조건부승인', '반려'], true)) ? '재제출' : '제출',
                'submitted_on' => now()->toDateString(),
                'approved_on' => null,
            ])->save();
        }

        return [
            'success' => true,
            'sent' => $channel === 'email',
            'mailto' => $result['mailto'] ?? null,
            'refCode' => $thread->ref_code,
            'message' => $message,
        ];
    }

    /* ─── 본문·제목 ─────────────────────────────────────────────────────── */

    private function requestSubject(Submittal $submittal): string
    {
        return '[자료 요청] '.trim($submittal->csi.' '.$submittal->section).' 제출물 #'.$submittal->seq
            .($submittal->gate ? ' (정지 조항 — 승인 전 발주 불가)' : '');
    }

    private function requestHtml(Submittal $submittal): string
    {
        // 「자료요청」 으로 이미 만들어 둔 요청서가 있으면 그 편지를 그대로 본문으로 쓴다 —
        // 같은 요청이 화면 따로 메일 따로 두 벌이 되면 어느 쪽이 정본인지 아무도 모른다.
        $letter = $this->latestRequestLetter($submittal);
        if ($letter !== null) {
            return $letter;
        }

        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:sans-serif;line-height:1.7;color:#1a1a1a;max-width:680px">'
            .'<p>안녕하세요, '.$e(Org::name()).' 입니다.</p>'
            .'<p>아래 시방 조항에 따라 제출이 필요한 자료를 요청드립니다.</p>'
            .'<table style="border-collapse:collapse;font-size:13px;margin:14px 0">'
            .'<tr><td style="border:1px solid #ddd;padding:7px 10px;background:#f6f7f9;font-weight:700">시방 조항</td>'
            .'<td style="border:1px solid #ddd;padding:7px 10px">'.$e(trim($submittal->csi.' '.$submittal->section)).'</td></tr>'
            .'<tr><td style="border:1px solid #ddd;padding:7px 10px;background:#f6f7f9;font-weight:700">요구사항</td>'
            .'<td style="border:1px solid #ddd;padding:7px 10px">'.$e($submittal->title).'</td></tr>'
            .'</table>'
            .($submittal->gate
                ? '<p style="color:#8c2f26"><b>※ 이 항목은 시방 정지 조항입니다.</b> 승인 전에는 발주·시공을 진행할 수 없어 회신이 늦어지면 공정 전체가 지연됩니다.</p>'
                : '')
            .'<p>자료는 이 메일에 회신으로 보내 주시면 됩니다. 감사합니다.</p>'
            .'<p style="color:#666;font-size:12px">'.$e(Org::name()).' · 제출물 #'.$e($submittal->seq).'</p>'
            .'</div>';
    }

    private function requestText(Submittal $submittal): string
    {
        return implode("\n", array_filter([
            '안녕하세요, '.Org::name().' 입니다.',
            '',
            '아래 시방 조항에 따라 제출이 필요한 자료를 요청드립니다.',
            '',
            '시방 조항: '.trim($submittal->csi.' '.$submittal->section),
            '요구사항: '.$submittal->title,
            $submittal->gate ? '※ 정지 조항입니다 — 승인 전 발주·시공 불가. 회신이 늦어지면 공정이 지연됩니다.' : null,
            '',
            '자료는 이 메일에 회신으로 보내 주시면 됩니다. 감사합니다.',
        ]));
    }

    private function transmitSubject(Submittal $submittal): string
    {
        return '[Submittal] '.trim($submittal->csi.' '.$submittal->section).' #'.$submittal->seq.' — '.Org::name();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, IntelligentDocument>  $materials
     */
    private function transmitHtml(Submittal $submittal, $materials): string
    {
        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = $materials->map(fn (IntelligentDocument $d): string => '<li>'.$e($d->title ?: $d->original_file_name).'</li>')->implode('');

        return '<div style="font-family:sans-serif;line-height:1.7;color:#1a1a1a;max-width:680px">'
            .'<p>안녕하세요, '.$e(Org::name()).' 입니다.</p>'
            .'<p>아래 제출물 자료를 송부드립니다. 검토 후 승인 회신 부탁드립니다.</p>'
            .'<table style="border-collapse:collapse;font-size:13px;margin:14px 0">'
            .'<tr><td style="border:1px solid #ddd;padding:7px 10px;background:#f6f7f9;font-weight:700">시방 조항</td>'
            .'<td style="border:1px solid #ddd;padding:7px 10px">'.$e(trim($submittal->csi.' '.$submittal->section)).'</td></tr>'
            .'<tr><td style="border:1px solid #ddd;padding:7px 10px;background:#f6f7f9;font-weight:700">제출물</td>'
            .'<td style="border:1px solid #ddd;padding:7px 10px">#'.$e($submittal->seq).' '.$e(Str::limit($submittal->title, 160)).'</td></tr>'
            .'</table>'
            .'<p><b>첨부 자료</b></p><ul>'.$rows.'</ul>'
            .'<p style="color:#666;font-size:12px">'.$e(Org::name()).'</p>'
            .'</div>';
    }

    /** @param \Illuminate\Support\Collection<int, IntelligentDocument> $materials */
    private function transmitText(Submittal $submittal, $materials): string
    {
        return implode("\n", array_filter([
            '안녕하세요, '.Org::name().' 입니다.',
            '',
            '아래 제출물 자료를 송부드립니다. 검토 후 승인 회신 부탁드립니다.',
            '',
            '시방 조항: '.trim($submittal->csi.' '.$submittal->section),
            '제출물: #'.$submittal->seq.' '.$submittal->title,
            '',
            '자료 목록:',
            ...$materials->map(fn (IntelligentDocument $d): string => ' - '.($d->title ?: $d->original_file_name))->all(),
            '',
            '(자료 파일은 첨부를 확인해 주세요)',
        ]));
    }

    /* ─── 살림 ──────────────────────────────────────────────────────────── */

    /**
     * @param  \Illuminate\Support\Collection<int, IntelligentDocument>  $materials
     * @return array{0: list<array{data: string, name: string, mime: string}>, 1: list<string>}
     */
    private function collectAttachments($materials): array
    {
        $files = [];
        $problems = [];
        $total = 0;
        foreach ($materials as $document) {
            $label = (string) ($document->title ?: $document->original_file_name);
            try {
                $data = $this->documentBytes($document);
            } catch (\Throwable $e) {
                $problems[] = $label.' (원본 없음·읽기 실패·원본 불일치)';

                continue;
            }
            $total += strlen($data);
            if ($total > MailReady::attachmentBudget()) {
                $problems[] = $label.' (첨부 총량 초과)';

                continue;
            }
            $files[] = [
                'data' => $data,
                'name' => $document->original_file_name ?: ($label.'.'.$document->extension),
                'mime' => $document->mime_type ?: 'application/octet-stream',
            ];
        }

        return [$files, $problems];
    }

    private function sameProject(Submittal $submittal, IntelligentDocument $document): bool
    {
        if ((int) $document->company_id !== (int) $submittal->company_id) {
            return false;
        }

        return $submittal->project_id
            ? (int) $document->project_id === (int) $submittal->project_id
                && (! $document->site_id || (int) $document->site_id === (int) $submittal->site_id)
            : ($submittal->site_id && (int) $document->site_id === (int) $submittal->site_id && ! $document->project_id);
    }

    private function documentBytes(IntelligentDocument $document): string
    {
        $disk = Storage::disk($document->disk ?: config('document-intelligence.disk'));
        if (blank($document->file_path) || ! $disk->exists($document->file_path)) {
            throw new \RuntimeException('원본 없음');
        }
        $bytes = $disk->get($document->file_path);
        if (! is_string($bytes) || $bytes === '' || (filled($document->sha256) && ! hash_equals(strtolower($document->sha256), hash('sha256', $bytes)))) {
            throw new \RuntimeException('원본 불일치');
        }

        return $bytes;
    }

    /** 「자료요청」 이 만들어 둔 요청서 HTML — 있으면 메일 본문의 정본이다. */
    private function latestRequestLetter(Submittal $submittal): ?string
    {
        // CSI/sequence numbers repeat in different projects. Only this row's explicit
        // request relation identifies its letter; old unlinked letters are not guessed.
        $document = $submittal->documents()->visibleTo(auth()->user())
            ->wherePivot('kind', 'request')->where('extension', 'html')
            ->orderByDesc('intelligent_documents.id')->first();
        if (! $document || ! $this->sameProject($submittal, $document)) {
            return null;
        }
        try {
            return $this->documentBytes($document);
        } catch (\Throwable) {
            return null;
        }
    }

    /** 연결 후보 — 이 프로젝트(없으면 현장)의 최근 문서 중 아직 안 이어진 것. */
    private function linkableDocuments(Submittal $submittal): array
    {
        $linked = $submittal->documents->pluck('id');

        return IntelligentDocument::query()
            ->visibleTo(auth()->user())
            ->when($submittal->project_id,
                fn ($q) => $q->where('project_id', $submittal->project_id),
                fn ($q) => $q->where('site_id', $submittal->site_id))
            ->whereNotIn('id', $linked)
            ->orderByDesc('received_at')->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'title', 'original_file_name', 'received_at'])
            ->map(fn (IntelligentDocument $d): array => [
                'id' => $d->id,
                'label' => $d->title ?: $d->original_file_name,
                'receivedAt' => $d->received_at?->format('Y-m-d'),
            ])->values()->all();
    }

    // 판정 규칙은 App\Support\MailReady 한 곳에만 둔다. 예전에는 같은 조건이
    // 여기와 지원자 초대 서비스에 각각 복사돼 있었고, 한쪽만 고치면 규칙이 갈라졌다.
    private function mailReady(): bool
    {
        return \App\Support\MailReady::ok();
    }

    private function mailto(string $to, string $subject, string $body): string
    {
        return \App\Support\MailReady::mailto($to, $subject, $body);
    }

    private function log(
        Submittal $submittal,
        string $kind,
        string $channel,
        ?string $toName,
        ?string $toEmail,
        ?string $subject,
        ?int $documentId,
        ?int $userId,
        // 원장의 어느 봉투인가. 제출물 이벤트와 서신 원장이 같은 발송을 가리키게 한다.
        ?int $mailMessageId = null,
    ): void {
        SubmittalEvent::create([
            'submittal_id' => $submittal->id,
            'kind' => $kind,
            'channel' => $channel,
            'to_name' => $toName,
            'to_email' => $toEmail,
            'subject' => $subject ? mb_substr($subject, 0, 250) : null,
            'intelligent_document_id' => $documentId,
            'mail_message_id' => $mailMessageId,
            'created_by' => $userId,
        ]);
    }
}
