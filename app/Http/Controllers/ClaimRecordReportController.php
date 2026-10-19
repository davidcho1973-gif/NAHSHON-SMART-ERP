<?php

namespace App\Http\Controllers;

use App\Mail\ReportMail;
use App\Services\Finance\ClaimRecordReport;
use App\Services\Mail\OutboundMailer;
use App\Support\MailReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimRecordReportController extends Controller
{
    public function export(Request $request, int $line, string $format, ClaimRecordReport $reports)
    {
        $s = $reports->snapshot($line, (int) $request->query('revision'));
        if ($format === 'html') {
            return response($reports->html($s))->header('Cache-Control', 'private, no-store');
        }
        $f = $reports->file($s, $format);

        return response($f['data'])->header('Content-Type', $f['mime'])->header('Content-Disposition', 'attachment; filename="'.$f['name'].'"')->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, int $line, ClaimRecordReport $reports, OutboundMailer $outbound)
    {
        $v = $request->validate(['revision' => 'required|integer|min:1', 'requestId' => 'required|uuid',
            'to' => 'required|email:rfc|max:254', 'subject' => 'required|string|max:200|not_regex:/[\r\n]/',
            'body' => 'present|nullable|string|max:3500', 'format' => 'required|in:docx,xlsx', 'confirmed' => 'accepted']);
        $s = $reports->snapshot($line, $v['revision']);
        abort_unless(MailReady::ok(), 422, '메일 발송 설정이 필요합니다. 파일을 내려받아 별도로 전달하세요.');
        $file = $reports->file($s, $v['format']);
        abort_if(strlen($file['data']) > MailReady::attachmentBudget(), 422, '메일 첨부 한도를 넘었습니다.');
        $hash = hash('sha256', json_encode([$line, $request->user()->id, $v['revision'], $v['to'], $v['subject'], $v['body'], $v['format']]));
        $inserted = DB::table('claim_record_dispatches')->insertOrIgnore(['id' => $v['requestId'], 'request_hash' => $hash,
            'user_id' => $request->user()->id, 'claim_edit_draft_id' => $s['draft']->id, 'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);
        if (! $inserted) {
            $prior = DB::table('claim_record_dispatches')->where('id', $v['requestId'])->first();
            abort_unless($prior->request_hash === $hash, 409, '전송 요청이 변경되었습니다. 미리보기를 다시 만드세요.');
            abort_unless($prior->status === 'sent', 409, '발송 결과를 서신 원장에서 확인하세요. 중복 발송하지 않았습니다.');

            return response()->json(['success' => true, 'duplicate' => true]);
        }
        try {
            $contract = $s['line']->contract;
            $thread = $outbound->threadFor($s['draft'], $v['subject'], $v['to'], siteId: $contract->site_id, companyId: $contract->company_id, projectId: $contract->project_id);
            $html = str_replace('<h1>', '<div style="white-space:pre-wrap">'.e($v['body'] ?? '').'</div><h1>', $reports->html($s));
            $result = $outbound->send($thread, [['email' => $v['to']]], [], $v['subject'], $html, ($v['body'] ?? '')."\n".$s['title'].' · '.$s['status'],
                fn ($id, $refs) => new ReportMail($v['subject'], $html, [$file], messageId: $id, references: $refs), attachmentCount: 1);
            DB::table('claim_record_dispatches')->where('id', $v['requestId'])->update(['status' => ($result['sent'] ?? 0) > 0 ? 'sent' : 'uncertain', 'mail_message_id' => $result['messageId'] ?? null, 'updated_at' => now()]);

            return response()->json($result, ($result['sent'] ?? 0) > 0 ? 200 : 502);
        } catch (\Throwable $e) {
            report($e);
            DB::table('claim_record_dispatches')->where('id', $v['requestId'])->update(['status' => 'uncertain', 'updated_at' => now()]);

            return response()->json(['message' => '발송 결과를 확인하지 못했습니다. 서신 원장을 확인하세요. 자동 재전송하지 않습니다.'], 502);
        }
    }
}
