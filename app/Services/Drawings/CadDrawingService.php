<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\CadDrawingRevision;
use App\Models\DrawingSheet;
use App\Models\Site;
use App\Models\User;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadDrawingService
{
    public function __construct(private readonly CadConverter $converter) {}

    public function gate(User $user, bool $write = false, bool $export = false): void
    {
        abort_unless(app(SectionDrawingService::class)->canView($user), 403);
        abort_if(JobAccess::managed($user) && ! JobAccess::can($user, 'progress', $write ? 'edit' : 'view'), 403);
        if ($write) {
            abort_unless($this->canManage($user), 403);
        }
        if ($export) {
            abort_if(JobAccess::managed($user) && ! JobAccess::can($user, 'documents', 'export'), 403);
        }
    }

    public function canManage(User $user): bool
    {
        return app(SectionDrawingService::class)->canView($user) && app(DrawingStudioService::class)->canEdit($user)
            && (! JobAccess::managed($user) || JobAccess::can($user, 'progress', 'edit'));
    }

    public function canExport(User $user): bool
    {
        return ! JobAccess::managed($user) || JobAccess::can($user, 'documents', 'export');
    }

    public function access(User $user, CadDrawing $drawing, bool $write = false, bool $export = false): void
    {
        $this->gate($user, $write, $export);
        abort_unless($drawing->site && AiInformationAccess::canUseSite($user, $drawing->site), 404);
        if ($sheetId = $drawing->reference['sourceSheetId'] ?? null) {
            // A private PDF must not become site-wide merely by opening it in the CAD editor.
            app(DrawingStudioService::class)->access($user, DrawingSheet::findOrFail($sheetId));
        }
    }

    public function sites(User $user)
    {
        $this->gate($user);

        return Site::orderBy('name')->get()->filter(fn (Site $site) => AiInformationAccess::canUseSite($user, $site))->values();
    }

    public function visibleTo(User $user, CadDrawing $drawing, bool $write = false): bool
    {
        try {
            $this->access($user, $drawing, $write);

            return true;
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [403, 404], true)) {
                throw $e;
            }

return false;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    public function validateDxf(string $dxf): void
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($dxf));
        $valid = strlen($dxf) <= config('cad.max_bytes') && mb_check_encoding($dxf, 'UTF-8') && ! str_contains($dxf, "\0") && count($lines) % 2 === 0;
        $entities = false;
        $eof = false;
        $section = false;
        if ($valid) {
            for ($i = 0; $i < count($lines); $i += 2) {
                if (! preg_match('/^\s*\d{1,4}\s*$/', $lines[$i]) || (int) trim($lines[$i]) > 1071) {
                    $valid = false;
                    break;
                }
                $code = (int) trim($lines[$i]);
                $value = trim($lines[$i + 1]);
                if ($code === 0 && $value === 'SECTION') {
                    if ($section) {
                        $valid = false;
                        break;
                    } $section = true;
                }
                if ($code === 2 && $value === 'ENTITIES' && $section) {
                    $entities = true;
                }
                if ($code === 0 && $value === 'ENDSEC') {
                    if (! $section) {
                        $valid = false;
                        break;
                    } $section = false;
                }
                if ($code === 0 && $value === 'EOF') {
                    $eof = ! $section && $i === count($lines) - 2;
                }
            }
        }
        if (! $valid || ! $entities || ! $eof) {
            throw ValidationException::withMessages(['dxf' => '올바른 UTF-8 텍스트 DXF(ENTITIES/EOF 포함, 최대 20MB)가 필요합니다.']);
        }
    }

    private function put(string $disk, string $path, string $bytes): void
    {
        abort_unless(Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']), 503, '도면을 안전하게 저장하지 못했습니다.');
    }

    public function import(User $user, Site $site, UploadedFile $file, string $title, ?array $referenceInput = null, ?DrawingSheet $sourceSheet = null, ?string $sourceRequest = null): CadDrawing
    {
        $this->gate($user, true);
        abort_unless(AiInformationAccess::canUseSite($user, $site), 404);
        $bytes = $file->get();
        if (strlen($bytes) > config('cad.max_bytes')) {
            throw ValidationException::withMessages(['file' => '최대 20MB까지 업로드할 수 있습니다.']);
        }
        $dwg = preg_match('/^AC10[0-9]{2}/', $bytes) === 1;
        $reference = app(CadReferenceService::class)->inspect($bytes, $file->getMimeType(), $referenceInput);
        if ($sourceSheet) {
            app(DrawingStudioService::class)->access($user, $sourceSheet);
            abort_unless((int) $sourceSheet->site_id === (int) $site->id && ($reference['format'] ?? null) === 'pdf' && $reference['page'] === (int) $sourceSheet->page_no, 422);
            $reference += ['sourceSheetId' => $sourceSheet->id, 'sourceDocumentId' => $sourceSheet->intelligent_document_id, 'importRequestKey' => $sourceRequest];
        }
        if (! $dwg && ! $reference) {
            $this->validateDxf($bytes);
        }
        $format = $reference['format'] ?? ($dwg ? 'dwg' : 'dxf');
        $result = $reference ? ['bytes' => app(CadReferenceService::class)->extents($reference), 'warnings' => ['PDF/이미지 원본 위 표시 · CAD 객체 편집 대상 아님 · 실제 거리 측정에는 두 점 축척 보정 필요']] : ($dwg ? $this->converter->convert($bytes, 'dwg', 'dxf') : ['bytes' => $bytes, 'warnings' => []]);
        $this->validateDxf($result['bytes']);
        $disk = config('cad.disk') ?: config('document-intelligence.disk');
        abort_if($disk === 'public' || (app()->environment('production') && config("filesystems.disks.$disk.driver") === 'local'), 503, 'CAD 저장소에 영구 비공개 디스크를 설정해야 합니다.');
        $base = 'cad/'.Str::uuid();
        $paths = [$base.'/original.'.$format, $base.'/revisions/1.dxf'];
        try {
            $this->put($disk, $paths[0], $bytes);
            $this->put($disk, $paths[1], $result['bytes']);

            return DB::transaction(function () use ($user, $site, $file, $title, $format, $disk, $paths, $bytes, $result, $reference): CadDrawing {
                $drawing = CadDrawing::create(['company_id' => $site->company_id, 'site_id' => $site->id, 'title' => $title,
                    'source_format' => $format, 'original_name' => basename($file->getClientOriginalName()), 'disk' => $disk,
                    'original_path' => $paths[0], 'sha256' => hash('sha256', $bytes), 'revision' => 1, 'created_by' => $user->id, 'reference' => $reference]);
                $drawing->revisions()->create(['revision' => 1, 'dxf_path' => $paths[1], 'sha256' => hash('sha256', $result['bytes']), 'warnings' => $result['warnings'], 'created_by' => $user->id]);

                return $drawing;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($paths);
            throw $e;
        }
    }

    public function read(CadDrawing $drawing, ?int $revision = null): array
    {
        $row = $drawing->revisions()->where('revision', $revision ?? $drawing->revision)->firstOrFail();
        abort_unless(Storage::disk($drawing->disk)->exists($row->dxf_path), 404);

        return ['id' => $drawing->id, 'title' => $drawing->title, 'site_id' => $drawing->site_id, 'revision' => $row->revision,
            'snapshotToken' => $this->snapshotToken($row),
            'dxf' => Storage::disk($drawing->disk)->get($row->dxf_path), 'sourceFormat' => $drawing->source_format, 'warnings' => $row->warnings ?? [],
            'markups' => $row->markups ?? [], 'workspace' => (object) ($row->workspace ?? []),
            'reference' => $drawing->reference ? $drawing->reference + ['url' => '/admin-api/cad/'.$drawing->id.'/reference', 'sha256' => $drawing->sha256] : null];
    }

    public function snapshotToken(CadDrawingRevision $revision): string
    {
        return hash('sha256', $revision->id.'|'.$revision->sha256.'|'.json_encode([$revision->markups ?? [], $revision->workspace ?? []], JSON_THROW_ON_ERROR));
    }

    public function save(User $user, CadDrawing $drawing, int $revision, string $dxf, ?array $markups = null, ?array $workspace = null): array
    {
        $this->access($user, $drawing, true);
        $this->validateDxf($dxf);
        if ($markups !== null) {
            $markups = app(CadMarkupValidator::class)->validate($markups);
        }
        if ($workspace !== null) {
            $workspace = app(CadWorkspaceValidator::class)->validate($workspace);
        }

        // A parent row lock serializes revisions; immutable files prevent one editor replacing another editor's work.
        return DB::transaction(function () use ($user, $drawing, $revision, $dxf, $markups, $workspace): array {
            $current = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            abort_if((int) $current->revision !== $revision, 409, '다른 사용자가 수정했습니다. 최신 도면을 다시 열어 주세요.');
            $next = $revision + 1;
            $previous = $current->revisions()->where('revision', $revision)->firstOrFail();
            abort_if($current->reference && ! hash_equals($previous->sha256, hash('sha256', $dxf)), 422, 'PDF/이미지 원본 좌표는 변경할 수 없습니다. 도면 위 표시를 편집하세요.');
            $warnings = $previous->warnings ?? [];
            // Older clients omit this field. Preserve the overlay unless they explicitly send [].
            $markups ??= $previous->markups ?? [];
            $workspace ??= $previous->workspace ?? [];
            $layerIds = array_column($workspace['layers'] ?? [], 'id');
            foreach ($markups as $item) {
                if (isset($item['layer']) && ! in_array($item['layer'], $layerIds, true)) {
                    throw ValidationException::withMessages(['markups' => '표시가 속한 공종 레이어를 찾을 수 없습니다.']);
                }
            }
            $path = dirname($current->original_path).'/revisions/'.$next.'-'.Str::uuid().'.dxf';
            $this->put($current->disk, $path, $dxf);
            try {
                $current->revisions()->create(['revision' => $next, 'dxf_path' => $path, 'sha256' => hash('sha256', $dxf), 'warnings' => $warnings, 'markups' => $markups, 'workspace' => $workspace, 'created_by' => $user->id]);
                $current->update(['revision' => $next]);
            } catch (\Throwable $e) {
                Storage::disk($current->disk)->delete($path);
                throw $e;
            }

            return $this->read($current) + ['canManage' => true, 'canExport' => $this->canExport($user)];
        });
    }

    /** Recovery of a stale local draft creates a new drawing; it never overwrites a newer revision. */
    public function copy(User $user, CadDrawing $drawing, int $revision, string $title, string $dxf, array $markups, array $workspace, string $requestKey): array
    {
        $this->access($user, $drawing, true);
        $this->validateDxf($dxf);
        $markups = app(CadMarkupValidator::class)->validate($markups);
        $workspace = app(CadWorkspaceValidator::class)->validate($workspace);
        $layerIds = array_column($workspace['layers'] ?? [], 'id');
        foreach ($markups as $item) {
            if (isset($item['layer']) && ! in_array($item['layer'], $layerIds, true)) {
                throw ValidationException::withMessages(['markups' => '표시가 속한 공종 레이어를 찾을 수 없습니다.']);
            }
        }
        $source = $drawing->revisions()->where('revision', $revision)->firstOrFail();
        abort_if($drawing->reference && ! hash_equals($source->sha256, hash('sha256', $dxf)), 422, 'PDF/이미지 원본 좌표가 변경되었습니다.');
        abort_unless(Storage::disk($drawing->disk)->exists($drawing->original_path), 404);
        $original = Storage::disk($drawing->disk)->get($drawing->original_path);
        $base = 'cad/'.Str::uuid();
        $paths = [$base.'/original.'.$drawing->source_format, $base.'/revisions/1.dxf'];
        $fingerprint = hash('sha256', $source->id."\n".$title."\n".$dxf."\n".json_encode([$markups, $workspace], JSON_THROW_ON_ERROR));
        $written = false;
        try {
            $copy = DB::transaction(function () use ($user, $drawing, $source, $title, $paths, $dxf, $markups, $workspace, $requestKey, $fingerprint, $original, &$written): CadDrawing {
                // Serialize retries for this source before allocating private files.
                CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
                $existing = CadDrawing::where('copy_request_key', $requestKey)->first();
                if ($existing) {
                    abort_unless((int) $existing->created_by === (int) $user->id && hash_equals($existing->copy_fingerprint ?? '', $fingerprint), 409, '복구 사본 요청 내용이 변경되었습니다.');

                    return $existing;
                }
                $written = true;
                $this->put($drawing->disk, $paths[0], $original);
                $this->put($drawing->disk, $paths[1], $dxf);
                $copy = CadDrawing::create(['company_id' => $drawing->company_id, 'site_id' => $drawing->site_id, 'title' => $title,
                    'source_format' => $drawing->source_format, 'original_name' => $drawing->original_name, 'disk' => $drawing->disk,
                    'original_path' => $paths[0], 'sha256' => $drawing->sha256, 'revision' => 1, 'created_by' => $user->id, 'copied_from_revision_id' => $source->id,
                    'copy_request_key' => $requestKey, 'copy_fingerprint' => $fingerprint, 'reference' => $drawing->reference]);
                $copy->revisions()->create(['revision' => 1, 'dxf_path' => $paths[1], 'sha256' => hash('sha256', $dxf), 'warnings' => $source->warnings ?? [],
                    'markups' => $markups, 'workspace' => $workspace, 'created_by' => $user->id]);

                return $copy;
            });
        } catch (\Throwable $e) {
            if ($written) {
                Storage::disk($drawing->disk)->delete($paths);
            }
            throw $e;
        }

        return $this->read($copy) + ['canManage' => true, 'canExport' => $this->canExport($user)];
    }
}
