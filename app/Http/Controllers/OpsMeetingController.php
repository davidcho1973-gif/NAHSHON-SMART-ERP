<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOpsMeeting;
use App\Models\OpsActionItem;
use App\Models\OpsIntakeItem;
use App\Models\OpsMeeting;
use App\Models\Site;
use App\Services\Ops\MeetingAccess;
use App\Services\Ops\MeetingContext;
use App\Services\Ops\MeetingTranscriber;
use App\Services\Ops\MeetingWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OpsMeetingController extends Controller
{
    public function __construct(private MeetingWorkflow $workflow, private MeetingContext $context) {}

    public function index(Request $r)
    {
        abort_unless(MeetingAccess::allowed($r->user()), 403);
        $sites = MeetingAccess::sites($r->user())->get(['id', 'name', 'code']);
        $siteId = (int) $r->query('site_id', $sites->first()?->id);
        if ($siteId) {
            MeetingAccess::check($r->user(), $siteId);
        }
        $query = OpsMeeting::whereIn('site_id', $sites->pluck('id'))->when($siteId, fn ($q) => $q->where('site_id', $siteId));

        return response()->json(['success' => true, 'actor_id' => $r->user()->id, 'sites' => $sites, 'site_id' => $siteId,
            'providers' => app(MeetingTranscriber::class)->readiness(),
            'meetings' => $query->latest()->limit(50)->get(['id', 'title', 'meeting_on', 'site_id', 'status', 'error', 'queued_at', 'started_at', 'finished_at']),
            'next_meeting_tasks' => $siteId ? OpsActionItem::where('site_id', $siteId)->where('status', 'open')->orderByDesc('is_blocker')->orderBy('due_on')->limit(40)->get(['id', 'title', 'assignee', 'due_on', 'is_blocker']) : []]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['site_id' => 'required|integer', 'title' => 'required|string|max:160', 'meeting_on' => 'required|date_format:Y-m-d',
            'participants' => 'nullable|string|max:2000', 'upload_token' => 'required|uuid', 'audio_hash' => 'required|regex:/^[a-f0-9]{64}$/',
            'audio_bytes' => 'required|integer|min:1|max:'.config('meetings.max_bytes'),
            'audio_mime' => 'required|in:audio/webm,audio/ogg,audio/mpeg,audio/wav,audio/mp4,audio/m4a,audio/flac']);
        MeetingAccess::check($r->user(), $v['site_id']);
        abort_if($v['meeting_on'] > now(Site::findOrFail($v['site_id'])->timezone ?: config('app.timezone'))->toDateString(), 422, '미래 날짜의 회의 녹음은 등록할 수 없습니다.');
        $m = OpsMeeting::firstOrCreate(['created_by_id' => $r->user()->id, 'upload_token' => $v['upload_token']],
            $v + ['disk' => config('meetings.disk'), 'part_count' => (int) ceil($v['audio_bytes'] / config('meetings.chunk_bytes')), 'parts' => [], 'status' => 'uploading']);
        abort_unless((int) $m->site_id === (int) $v['site_id'] && $m->audio_hash === $v['audio_hash'] && (int) $m->audio_bytes === (int) $v['audio_bytes'], 409, '다른 파일에 사용한 업로드 번호입니다.');

        return $this->show($r, $m);
    }

    public function part(Request $r, OpsMeeting $meeting, int $part)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);
        abort_unless((int) $meeting->created_by_id === (int) $r->user()->id, 403);
        $r->validate(['chunk' => 'required|file|max:1024']);
        abort_unless($part >= 0 && $part < $meeting->part_count, 422);
        $bytes = file_get_contents($r->file('chunk')->getRealPath());
        $expected = min(config('meetings.chunk_bytes'), $meeting->audio_bytes - $part * config('meetings.chunk_bytes'));
        abort_unless(strlen($bytes) === $expected, 422, '업로드 조각 크기가 다릅니다.');

        return DB::transaction(function () use ($meeting, $bytes, $part) {
            $m = OpsMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless($m->status === 'uploading', 409, '이미 업로드가 완료되었습니다.');
            $parts = $m->parts ?? [];
            $hash = hash('sha256', $bytes);
            abort_if(isset($parts[$part]) && $parts[$part] !== $hash, 409, '같은 번호의 다른 조각입니다.');
            if (! Storage::disk($m->disk)->put($this->partPath($m, $part), $bytes, 'private')) {
                abort(503, '녹음 조각을 저장하지 못했습니다. 다시 시도해 주세요.');
            }
            $parts[$part] = $hash;
            $m->update(['parts' => $parts]);

            return response()->json(['success' => true, 'part' => $part]);
        });
    }

    public function finish(Request $r, OpsMeeting $meeting)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);
        abort_unless((int) $meeting->created_by_id === (int) $r->user()->id, 403);
        DB::transaction(function () use ($meeting) {
            $m = OpsMeeting::lockForUpdate()->findOrFail($meeting->id);
            if ($m->status !== 'uploading') {
                return;
            }
            abort_unless(count($m->parts ?? []) === $m->part_count, 422, '모든 녹음 조각을 올린 뒤 완료해 주세요.');
            $disk = Storage::disk($m->disk);
            $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
            $hash = hash_init('sha256');
            try {
                for ($i = 0; $i < $m->part_count; $i++) {
                    $bytes = $disk->get($this->partPath($m, $i));
                    abort_unless(is_string($bytes) && hash('sha256', $bytes) === ($m->parts[$i] ?? ''), 422, '녹음 조각의 무결성 확인에 실패했습니다.');
                    fwrite($stream, $bytes);
                    hash_update($hash, $bytes);
                }
                abort_unless(hash_final($hash) === $m->audio_hash && ftell($stream) === (int) $m->audio_bytes, 422, '원본 녹음과 업로드 결과가 다릅니다.');
                rewind($stream);
                $sample = fread($stream, 4096);
                rewind($stream);
                $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);
                abort_unless(in_array($detected, ['audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mpeg', 'audio/x-wav', 'audio/wav', 'audio/mp4', 'video/mp4', 'audio/flac', 'audio/x-flac'], true), 422, '지원하는 녹음 파일이 아닙니다.');
                $path = 'meetings/'.$m->id.'/'.$m->audio_hash.'.audio';
                abort_unless($disk->put($path, $stream, 'private'), 503, '녹음을 영구 저장하지 못했습니다.');
                $mime = ['video/webm' => 'audio/webm', 'application/ogg' => 'audio/ogg', 'audio/x-wav' => 'audio/wav', 'video/mp4' => 'audio/mp4', 'audio/x-flac' => 'audio/flac'][$detected] ?? $detected;
                $m->update(['audio_path' => $path, 'audio_mime' => $mime, 'status' => 'queued', 'queued_at' => now()]);
                ProcessOpsMeeting::dispatch($m->id)->afterCommit();
            } finally {
                fclose($stream);
            }
            // Uploaded chunks can be removed now; the validated original is durable.
            for ($i = 0; $i < $m->part_count; $i++) {
                $disk->delete($this->partPath($m, $i));
            }
        });

        return $this->show($r, $meeting->fresh());
    }

    public function show(Request $r, OpsMeeting $meeting)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);
        $items = $meeting->ops_intake_batch_id ? OpsIntakeItem::where('ops_intake_batch_id', $meeting->ops_intake_batch_id)->orderBy('id')->get() : [];

        return response()->json(['success' => true, 'meeting' => $meeting->only(['id', 'site_id', 'title', 'meeting_on', 'participants', 'status', 'audio_mime', 'audio_hash', 'audio_bytes', 'parts', 'part_count', 'analysis', 'error', 'transcripts', 'queued_at', 'started_at', 'finished_at', 'attempts']),
            'audio_url' => $meeting->audio_path ? route('ops.meeting.audio', $meeting, false) : null, 'items' => $items]);
    }

    public function audio(Request $r, OpsMeeting $meeting)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);
        abort_unless($meeting->audio_path && Storage::disk($meeting->disk)->exists($meeting->audio_path), 404);
        // Byte ranges are necessary for evidence playback to seek to the quoted timestamp.
        $disk = Storage::disk($meeting->disk);
        $size = $disk->size($meeting->audio_path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($range = $r->header('Range')) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size]);
            }
            $start = $matches[1] !== '' ? (int) $matches[1] : max(0, $size - (int) $matches[2]);
            $end = $matches[1] !== '' && $matches[2] !== '' ? min($end, (int) $matches[2]) : $end;
            if ($start > $end || $start >= $size) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size]);
            }
            $status = 206;
        }
        $headers = ['Content-Type' => $meeting->audio_mime, 'Cache-Control' => 'private, no-store', 'Accept-Ranges' => 'bytes', 'Content-Length' => $end - $start + 1];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }

        return response()->stream(function () use ($disk, $meeting, $start, $end) {
            $stream = $disk->readStream($meeting->audio_path);
            if (! is_resource($stream)) {
                return;
            }
            try {
                // S3 streams need not be seekable. Consume skipped bytes in bounded chunks.
                for ($skip = $start; $skip > 0 && ! feof($stream);) {
                    $skip -= strlen(fread($stream, min(65536, $skip)));
                }
                for ($left = $end - $start + 1; $left > 0 && ! feof($stream);) {
                    $chunk = fread($stream, min(65536, $left));
                    if ($chunk === '' || $chunk === false) {
                        break;
                    } echo $chunk;
                    $left -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    public function retry(Request $r, OpsMeeting $meeting)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);
        DB::transaction(function () use ($meeting) {
            $m = OpsMeeting::lockForUpdate()->findOrFail($meeting->id);
            abort_unless($m->audio_path && $m->attempts < 5 && ($m->status === 'failed' || (in_array($m->status, ['queued', 'transcribing', 'analyzing'], true) && $m->updated_at->lt(now()->subMinutes(31)))), 409, '처리 중이거나 재시도할 수 없는 상태입니다.');
            $m->update(['status' => 'queued', 'error' => null, 'queued_at' => now()]);
            ProcessOpsMeeting::dispatch($m->id)->afterCommit();
        });

        return $this->show($r, $meeting->fresh());
    }

    public function edit(Request $r, OpsMeeting $meeting, OpsIntakeItem $item)
    {
        $this->itemGuard($r, $meeting, $item);
        $v = $r->validate(['title' => 'required|string|max:300', 'target_ref' => 'present|nullable|string|max:40', 'assignee' => 'nullable|string|max:120',
            'due_on' => 'nullable|date_format:Y-m-d', 'changes' => 'present|array', 'company' => 'nullable|string|max:150', 'headcount' => 'nullable|integer|min:0|max:1000']);
        $v['target_ref'] = (string) ($v['target_ref'] ?? '');
        DB::transaction(function () use ($item, $v, $r) {
            $i = OpsIntakeItem::lockForUpdate()->findOrFail($item->id);
            abort_unless(in_array($i->status, ['pending', 'needs_input'], true), 409);
            $target = $v['target_ref'] ? $this->context->model($v['target_ref'], $i->site_id) : null;
            abort_if($v['target_ref'] && ! $target, 422, '같은 현장의 대상을 선택해 주세요.');
            $meta = $i->meeting_meta;
            $meta['history'][] = ['event' => 'edit', 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'before' => $i->only(['summary', 'proposed', 'target_name'])];
            foreach (['title', 'target_ref', 'assignee', 'due_on', 'company', 'headcount', 'changes'] as $key) {
                $meta[$key] = $v[$key] ?? null;
            }
            $snap = $target ? $this->context->describe(substr($v['target_ref'], 0, 1), $target) : null;
            $meta['target_snapshot'] = $snap;
            $i->update(['meeting_meta' => $meta, 'summary' => $v['title'], 'proposed' => $v['changes'], 'target_name' => $snap['name'] ?? null,
                'target_type' => $this->workflow->type($v['target_ref']), 'target_code' => $snap['code'] ?? $snap['wbs_code'] ?? null]);
        });

        return $this->show($r, $meeting);
    }

    public function apply(Request $r, OpsMeeting $meeting, OpsIntakeItem $item)
    {
        $this->itemGuard($r, $meeting, $item);
        $r->validate(['confirmed' => 'accepted']);

        return response()->json($this->workflow->apply($item->id, $r->user(), true));
    }

    public function dismiss(Request $r, OpsMeeting $meeting, OpsIntakeItem $item)
    {
        $this->itemGuard($r, $meeting, $item);
        $r->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($r, $item) {
            $i = OpsIntakeItem::lockForUpdate()->findOrFail($item->id);
            abort_unless(in_array($i->status, ['pending', 'needs_input'], true), 409);
            $meta = $i->meeting_meta;
            $meta['history'][] = ['event' => 'dismiss', 'reason' => $r->input('reason'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $i->update(['status' => 'dismissed', 'meeting_meta' => $meta, 'result_note' => '제외: '.$r->input('reason')]);
        });
        $this->workflow->restamp($meeting);

        return response()->json(['success' => true]);
    }

    public function targets(Request $r, OpsMeeting $meeting)
    {
        MeetingAccess::check($r->user(), $meeting->site_id);

        return response()->json(['success' => true] + $this->context->get($meeting->site_id));
    }

    public function undo(Request $r, OpsMeeting $meeting, OpsIntakeItem $item)
    {
        $this->itemGuard($r, $meeting, $item);
        $r->validate(['reason' => 'required|string|max:500']);

        return response()->json($this->workflow->undo($item->id, $r->user(), $r->input('reason')));
    }

    private function itemGuard(Request $r, OpsMeeting $m, OpsIntakeItem $i): void
    {
        MeetingAccess::check($r->user(), $m->site_id);
        abort_unless($i->source === 'meeting' && (int) $i->ops_intake_batch_id === (int) $m->ops_intake_batch_id && (int) ($i->meeting_meta['meeting_id'] ?? 0) === $m->id, 404);
    }

    private function partPath(OpsMeeting $m, int $part): string
    {
        return 'meetings/'.$m->id.'/parts/'.$part.'.part';
    }
}
