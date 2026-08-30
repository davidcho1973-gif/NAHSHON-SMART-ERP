<?php

namespace App\Services\Takeoff;

use App\Models\IntelligentDocument;
use App\Models\Submittal;
use App\Services\Ocr\OcrEngine;
use App\Support\AiMeter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 제출물 조항 하나로 <b>업체에 보낼 자료 요청서</b>를 쓰고 문서함에 편철한다.
 *
 * 왜 이것부터인가 — 제품자료·제작도·시험성적서는 AI 가 만들 수 없다(제조사·시험기관이
 * 발급한다. 지어내면 허위 서류다). 하지만 <b>"이 조항 때문에 이런 자료가 필요하다"</b>
 * 고 업체에 요청하는 일은 사람이 조항을 읽고 매번 손으로 쓴다. 703K 기준 276건이면
 * 276번이다. 그 편지를 대신 쓴다.
 *
 * 요청서가 갖춰야 하는 것은 셋이다:
 *  1. <b>무엇을</b> — 조항이 요구한 항목을 빠짐없이 낱개로(하나라도 빠지면 반려된다)
 *  2. <b>왜</b> — 시방 조항 번호. 근거 없는 요청은 업체가 미룬다
 *  3. <b>언제까지</b> — 특히 정지 조항이면 그 사실을 못박는다("승인 전 발주 금지")
 */
class SubmittalRequestService
{
    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * @return array{success: bool, error?: string, documentId?: int, title?: string, items?: int}
     */
    public function build(Submittal $submittal, ?string $vendorName = null): array
    {
        if (! $submittal->project_id) {
            return ['success' => false, 'error' => '이 제출물에 프로젝트가 없어 문서함에 넣을 수 없습니다.'];
        }

        $startedAt = microtime(true);
        try {
            // 이미지는 없다 — 조항 글만 주고 편지를 받는다.
            $result = $this->engine->analyze([], $this->prompt($submittal, $vendorName), $this->schema());
        } catch (Throwable $e) {
            AiMeter::record($this->engine->name(), 'submittal_request', null,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                ok: false, error: $e->getMessage(), subjectType: 'submittal', subjectId: $submittal->id);

            return ['success' => false, 'error' => '요청서 작성 실패: '.$e->getMessage()];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $required = array_values(array_filter(
            is_array($data['required_items'] ?? null) ? $data['required_items'] : [],
            fn ($v): bool => is_string($v) && trim($v) !== '',
        ));

        if ($required === []) {
            return ['success' => false, 'error' => '조항에서 요청할 항목을 뽑지 못했습니다. 조항 내용을 확인해 주세요.'];
        }

        $html = $this->render($submittal, $data, $required, $vendorName);
        $document = $this->file($submittal, $html, $vendorName);

        return ['success' => true, 'documentId' => $document->id, 'title' => $document->title, 'items' => count($required)];
    }

    /** 만든 편지를 문서함에 넣는다 — 사람이 쓴 공문과 같은 서랍에 들어가야 나중에 찾는다. */
    private function file(Submittal $submittal, string $html, ?string $vendorName): IntelligentDocument
    {
        $uuid = (string) Str::uuid();
        $name = trim(($submittal->csi ?: '제출물').' 자료요청서'.($vendorName ? " ({$vendorName})" : '')).'.html';
        $disk = Storage::disk(config('document-intelligence.disk'));
        $path = "document-intelligence/inbox/{$uuid}/{$name}";
        $disk->put($path, $html);

        return IntelligentDocument::create([
            'uuid' => $uuid,
            'disk' => (string) config('document-intelligence.disk'),
            'company_id' => $submittal->company_id,
            'site_id' => $submittal->site_id,
            'project_id' => $submittal->project_id,
            'file_path' => $path,
            'original_file_name' => $name,
            'stored_file_name' => $name,
            'mime_type' => 'text/html',
            'extension' => 'html',
            'file_size' => strlen($html),
            'sha256' => hash('sha256', $html),
            'title' => trim(($submittal->csi ? "[{$submittal->csi}] " : '').($submittal->section ?: '제출물').' — 자료 요청서'),
            // 우리가 만든 문서라 AI 분류를 다시 돌릴 이유가 없다. 분류를 여기서 확정한다.
            'category' => 'rfi_submittal',
            'document_type' => 'transmittal',
            'direction' => 'outgoing',
            'discipline' => $submittal->section,
            'document_number' => $submittal->csi ? $submittal->csi.'-REQ-'.$submittal->seq : null,
            'recipients' => $vendorName ? [$vendorName] : [],
            'summary' => '제출물 #'.$submittal->seq.' 관련 업체 자료 요청서'.($submittal->gate ? ' (정지 조항 — 승인 전 진행 불가)' : ''),
            'ai_status' => 'ready',
            'ai_engine' => $this->engine->name(),
            'ai_confidence' => 95,
            'received_at' => now(),
            'analyzed_at' => now(),
            'document_date' => now()->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $required
     */
    private function render(Submittal $submittal, array $data, array $required, ?string $vendorName): string
    {
        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $org = \App\Support\Org::name();
        $today = now()->format('Y-m-d');
        $due = trim((string) ($data['due_note'] ?? ''));

        $lines = '';
        foreach ($required as $i => $item) {
            $lines .= '<li>'.$e($item).'</li>';
        }

        $gateBox = $submittal->gate
            ? '<div class="gate"><b>※ 이 항목은 시방 정지 조항입니다.</b><br>'
                .'승인 전에는 발주·시공을 진행할 수 없습니다. 회신이 늦어지면 공정 전체가 지연됩니다.'
                .($submittal->source_excerpt ? '<div class="quote">'.$e($submittal->source_excerpt).'</div>' : '')
                .'</div>'
            : '';

        return '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8">'
            .'<title>'.$e($submittal->csi).' 자료 요청서</title><style>'
            .'body{font-family:"Malgun Gothic","맑은 고딕",sans-serif;color:#1a1a1a;max-width:760px;margin:0 auto;padding:38px 30px;line-height:1.7}'
            .'h1{font-size:22px;margin:0 0 4px;letter-spacing:-.01em}.sub{color:#666;font-size:13px;margin:0 0 22px}'
            .'table.meta{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:22px}'
            .'table.meta td{border:1px solid #ddd;padding:8px 11px}table.meta td.k{background:#f6f7f9;width:120px;font-weight:700}'
            .'h2{font-size:15px;margin:24px 0 8px;padding-bottom:5px;border-bottom:2px solid #22303c}'
            .'ol{padding-left:22px}ol li{margin-bottom:7px}'
            .'.gate{border:1px solid #e5b4ae;background:#fdf3f2;border-radius:8px;padding:13px 15px;margin:16px 0;font-size:13.5px;color:#8c2f26}'
            .'.quote{margin-top:8px;padding:9px 11px;background:#fff;border-left:3px solid #c0574a;font-size:12.5px;color:#444;font-style:italic}'
            .'p{margin:0 0 12px;font-size:14px}.foot{margin-top:34px;padding-top:14px;border-top:1px solid #ddd;font-size:12.5px;color:#666}'
            .'.navbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e5e5;margin:-38px -30px 26px;padding:11px 30px;display:flex;gap:9px;align-items:center}'
            .'.navbar a,.navbar button{font:inherit;font-size:13px;padding:7px 14px;border-radius:8px;border:1px solid #d5d9de;background:#fff;color:#1a1a1a;text-decoration:none;cursor:pointer}'
            .'.navbar a.home{background:#22303c;border-color:#22303c;color:#fff}'
            // 인쇄할 때는 버튼 줄이 나오면 안 된다 — 업체에 보내는 서류다.
            .'@media print{.navbar{display:none}body{padding:0}}'
            .'</style></head><body>'
            // 이 문서는 ERP 밖에서 열리는 독립 파일이다 — 돌아갈 길을 안에 넣어 두지
            // 않으면 사용자가 막힌다(뒤로가기를 아는 사람만 빠져나온다).
            .'<div class="navbar">'
                .'<a class="home" href="/document-hub">← 문서함</a>'
                .'<button type="button" onclick="window.print()">🖨 인쇄 · PDF 저장</button>'
                .'<a href="/">ERP 홈</a>'
            .'</div>'
            .'<h1>제출자료 요청서</h1>'
            .'<p class="sub">Submittal Data Request · '.$e($org).'</p>'
            .'<table class="meta">'
            .'<tr><td class="k">수신</td><td>'.$e($vendorName ?: '(업체명)').'</td></tr>'
            .'<tr><td class="k">발신</td><td>'.$e($org).'</td></tr>'
            .'<tr><td class="k">일자</td><td>'.$e($today).'</td></tr>'
            .'<tr><td class="k">시방 조항</td><td>'.$e(trim($submittal->csi.' '.$submittal->section)).'</td></tr>'
            .'<tr><td class="k">제출물 번호</td><td>#'.$e($submittal->seq).'</td></tr>'
            .'</table>'
            .'<p>'.$e($data['opening'] ?? '아래 시방 조항에 따라 제출이 필요한 자료를 요청드립니다.').'</p>'
            .$gateBox
            .'<h2>요청 자료</h2><ol>'.$lines.'</ol>'
            .'<h2>근거 조항</h2><p style="font-size:13px;color:#444">'.$e($submittal->title).'</p>'
            .($due !== '' ? '<h2>회신 기한</h2><p>'.$e($due).'</p>' : '')
            .'<div class="foot">이 요청서는 시방 조항에서 자동 생성되었습니다. 자료를 받으시면 문서함에 올려 주세요 —'
            .' 요구 항목이 모두 담겼는지 대조해 드립니다.</div>'
            .'</body></html>';
    }

    private function prompt(Submittal $submittal, ?string $vendorName): string
    {
        return implode("\n", array_filter([
            '당신은 미국 건설현장의 제출물 관리 담당자입니다. 아래 시방 조항을 근거로',
            '<b>협력업체·제조사에 보낼 자료 요청서</b>의 내용을 작성하세요.',
            '',
            '## 시방 조항',
            'CSI: '.trim($submittal->csi.' '.$submittal->section),
            '구분: '.$submittal->category,
            '요구사항: '.$submittal->title,
            $submittal->source_excerpt ? '원문: '.$submittal->source_excerpt : null,
            $submittal->gate ? '※ 이 조항은 정지 조항입니다(승인 전 발주·시공 금지).' : null,
            $vendorName ? '수신 업체: '.$vendorName : null,
            '',
            '## 지켜야 할 것',
            '1. required_items 에 <b>업체가 보내야 할 자료를 낱개로</b> 나열하세요.',
            '   조항이 "코어 구성·금속 게이지·방향성 결 마감" 을 요구하면 세 줄로 나눕니다 —',
            '   묶어 쓰면 업체가 하나를 빠뜨리고, 그러면 제출물이 반려됩니다.',
            '2. 조항에 없는 자료를 요구하지 마세요. 근거 없는 요청은 업체가 미룹니다.',
            '3. opening 은 두세 문장. 무엇을 왜 요청하는지.',
            '4. due_note 는 회신 기한 안내. 정지 조항이면 <b>승인 전 발주가 불가하다는 사실</b>을',
            '   반드시 밝히고, 그래서 언제까지 회신이 필요한지 적으세요. 조항에 통보 기간이',
            '   있으면(예: 21일 전) 그것을 근거로 쓰세요.',
            '5. 한국어로 쓰되, 시방 용어와 제출물 명칭은 영문을 괄호로 병기하세요',
            '   (예: 제품자료(Product Data)). 업체가 영문 시방을 보고 찾아야 합니다.',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'opening' => ['type' => 'string'],
                'required_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'due_note' => ['type' => 'string'],
            ],
            'required' => ['opening', 'required_items', 'due_note'],
        ];
    }
}
