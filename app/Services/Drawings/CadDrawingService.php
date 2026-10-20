<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\Site;
use App\Models\User;
use App\Support\AiInformationAccess;
use App\Support\JobAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function access(User $user, CadDrawing $drawing, bool $write = false, bool $export = false): void
    {
        $this->gate($user, $write, $export);
        abort_unless($drawing->site && AiInformationAccess::canUseSite($user, $drawing->site), 404);
    }

    public function sites(User $user)
    {
        $this->gate($user);

        return Site::orderBy('name')->get()->filter(fn (Site $site) => AiInformationAccess::canUseSite($user, $site))->values();
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

    public function import(User $user, Site $site, UploadedFile $file, string $title): CadDrawing
    {
        $this->gate($user, true);
        abort_unless(AiInformationAccess::canUseSite($user, $site), 404);
        $bytes = $file->get();
        if (strlen($bytes) > config('cad.max_bytes')) {
            throw ValidationException::withMessages(['file' => '최대 20MB까지 업로드할 수 있습니다.']);
        }
        $dwg = preg_match('/^AC10[0-9]{2}/', $bytes) === 1;
        if (! $dwg) {
            $this->validateDxf($bytes);
        }
        $format = $dwg ? 'dwg' : 'dxf';
        $result = $dwg ? $this->converter->convert($bytes, 'dwg', 'dxf') : ['bytes' => $bytes, 'warnings' => []];
        $this->validateDxf($result['bytes']);
        $disk = config('cad.disk') ?: config('document-intelligence.disk');
        abort_if($disk === 'public' || (app()->environment('production') && config("filesystems.disks.$disk.driver") === 'local'), 503, 'CAD 저장소에 영구 비공개 디스크를 설정해야 합니다.');
        $base = 'cad/'.Str::uuid();
        $paths = [$base.'/original.'.$format, $base.'/revisions/1.dxf'];
        try {
            $this->put($disk, $paths[0], $bytes);
            $this->put($disk, $paths[1], $result['bytes']);

            return DB::transaction(function () use ($user, $site, $file, $title, $format, $disk, $paths, $bytes, $result): CadDrawing {
                $drawing = CadDrawing::create(['company_id' => $site->company_id, 'site_id' => $site->id, 'title' => $title,
                    'source_format' => $format, 'original_name' => basename($file->getClientOriginalName()), 'disk' => $disk,
                    'original_path' => $paths[0], 'sha256' => hash('sha256', $bytes), 'revision' => 1, 'created_by' => $user->id]);
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
            'dxf' => Storage::disk($drawing->disk)->get($row->dxf_path), 'sourceFormat' => $drawing->source_format, 'warnings' => $row->warnings ?? []];
    }

    public function save(User $user, CadDrawing $drawing, int $revision, string $dxf): array
    {
        $this->access($user, $drawing, true);
        $this->validateDxf($dxf);

        // A parent row lock serializes revisions; immutable files prevent one editor replacing another editor's work.
        return DB::transaction(function () use ($user, $drawing, $revision, $dxf): array {
            $current = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            abort_if((int) $current->revision !== $revision, 409, '다른 사용자가 수정했습니다. 최신 도면을 다시 열어 주세요.');
            $next = $revision + 1;
            $warnings = $current->revisions()->where('revision', $revision)->firstOrFail()->warnings ?? [];
            $path = dirname($current->original_path).'/revisions/'.$next.'-'.Str::uuid().'.dxf';
            $this->put($current->disk, $path, $dxf);
            try {
                $current->revisions()->create(['revision' => $next, 'dxf_path' => $path, 'sha256' => hash('sha256', $dxf), 'warnings' => $warnings, 'created_by' => $user->id]);
                $current->update(['revision' => $next]);
            } catch (\Throwable $e) {
                Storage::disk($current->disk)->delete($path);
                throw $e;
            }

            return $this->read($current) + ['canManage' => true];
        });
    }
}
