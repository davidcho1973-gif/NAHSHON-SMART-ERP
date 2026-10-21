<?php

namespace App\Http\Controllers;

use App\Models\AuthEvent;
use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\Team;
use App\Models\WorkSection;
use App\Services\Admin\UserAccessService;
use App\Services\Documents\DocumentIntake;
use App\Services\Finance\ClaimEvidenceService;
use App\Support\FieldClaimAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FieldClaimController extends Controller
{
    public function index()
    {
        FieldClaimAccess::sections();

        return response()->view('field-claims.index')->header('Cache-Control', 'private, no-store');
    }

    public function data()
    {
        $sections = FieldClaimAccess::sections()->orderBy('sort_order')->get();
        $lines = ContractBoqLine::whereIn('work_section_id', $sections->modelKeys())->where('status', 'accepted')->get();
        $records = ClaimWorkRecord::whereIn('contract_boq_line_id', $lines->modelKeys())
            ->where('reported_by', auth()->id())->where('record_kind', 'actual')->orderByDesc('id')->get();
        $attachments = DB::table('field_claim_attachments')->whereIn('contract_boq_line_id', $lines->modelKeys())
            ->where('user_id', auth()->id())->get();

        return response()->json(['success' => true, 'sections' => $sections->map(fn ($s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name,
            'lines' => $lines->where('work_section_id', $s->id)->map(fn ($l) => [
                'id' => $l->id, 'lineNo' => $l->line_no, 'description' => $l->description,
                'unit' => $l->unit, 'quantity' => (float) $l->contract_qty,
                'stages' => $l->recognition_basis === 'milestone' ? array_keys($l->stage_weights ?? []) : ['installed'],
                'records' => $records->where('contract_boq_line_id', $l->id)->map(fn ($r) => [
                    'id' => $r->id, 'revision' => (int) $r->revision, 'workDate' => $r->work_date?->toDateString(), 'location' => $r->location,
                    'stage' => $r->stage, 'reportedQty' => (float) $r->reported_qty, 'status' => $r->status,
                    'notes' => $r->notes, 'evidenceCount' => count($r->evidence ?? []),
                    'verifiedQty' => $r->verified_qty === null ? null : (float) $r->verified_qty, 'reviewNote' => $r->review_note,
                    'evidence' => collect($r->evidence ?? [])->map(function ($e) use ($attachments, $l) {
                        $a = $attachments->where('contract_boq_line_id', $l->id)->firstWhere('intelligent_document_id', $e['id'] ?? 0);

                        return $a ? ['attachmentId' => $a->id, 'locator' => $e['locator'] ?? '문서 전체'] : null;
                    })->filter()->values()->all(),
                ])->values()->all(),
                'attachments' => $attachments->where('contract_boq_line_id', $l->id)->map(fn ($a) => [
                    'id' => $a->id, 'name' => $a->original_name,
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all()])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, int $line, ClaimEvidenceService $ledger)
    {
        $model = FieldClaimAccess::line($line, true);
        abort_unless($model->status === 'accepted', 422, '확정된 공정만 기록할 수 있습니다.');
        $input = $request->validate([
            'requestId' => ['nullable', 'uuid'], 'expectedRevision' => ['nullable', 'integer', 'min:1'],
            'id' => ['nullable', 'integer'], 'workDate' => ['required', 'date_format:Y-m-d'],
            'location' => ['required', 'string', 'max:255'], 'stage' => ['required', 'string', 'max:60'],
            'reportedQty' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:10000'],
            'evidence' => ['present', 'array', 'max:20'], 'evidence.*.attachmentId' => ['required', 'integer'],
            'evidence.*.locator' => ['required', 'string', 'max:255'],
        ]);
        if (! empty($input['id'])) {
            $record = ClaimWorkRecord::where('contract_boq_line_id', $line)->findOrFail($input['id']);
            FieldClaimAccess::editable($record);
            // Never silently discard a reviewer's additional proof during a field edit.
            foreach ($record->evidence ?? [] as $proof) {
                abort_unless(DB::table('field_claim_attachments')->where('contract_boq_line_id', $line)
                    ->where('user_id', auth()->id())->where('intelligent_document_id', $proof['id'] ?? 0)->exists(), 409,
                    '검토자가 증빙을 추가한 기록은 관리자에게 수정 요청하세요.');
            }
        } else {
            unset($input['id']);
        }
        $result = $ledger->saveRecord($input + ['lineId' => $line, 'recordKind' => 'actual']);

        return response()->json(($result['success'] ?? false)
            ? ['success' => true, 'id' => $result['id'], 'status' => 'pending']
            : ['success' => false, 'error' => $result['error'] ?? '저장하지 못했습니다.'], ($result['success'] ?? false) ? 200 : 422);
    }

    public function upload(Request $request, int $line, DocumentIntake $intake)
    {
        $model = FieldClaimAccess::line($line, true);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx']]);
        $contract = $model->contract;
        $result = $intake->ingest($request->file('file'), [
            'company_id' => $contract->company_id, 'site_id' => $contract->site_id, 'project_id' => $contract->project_id,
        ], ['source' => 'evidence_batch', 'uploaded_by' => auth()->id(), 'project_contract_id' => $contract->id]);
        $doc = $result['document'] ?? null;
        abort_unless($doc, 422, '파일을 접수하지 못했습니다. 원본을 보관하고 다시 시도하세요.');
        $key = ['contract_boq_line_id' => $line, 'user_id' => auth()->id(), 'intelligent_document_id' => $doc->id];
        DB::table('field_claim_attachments')->updateOrInsert($key, [
            'sha256' => $doc->sha256, 'original_name' => mb_substr(basename($request->file('file')->getClientOriginalName()), 0, 255),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attachment = DB::table('field_claim_attachments')->where($key)->first();

        return response()->json(['success' => true, 'attachment' => ['id' => $attachment->id, 'name' => $attachment->original_name]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request, int $section)
    {
        abort_unless(app(UserAccessService::class)->canManagePurchasingGrants(), 403);
        $input = $request->validate(['trade' => ['nullable', 'string', 'max:255']]);
        $model = WorkSection::findOrFail($section);
        $trade = trim($input['trade'] ?? '') ?: null;
        abort_if($trade && ! Team::where('company_id', $model->company_id)->where('site_id', $model->site_id)->where('trade_type', $trade)->exists(), 422,
            '이 현장에 등록된 담당 공종을 선택하세요.');
        DB::transaction(function () use ($model, $trade, $request): void {
            $model = WorkSection::whereKey($model->id)->lockForUpdate()->firstOrFail();
            $before = $model->claim_trade;
            $model->forceFill(['claim_trade' => $trade])->save();
            AuthEvent::record('field_claim_assignment_changed', user: auth()->user(), actor: auth()->user(), method: 'erp', request: $request,
                note: json_encode(['sectionId' => $model->id, 'before' => $before, 'after' => $trade], JSON_UNESCAPED_UNICODE));
        });

        return response()->json(['success' => true]);
    }
}
