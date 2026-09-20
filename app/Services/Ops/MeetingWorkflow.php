<?php

namespace App\Services\Ops;

use App\Models\OpsActionItem;
use App\Models\OpsIntakeBatch;
use App\Models\OpsIntakeItem;
use App\Models\OpsLaborReport;
use App\Models\OpsMeeting;
use App\Models\ProcurementItem;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MeetingWorkflow
{
    public function __construct(private readonly MeetingContext $context) {}

    public function persist(OpsMeeting $meeting, array $analysis, array $context): void
    {
        DB::transaction(function () use ($meeting, $analysis, $context) {
            $m = OpsMeeting::lockForUpdate()->findOrFail($meeting->id);
            if ($m->ops_intake_batch_id) {
                return;
            }
            $batch = OpsIntakeBatch::create(['site_id' => $m->site_id, 'created_by_id' => $m->created_by_id, 'source' => 'meeting',
                'raw_text' => $m->transcripts['scribe']['text'], 'status' => 'done', 'analyzed_at' => now(), 'parsed_count' => count($analysis['items']), 'actionable_count' => count($analysis['items'])]);
            foreach ($analysis['items'] as $raw) {
                $this->validateItem($raw);
                $ref = $raw['target_ref'] ?? '';
                $target = $context['targets'][$ref] ?? null;
                $candidates = array_values(array_unique(array_filter($raw['candidate_refs'] ?? [], fn ($v) => is_string($v) && isset($context['targets'][$v]))));
                if ($target && ! in_array($ref, $candidates, true)) {
                    $candidates[] = $ref;
                }
                $evidence = $this->evidence($m, $raw);
                $question = trim($raw['question'] ?? '');
                if (array_filter($raw['candidate_refs'] ?? [], fn ($v) => ! isset($context['targets'][$v]))) {
                    $question = 'ERP에서 확인되지 않는 후보가 포함되어 있습니다. 대상을 확인해 주세요.';
                }
                if (! $evidence['verified']) {
                    $question = $question ?: '두 받아쓰기의 근거가 일치하는지 원음을 확인해 주세요.';
                }
                if ($context['truncated']) {
                    $question = '현장 후보가 일부만 조회되었습니다. 대상을 직접 확인해 주세요.';
                }
                if (count($candidates) > 1) {
                    $question = $question ?: '어느 자재/작업인지 대상을 선택해 주세요.';
                }
                if (! $target && in_array($raw['kind'], ['lookup', 'progress', 'plan', 'procurement', 'inspection'], true)) {
                    $question = $question ?: '대상 자재/공정을 선택해 주세요.';
                }
                if ($raw['uncertain'] || ! empty($raw['condition'])) {
                    $question = $question ?: '발언의 의미·선행조건을 확인해 주세요.';
                }
                $kind = $raw['kind'];
                $category = $kind === 'lookup' ? 'procurement' : ($kind === 'decision' ? 'decision' : $kind);
                $meta = $raw + ['version' => 1];
                $meta['target_snapshot'] = $target;
                $meta['candidate_refs'] = $candidates;
                $meta['evidence'] = $evidence;
                $meta['meeting_id'] = $m->id;
                $meta['history'] = [];
                OpsIntakeItem::create(['site_id' => $m->site_id, 'ops_intake_batch_id' => $batch->id, 'created_by_id' => $m->created_by_id,
                    'source' => 'meeting', 'raw_text' => $raw['quote_scribe'], 'occurred_on' => $m->meeting_on, 'category' => $category,
                    'summary' => $raw['title'], 'proposed' => $raw['changes'], 'meeting_meta' => $meta, 'question' => $question ?: null,
                    'status' => $question !== '' ? 'needs_input' : 'pending', 'target_name' => $target['name'] ?? null,
                    'target_type' => $this->type($ref), 'target_code' => $target['code'] ?? $target['wbs_code'] ?? null]);
            }
            $m->update(['ops_intake_batch_id' => $batch->id, 'analysis' => ['summary' => $analysis['summary'], 'context_truncated' => $context['truncated']], 'status' => 'review', 'finished_at' => now(), 'error' => null]);
        });
        $meeting->refresh();
        foreach ($meeting->batch->items as $item) {
            if (in_array($item->meeting_meta['kind'], ['lookup', 'request'], true) && $item->status === 'pending') {
                try {
                    $this->apply($item->id, $meeting->creator, false);
                } catch (\Throwable $e) {
                    $item->update(['status' => 'needs_input', 'question' => '자동 처리하지 못했습니다. 대상을 확인하고 다시 반영해 주세요.']);
                    report($e);
                }
            }
        }
        $this->restamp($meeting);
    }

    public function apply(int $id, User $actor, bool $confirmed): array
    {
        return DB::transaction(function () use ($id, $actor, $confirmed) {
            $item = OpsIntakeItem::lockForUpdate()->findOrFail($id);
            abort_unless($item->source === 'meeting', 404);
            MeetingAccess::check($actor, $item->site_id);
            $meta = $item->meeting_meta;
            $meeting = OpsMeeting::findOrFail($meta['meeting_id']);
            if ($item->status === 'applied') {
                return ['success' => true, 'already_applied' => true];
            }
            if (! in_array($item->status, ['pending', 'needs_input'], true)) {
                return ['success' => false, 'error' => '처리할 수 없는 상태입니다.'];
            }
            if (! $confirmed && ($item->question || ! ($meta['evidence']['verified'] ?? false) || ! config('meetings.auto_internal_tasks'))) {
                return ['success' => false, 'error' => '사람 확인이 필요합니다.'];
            }
            // No approvals, money movement, outbound communication, or hold release from meeting text.
            if (($meta['requires_approval'] ?? false) || in_array($meta['kind'], ['expense', 'decision'], true)) {
                return ['success' => false, 'error' => '해당 업무의 원래 승인 화면에서 처리해야 합니다. 회의만으로 승인·지급·발송하지 않습니다.'];
            }
            $ref = $meta['target_ref'] ?? '';
            $target = $ref ? $this->context->model($ref, $item->site_id) : null;
            if ($ref && ! $target) {
                return ['success' => false, 'error' => '대상이 삭제되었거나 현장이 다릅니다. 다시 선택해 주세요.'];
            }
            if ($meta['kind'] === 'lookup') {
                if (! $confirmed && ! empty($meta['condition'])) {
                    return ['success' => false, 'error' => '선행조건 확인이 필요합니다.'];
                }
                if (! $target) {
                    return ['success' => false, 'error' => '조회할 자재를 선택해 주세요.'];
                }
                $result = $this->lookup($item, $target, $meta);
            } elseif (in_array($meta['kind'], ['request', 'issue'], true)) {
                if (! $confirmed && $meta['kind'] !== 'request') {
                    return ['success' => false, 'error' => '확인 후 요청을 등록해 주세요.'];
                }
                if (! $confirmed && (! empty($meta['condition']) || empty($meta['assignee']))) {
                    return ['success' => false, 'error' => '담당자·선행조건 확인이 필요합니다.'];
                }
                $result = $this->task($item, $meta);
            } elseif ($meta['kind'] === 'labor') {
                if (! $confirmed) {
                    return ['success' => false, 'error' => '실제 출역 보고인지 확인이 필요합니다.'];
                }
                $label = trim($meta['company'] ?? '');
                $count = (int) ($meta['headcount'] ?? 0);
                Site::whereKey($item->site_id)->lockForUpdate()->firstOrFail();
                if ($label === '' || $count < 1 || $count > 1000) {
                    return ['success' => false, 'error' => '업체명과 실제 출역 인원을 확인해 주세요.'];
                }
                if (OpsLaborReport::where('site_id', $item->site_id)->whereDate('work_date', $meeting->meeting_on)->where('company_label', $label)->exists()) {
                    return ['success' => false, 'error' => '동일 업체의 출역 보고가 이미 있습니다. 출역 화면에서 기존 보고와 대조해 주세요.'];
                }
                $r = OpsLaborReport::create(['site_id' => $item->site_id, 'work_date' => $meeting->meeting_on, 'company_label' => $label,
                    'headcount' => $count, 'note' => $item->summary, 'ops_intake_batch_id' => $item->ops_intake_batch_id,
                    'ops_intake_item_id' => $item->id, 'reported_by_id' => $actor->id]);
                $result = ['success' => true, 'labor_id' => $r->id, 'note' => '출역 보고 등록 — 근태·급여에는 반영하지 않음'];
            } else {
                if (! $confirmed) {
                    return ['success' => false, 'error' => '운영 변경은 확인 후 반영합니다.'];
                }
                if (! empty($meta['condition'])) {
                    return ['success' => false, 'error' => '선행조건이 있는 변경입니다. 조건을 확인하고 공정 화면에서 처리해 주세요.'];
                }
                if (! $target) {
                    return ['success' => false, 'error' => '변경 대상을 선택해 주세요.'];
                }
                $fresh = $this->context->describe(substr($ref, 0, 1), $target);
                if (! $this->sameSnapshot($fresh, $meta['target_snapshot'] ?? [])) {
                    return ['success' => false, 'error' => '회의 분석 후 대상 정보가 변경되었습니다. 대상 재선택으로 최신 값을 확인해 주세요.'];
                }
                $this->validateChanges($meta['kind'], $ref, $item->proposed ?? []);
                if ($meta['kind'] === 'plan' && ($item->proposed['planned_start'] ?? $fresh['planned_start']) && ($item->proposed['planned_end'] ?? $fresh['planned_end']) && ($item->proposed['planned_start'] ?? $fresh['planned_start']) > ($item->proposed['planned_end'] ?? $fresh['planned_end'])) {
                    return ['success' => false, 'error' => '기존 일정과 합치면 종료일이 시작일보다 빠릅니다.'];
                }
                if ($meta['kind'] === 'progress' && (int) ($item->proposed['progress'] ?? 0) === 100 && $target->hold_point && ! $target->hold_released) {
                    return ['success' => false, 'error' => '검사 홀드포인트가 해제되지 않아 완료 진척을 반영할 수 없습니다.'];
                }
                if ($meta['kind'] === 'procurement') {
                    if (isset($item->proposed['status']) && $item->proposed['status'] !== '발주대기' && ! $target->ordered_on && empty($item->proposed['ordered_on'])) {
                        return ['success' => false, 'error' => '발주일이 비어 있습니다. 실제 발주일을 확인해 함께 입력하세요. 분석일을 발주일로 추정하지 않습니다.'];
                    }
                    if (! $target->po_no || ProcurementItem::where('site_id', $item->site_id)->where('po_no', $target->po_no)->count() !== 1) {
                        return ['success' => false, 'error' => 'PO 번호가 없거나 중복되어 있습니다. 조달 화면에서 대상을 정리해 주세요.'];
                    }
                }
                if ($meta['kind'] === 'inspection' && Submittal::where('site_id', $item->site_id)->where('seq', $target->seq)->count() !== 1) {
                    return ['success' => false, 'error' => '검사 번호가 중복됩니다. 제출물 대장에서 확인해 주세요.'];
                }
                $result = app(OpsIntakeService::class)->apply($item->id, $item->proposed, $actor->id, 'meeting_review');
                $item->refresh();
                if ($result['success'] ?? false) {
                    $meta['after_snapshot'] = $this->context->describe(substr($ref, 0, 1), $target->fresh());
                }
            }
            if (! ($result['success'] ?? false)) {
                return $result;
            }
            $meta['result'] = $result;
            $meta['history'][] = ['event' => $confirmed ? 'confirmed' : 'auto', 'by' => $actor->id, 'at' => now()->toIso8601String()];
            $item->update(['meeting_meta' => $meta, 'status' => 'applied', 'question' => null, 'applied_at' => now(), 'applied_by_id' => $actor->id,
                'applied_via' => $confirmed ? 'meeting_review' : 'auto', 'result_note' => mb_substr($result['note'] ?? $item->result_note ?? '반영 완료', 0, 300)]);
            $this->restamp($meeting);

            return $result;
        });
    }

    private function lookup(OpsIntakeItem $item, $target, array &$meta): array
    {
        if (str_starts_with($meta['target_ref'], 'P:')) {
            $data = $this->context->describe('P', $target);
            $note = 'ERP 기록: '.($data['status'] ?: '상태 미등록').' / 납품 예정일: '.($data['eta'] ?: '미등록');
            $note .= ' (공급업체 확정 여부·실제 입고는 별도 확인)';
            $need = $data['eta'] === null || $data['status'] === '발주대기' || ($data['status'] !== '입고완료' && $data['eta'] < now()->toDateString());
            $impact = $data['eta'] && $data['need_by'] && $data['eta'] > $data['need_by'] ? '등록된 필요일보다 납품 예정일이 늦습니다.' : '설치 일정은 변경하지 않았습니다.';
            $task = $need ? $this->task($item, $meta, '납기·발주 확인: '.$data['name']) : [];

            return ['success' => true, 'note' => $note, 'lookup' => $data, 'impact' => $impact, 'follow_up' => $task];
        }
        $data = $this->context->describe(substr($meta['target_ref'], 0, 1), $target);

        return ['success' => true, 'note' => '자재/공정 자료를 찾았습니다. 연결된 발주·입고 내역은 미확인입니다.', 'lookup' => $data,
            'follow_up' => $this->task($item, $meta, '발주·납기 연결 확인: '.$data['name'])];
    }

    private function task(OpsIntakeItem $item, array $meta, ?string $title = null): array
    {
        // Serialize per site so separate meetings cannot create the same open task concurrently.
        Site::whereKey($item->site_id)->lockForUpdate()->firstOrFail();
        $title = mb_substr($title ?: $item->summary, 0, 255);
        $assignee = trim($meta['assignee'] ?? '') ?: null;
        $due = trim($meta['due_on'] ?? '') ?: null;
        $existingQuery = OpsActionItem::where('site_id', $item->site_id)->where('status', 'open');
        if (($meta['kind'] ?? '') === 'lookup' && ! empty($meta['target_ref'])) {
            // The same material ETA question remains one open follow-up across meetings.
            $ids = OpsIntakeItem::where('site_id', $item->site_id)->where('source', 'meeting')
                ->where('meeting_meta->kind', 'lookup')->where('meeting_meta->target_ref', $meta['target_ref'])->select('id');
            $existingQuery->whereIn('ops_intake_item_id', $ids);
        } else {
            $existingQuery->where('title', $title)->where('assignee', $assignee)->where('due_on', $due);
        }
        $existing = $existingQuery->first();
        if ($existing) {
            return ['success' => true, 'action_id' => $existing->id, 'created' => false, 'note' => '기존 미완료 업무에 연결했습니다.'];
        }
        $action = OpsActionItem::create(['site_id' => $item->site_id, 'ops_intake_batch_id' => $item->ops_intake_batch_id, 'ops_intake_item_id' => $item->id,
            'kind' => 'request', 'title' => $title, 'detail' => $item->raw_text.(! empty($meta['condition']) ? "\n선행조건: ".$meta['condition'] : ''),
            'assignee' => $assignee, 'due_on' => $due, 'occurred_on' => $item->occurred_on, 'is_blocker' => (bool) $meta['blocker'], 'status' => 'open']);

        return ['success' => true, 'action_id' => $action->id, 'created' => true, 'action_snapshot' => $action->only(['title', 'detail', 'assignee', 'status', 'due_on', 'is_blocker']), 'note' => '상황실 할 일 등록'.($assignee ? '' : ' — 담당자 지정 필요')];
    }

    public function restamp(OpsMeeting $meeting): void
    {
        if (! $meeting->ops_intake_batch_id) {
            return;
        }
        $pending = OpsIntakeItem::where('ops_intake_batch_id', $meeting->ops_intake_batch_id)->whereIn('status', ['pending', 'needs_input', 'applying'])->count();
        $meeting->update(['status' => $pending ? 'review' : 'completed']);
        UnifiedAlert::where('fingerprint', 'meeting:'.$meeting->id)->update([
            'title' => '공정미팅 분석 완료 · 확인 '.$pending.'건',
            'status' => $pending ? 'unresolved' : 'completed', 'resolved_at' => $pending ? null : now(),
        ]);
    }

    public function notify(OpsMeeting $meeting): void
    {
        $meeting->refresh();
        $creator = $meeting->creator->fresh();
        if (! MeetingAccess::allowed($creator) || ! MeetingAccess::sites($creator)->whereKey($meeting->site_id)->exists()) {
            return;
        }
        $count = $meeting->ops_intake_batch_id ? $meeting->batch->items()->whereIn('status', ['pending', 'needs_input'])->count() : 0;
        // Existing ERP alert center only. No push, email or chat transmission.
        app(UnifiedAlertService::class)->emit('meeting:'.$meeting->id, [
            'company_id' => $meeting->site->company_id, 'site_id' => $meeting->site_id, 'user_id' => $creator->id,
            'source_module' => 'OPS', 'source_type' => OpsMeeting::class, 'source_id' => (string) $meeting->id,
            'event_type' => 'meeting_result', 'severity' => $meeting->status === 'failed' ? 'warning' : 'info', 'status' => 'unresolved',
            'title' => $meeting->status === 'failed' ? '공정미팅 분석 재시도 필요' : '공정미팅 분석 완료 · 확인 '.$count.'건',
            'content' => '회의 화면에서 결과와 원음 근거를 확인하세요.',
            'action_url' => '/?view=meetings&detail=meeting&code='.$meeting->id.'&site='.$meeting->site_id,
        ]);
    }

    public function undo(int $id, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $i = OpsIntakeItem::lockForUpdate()->findOrFail($id);
            MeetingAccess::check($actor, $i->site_id);
            abort_unless($i->source === 'meeting' && $i->status === 'applied', 409);
            $meta = $i->meeting_meta;
            $result = $meta['result'] ?? [];
            $task = $result['follow_up'] ?? $result;
            if (! empty($task['action_id']) && ! empty($task['created'])) {
                $a = OpsActionItem::lockForUpdate()->find($task['action_id']);
                $reused = OpsIntakeItem::where('id', '!=', $i->id)->where('site_id', $i->site_id)->where('status', 'applied')
                    ->where(fn ($q) => $q->where('meeting_meta->result->follow_up->action_id', $task['action_id'])->orWhere('meeting_meta->result->action_id', $task['action_id']))->exists();
                if ($a && ($reused || (int) $a->ops_intake_item_id !== $i->id || $a->status !== 'open' || ! $this->sameSnapshot($a->only(['title', 'detail', 'assignee', 'status', 'due_on', 'is_blocker']), $task['action_snapshot'] ?? []))) {
                    return ['success' => false, 'error' => '업무가 이미 수정·처리되었습니다. 해당 업무 화면에서 정정해 주세요.'];
                }
                $a?->delete();
            } elseif (! empty($result['labor_id'])) {
                $a = OpsLaborReport::lockForUpdate()->find($result['labor_id']);
                if ($a && ((int) $a->ops_intake_item_id !== $i->id || (int) $a->headcount !== (int) $meta['headcount'])) {
                    return ['success' => false, 'error' => '출역 보고가 변경되어 자동 취소할 수 없습니다.'];
                }
                $a?->delete();
            } elseif (! empty($meta['after_snapshot'])) {
                if (($result['cpm']['movedCount'] ?? 0) > 0) {
                    return ['success' => false, 'error' => '후속 공정 일정도 함께 변경되었습니다. 공정표에서 영향 범위를 확인하고 정정해 주세요.'];
                }
                $ref = $meta['target_ref'];
                $target = $this->context->model($ref, $i->site_id);
                if (! $target || ! $this->sameSnapshot($this->context->describe(substr($ref, 0, 1), $target), $meta['after_snapshot'])) {
                    return ['success' => false, 'error' => '반영 후 대상이 변경되었습니다. 최신 내용을 덮어쓰지 않고 보류합니다.'];
                }
                if (! str_starts_with($ref, 'S:') && (in_array(null, $i->previous ?? [], true) || in_array('', $i->previous ?? [], true))) {
                    return ['success' => false, 'error' => '원래 비어 있던 값입니다. 해당 모듈에서 빈 값으로 정정해 주세요. 변경 이력은 보존됩니다.'];
                }
                $r = app(OpsIntakeService::class)->revert($i->id, $actor->id, 'meeting_review');
                if (! ($r['success'] ?? false)) {
                    return $r;
                }
            }
            $meta['history'][] = ['event' => 'undo', 'by' => $actor->id, 'at' => now()->toIso8601String(), 'reason' => $reason];
            $i->update(['status' => 'dismissed', 'meeting_meta' => $meta, 'applied_at' => null, 'result_note' => '반영 취소: '.$reason]);
            $this->restamp(OpsMeeting::findOrFail($meta['meeting_id']));

            return ['success' => true];
        });
    }

    public function evidence(OpsMeeting $m, array $raw): array
    {
        $g = (string) ($raw['quote_gemini'] ?? '');
        $s = (string) ($raw['quote_scribe'] ?? '');
        $normalize = fn ($x) => preg_replace('/[\p{P}\p{Z}\s]+/u', '', mb_strtolower($x));
        $verified = mb_strlen($normalize($g)) >= 4 && mb_strlen($normalize($s)) >= 4
            && str_contains($normalize($m->transcripts['gemini']['text'] ?? ''), $normalize($g))
            && str_contains($normalize($m->transcripts['scribe']['text'] ?? ''), $normalize($s));
        // Locate the actual Scribe quote in timestamped words; AI never supplies trusted timestamps.
        $words = $m->transcripts['scribe']['segments'] ?? [];
        $joined = '';
        $offsets = [];
        foreach ($words as $w) {
            $offsets[] = ['offset' => strlen($joined), 'start' => $w['start'], 'end' => $w['end']];
            $joined .= $normalize($w['text']);
        }
        $needle = $normalize($s);
        $pos = $needle !== '' ? strpos($joined, $needle) : false;
        $start = null;
        $end = null;
        if ($pos !== false) {
            foreach ($offsets as $w) {
                if ($w['offset'] <= $pos) {
                    $start = $w['start'];
                } if ($w['offset'] < $pos + strlen($needle)) {
                    $end = $w['end'];
                }
            }
        }

        return ['verified' => $verified, 'start' => $start, 'end' => $end, 'quote_gemini' => $g, 'quote_scribe' => $s];
    }

    public function validateItem(array $r): void
    {
        Validator::make($r, ['kind' => 'required|in:lookup,progress,plan,procurement,labor,inspection,request,issue,expense,decision',
            'title' => 'required|string|max:300', 'target_ref' => 'present|string|max:40', 'candidate_refs' => 'present|array|max:30',
            'candidate_refs.*' => 'string|max:40', 'quote_gemini' => 'present|string|max:4000', 'quote_scribe' => 'present|string|max:4000',
            'uncertain' => 'required|boolean', 'question' => 'present|string|max:1000', 'condition' => 'present|string|max:2000',
            'requires_approval' => 'required|boolean', 'assignee' => 'present|string|max:120', 'due_on' => 'nullable|date_format:Y-m-d',
            'blocker' => 'required|boolean', 'changes' => 'present|array', 'company' => 'present|string|max:150', 'headcount' => 'required|integer|min:0|max:1000'])->validate();
        if ($r['kind'] === 'lookup' && $r['changes'] !== []) {
            throw ValidationException::withMessages(['changes' => '조회 요청에는 변경 값을 넣을 수 없습니다.']);
        }
    }

    public function validateChanges(string $kind, string $ref, array $changes): void
    {
        [$prefix, $rules] = match ($kind) {
            'progress' => ['W:', ['progress' => 'required|integer|min:0|max:100']],
            'plan' => ['W:', ['planned_start' => 'sometimes|date_format:Y-m-d', 'planned_end' => 'sometimes|date_format:Y-m-d', 'crew_size' => 'sometimes|integer|min:1|max:1000']],
            'procurement' => ['P:', ['eta' => 'sometimes|date_format:Y-m-d', 'ordered_on' => 'sometimes|date_format:Y-m-d', 'status' => 'sometimes|in:발주대기,발주완료,생산중,선적중,통관중,입고완료']],
            'inspection' => ['S:', ['planned_on' => 'required|date_format:Y-m-d']],
            default => ['', []],
        };
        if ($prefix === '' || ! str_starts_with($ref, $prefix) || $changes === [] || array_diff(array_keys($changes), array_keys($rules))) {
            throw ValidationException::withMessages(['changes' => '해당 대상에 반영할 수 없는 변경입니다.']);
        }
        Validator::make($changes, $rules)->validate();
        if (isset($changes['planned_start'], $changes['planned_end']) && $changes['planned_start'] > $changes['planned_end']) {
            throw ValidationException::withMessages(['changes' => '종료일이 시작일보다 빠릅니다.']);
        }
    }

    public function type(string $ref): ?string
    {
        return ['P' => 'procurement', 'W' => 'wbs', 'S' => 'submittal', 'B' => 'boq'][substr($ref, 0, 1)] ?? null;
    }

    private function sameSnapshot(array $a, array $b): bool
    {
        // PostgreSQL JSONB reorders keys. Compare values canonically, keeping null distinct from empty text.
        return json_encode(Arr::sortRecursive($a)) === json_encode(Arr::sortRecursive($b));
    }
}
