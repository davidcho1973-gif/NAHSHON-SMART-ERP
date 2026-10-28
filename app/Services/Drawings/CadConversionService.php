<?php

namespace App\Services\Drawings;

use App\Jobs\ProcessCadConversion;
use App\Models\CadConversion;
use App\Models\CadDrawing;
use App\Models\Site;
use App\Models\User;
use App\Support\AiInformationAccess;
use App\Support\CadFileLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadConversionService
{
    public function __construct(private readonly CadDrawingService $cad, private readonly CadConverter $converter) {}

    public function access(User $user, CadConversion $conversion): void
    {
        $this->cad->gate($user);
        abort_unless((int) $conversion->created_by === (int) $user->id && $conversion->site &&
            AiInformationAccess::canUseSite($user, $conversion->site), 404);
        if ($conversion->operation === 'export') {
            $this->cad->access($user, CadDrawing::findOrFail($conversion->result['sourceDrawingId']), false, true);
        }
    }

    public function export(User $user, CadDrawing $drawing, int $revision, string $requestKey): CadConversion
    {
        $this->cad->access($user, $drawing, false, true);
        abort_unless(config('cad.isolated_conversions') && ! $drawing->reference, 422, '전용 DWG 내보내기를 사용할 수 없습니다.');
        $row = $drawing->revisions()->where('revision', $revision)->firstOrFail();

        return DB::transaction(function () use ($user, $drawing, $row, $requestKey): CadConversion {
            $task = CadConversion::firstOrCreate(['created_by' => $user->id, 'request_key' => $requestKey],
                ['id' => (string) Str::uuid(), 'company_id' => $drawing->company_id, 'site_id' => $drawing->site_id,
                    'operation' => 'export', 'status' => 'queued', 'title' => $drawing->title, 'original_name' => 'drawing-'.$drawing->id.'.dxf',
                    'disk' => $drawing->disk, 'source_path' => $row->dxf_path, 'source_format' => 'dxf', 'sha256' => $row->sha256,
                    'result' => ['sourceDrawingId' => $drawing->id, 'revision' => $row->revision]]);
            abort_unless($task->operation === 'export' && (int) ($task->result['sourceDrawingId'] ?? 0) === (int) $drawing->id &&
                (int) ($task->result['revision'] ?? 0) === (int) $row->revision, 409, '내보내기 요청 정보가 다릅니다.');
            if ($task->wasRecentlyCreated) {
                ProcessCadConversion::dispatch($task->id);
            }

            return $task;
        });
    }

    public function submit(User $user, Site $site, UploadedFile $file, string $title, string $requestKey): CadConversion
    {
        $this->cad->gate($user, true);
        abort_unless(AiInformationAccess::canUseSite($user, $site), 404);
        abort_unless(config('cad.isolated_conversions'), 503, '전용 CAD 변환 서버가 아직 활성화되지 않았습니다.');
        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = CadConversion::where('created_by', $user->id)->where('request_key', $requestKey)->first()) {
            abort_unless($existing->operation === 'import' && hash_equals($existing->sha256, $sha) && (int) $existing->site_id === (int) $site->id, 409, '업로드 요청이 다른 파일에 사용됐습니다.');

            return $existing;
        }
        $head = file_get_contents($file->getRealPath(), false, null, 0, 6);
        abort_unless(preg_match('/^AC10[0-9]{2}$/D', $head), 422, '올바른 DWG 원본이 필요합니다.');
        abort_if($file->getSize() > CadFileLimits::drawingBytes(), 422, '원본은 최대 '.CadFileLimits::label().'까지 지원합니다.');
        $disk = config('cad.disk') ?: config('document-intelligence.disk');
        abort_if($disk === 'public' || (app()->environment('production') && config("filesystems.disks.$disk.driver") === 'local'), 503, '영구 비공개 CAD 저장소가 필요합니다.');
        $id = (string) Str::uuid();
        $path = 'cad/conversions/'.$id.'/original.dwg';
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            abort_unless(Storage::disk($disk)->put($path, $stream, ['visibility' => 'private']), 503, '원본을 보관하지 못했습니다.');
        } finally {
            fclose($stream);
        }
        try {
            return DB::transaction(function () use ($user, $site, $file, $title, $requestKey, $sha, $disk, $id, $path): CadConversion {
                $conversion = CadConversion::create(['id' => $id, 'company_id' => $site->company_id, 'site_id' => $site->id,
                    'created_by' => $user->id, 'request_key' => $requestKey, 'title' => $title, 'operation' => 'import', 'status' => 'queued',
                    'disk' => $disk, 'source_path' => $path, 'source_format' => 'dwg', 'sha256' => $sha, 'original_name' => basename($file->getClientOriginalName())]);
                // Database queue insertion joins this transaction. The original
                // and job are durable before the browser sees HTTP 202.
                ProcessCadConversion::dispatch($id);

                return $conversion;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            // A concurrent retry may have won the unique request key.
            $winner = CadConversion::where('created_by', $user->id)->where('request_key', $requestKey)->first();
            if ($winner && $winner->operation === 'import' && hash_equals($winner->sha256, $sha) && (int) $winner->site_id === (int) $site->id) {
                return $winner;
            }
            throw $e;
        }
    }

    public function status(CadConversion $conversion): array
    {
        // A killed process cannot execute failed(). Convert its expired lease
        // into a retryable state without ever starting conversion in HTTP.
        if ($conversion->status === 'processing' && $conversion->started_at?->lt(now()->subSeconds(900))) {
            CadConversion::whereKey($conversion->id)->where('status', 'processing')->where('attempt', $conversion->attempt)
                ->where('started_at', $conversion->started_at)->update(['status' => 'failed', 'finished_at' => now(),
                    'error' => '변환 서버의 작업 응답 시간이 초과됐습니다. 보관된 원본으로 재시도할 수 있습니다.']);
            $conversion->refresh();
        }

        $result = $conversion->result;
        unset($result['outputPath']);

        return ['id' => $conversion->id, 'status' => $conversion->status, 'title' => $conversion->title, 'operation' => $conversion->operation,
            'notice' => $conversion->status === 'queued' && $conversion->updated_at?->lt(now()->subMinutes(5))
                ? '전용 변환 서버의 대기가 길어지고 있습니다. 원본은 보관되어 있습니다. 관리자에게 작업 서버 상태 확인을 요청하세요.' : null,
            'attempt' => $conversion->attempt, 'error' => $conversion->error, 'result' => $result, 'siteId' => $conversion->site_id,
            'originalUrl' => '/admin-api/cad/conversions/'.$conversion->id.'/original',
            'createdAt' => $conversion->created_at?->toIso8601String()];
    }

    public function retry(CadConversion $conversion): CadConversion
    {
        abort_unless(config('cad.isolated_conversions'), 503, '전용 CAD 변환 서버가 아직 활성화되지 않았습니다.');

        return DB::transaction(function () use ($conversion): CadConversion {
            $task = CadConversion::whereKey($conversion->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->status === 'failed', 409, '실패한 작업만 재시도할 수 있습니다.');
            abort_if($task->attempt >= 3, 422, '반복 실패한 도면입니다. 원본 객체 검사가 필요합니다.');
            $task->update(['status' => 'queued', 'error' => null, 'started_at' => null, 'finished_at' => null, 'attempt' => $task->attempt + 1]);
            ProcessCadConversion::dispatch($task->id, $task->attempt);

            return $task;
        });
    }

    public function process(string $id, int $attempt): void
    {
        abort_unless(app()->runningInConsole() && config('cad.worker_active') === true, 503);
        $claimed = CadConversion::whereKey($id)->where('status', 'queued')->where('attempt', $attempt)
            ->update(['status' => 'processing', 'started_at' => now(), 'error' => null]);
        if (! $claimed) {
            return;
        }
        $task = CadConversion::findOrFail($id);
        $directory = storage_path('app/private/cad-worker/'.Str::uuid());
        $written = [];
        try {
            File::ensureDirectoryExists($directory, 0700);
            $input = $directory.'/source.'.$task->source_format;
            $source = Storage::disk($task->disk)->readStream($task->source_path);
            abort_unless(is_resource($source), 503, '보관된 원본을 읽지 못했습니다.');
            $target = fopen($input, 'xb');
            try {
                stream_copy_to_stream($source, $target, CadFileLimits::drawingBytes() + 1);
            } finally {
                fclose($source);
                fclose($target);
            }
            abort_unless(filesize($input) <= CadFileLimits::drawingBytes() && hash_equals($task->sha256, hash_file('sha256', $input)), 422, '원본 무결성 검증에 실패했습니다.');
            if ($task->operation === 'export') {
                $native = $this->converter->convert(file_get_contents($input), 'dxf', 'dwg');
                $path = 'cad/conversions/'.$id.'/attempt-'.$attempt.'/export.dwg';
                abort_unless(Storage::disk($task->disk)->put($path, $native['bytes'], ['visibility' => 'private']), 503, 'DWG 내보내기를 저장하지 못했습니다.');
                $written[] = $path;
                $updated = CadConversion::whereKey($id)->where('status', 'processing')->where('attempt', $attempt)
                    ->update(['status' => 'completed', 'finished_at' => now(), 'result' => json_encode($task->result + [
                        'outputPath' => $path, 'warnings' => $native['warnings'], 'downloadUrl' => '/admin-api/cad/conversions/'.$id.'/download'], JSON_THROW_ON_ERROR)]);
                abort_unless($updated, 409, '내보내기 상태가 변경됐습니다.');
                $written = [];

                return;
            }
            $report = $this->converter->prepare2D($input, $directory.'/output');
            foreach ($report['parts'] as $index => $part) {
                $local = $directory.'/output/'.$part['file'];
                $this->cad->validateDxf(file_get_contents($local));
                $path = dirname($task->source_path).'/attempt-'.$attempt.'/'.$part['file'];
                $stream = fopen($local, 'rb');
                try {
                    abort_unless(Storage::disk($task->disk)->put($path, $stream, ['visibility' => 'private']), 503, '작업본을 저장하지 못했습니다.');
                    $written[] = $path;
                } finally {
                    fclose($stream);
                }
            }
            DB::transaction(function () use ($task, $attempt, $report, $written): void {
                $current = CadConversion::whereKey($task->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->status === 'processing' && $current->attempt === $attempt, 409, '작업 상태가 변경됐습니다.');
                $drawings = [];
                foreach ($report['parts'] as $index => $part) {
                    $title = mb_substr($task->title, 0, 210).' · '.$part['label'].' '.($index + 1);
                    $drawing = CadDrawing::create(['company_id' => $task->company_id, 'site_id' => $task->site_id, 'title' => $title,
                        'source_format' => 'dwg', 'original_name' => $task->original_name, 'disk' => $task->disk, 'original_path' => $task->source_path,
                        'sha256' => $task->sha256, 'revision' => 1, 'created_by' => $task->created_by]);
                    $drawing->revisions()->create(['revision' => 1, 'dxf_path' => $written[$index], 'sha256' => $part['sha256'],
                        'warnings' => $report['warnings'], 'created_by' => $task->created_by]);
                    $drawings[] = ['id' => $drawing->id, 'title' => $title, 'entities' => $part['entities'], 'bytes' => $part['bytes']];
                }
                $current->update(['status' => 'completed', 'finished_at' => now(), 'result' => ['drawings' => $drawings,
                    'warnings' => $report['warnings'], 'excluded' => $report['excluded'], 'projected' => $report['projected'],
                    'units' => $report['units'], 'sha256' => $task->sha256]]);
            });
            $written = [];
        } catch (\Throwable $e) {
            $message = $e instanceof HttpExceptionInterface ? $e->getMessage() : '변환 처리 중 오류가 발생했습니다. 원본은 보존했습니다.';
            CadConversion::whereKey($id)->where('status', 'processing')->where('attempt', $attempt)
                ->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            if (! $e instanceof HttpExceptionInterface) {
                report($e);
            }
        } finally {
            if ($written) {
                Storage::disk($task->disk)->delete($written);
            }
            // Unique private temporary directory, never a user-supplied path.
            File::deleteDirectory($directory);
        }
    }
}
