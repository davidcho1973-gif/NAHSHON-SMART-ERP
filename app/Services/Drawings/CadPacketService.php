<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\CadDrawingPacket;
use App\Models\IntelligentDocument;
use App\Models\WbsPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Immutable package: reviewed raster PDF + exact source/revision + authorized evidence snapshots. */
class CadPacketService
{
    public function __construct(private CadDrawingService $cad, private CadTargetService $targets, private CadWorkflowService $workflow) {}

    public function create(CadDrawing $drawing, array $data, UploadedFile $pdf): array
    {
        $this->cad->access(auth()->user(), $drawing, true, true);
        $bytes = $pdf->get();
        abort_unless(str_starts_with($bytes, '%PDF-') && $pdf->getMimeType() === 'application/pdf', 422, '검토한 PDF 파일이 필요합니다.');
        $refs = $data['references'] ?? [];
        $fingerprint = hash('sha256', json_encode([$drawing->id, $data['revision'], $data['snapshotToken'], $data['title'], $refs, hash('sha256', $bytes)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($drawing, $data, $bytes, $refs, $fingerprint): array {
            $drawing = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $existing = CadDrawingPacket::find($data['id']);
            if ($existing) {
                abort_unless((int) $existing->created_by === (int) auth()->id() && hash_equals($existing->fingerprint, $fingerprint), 409, '같은 보관번호에 다른 내용을 저장할 수 없습니다.');

                return $this->row($existing);
            }
            $revision = $drawing->revisions()->where('revision', $data['revision'])->firstOrFail();
            abort_unless(hash_equals($this->cad->snapshotToken($revision), $data['snapshotToken']), 409, '도면 개정과 검토본이 일치하지 않습니다. 다시 미리보기를 확인하세요.');
            $source = $this->cad->read($drawing, $revision->revision);
            abort_unless(hash_equals($revision->sha256, hash('sha256', $source['dxf'])), 409, 'CAD 개정본 파일이 변경되었습니다.');
            $original = Storage::disk($drawing->disk)->get($drawing->original_path);
            abort_unless(is_string($original) && hash_equals($drawing->sha256, hash('sha256', $original)), 409, '업로드 원본 파일이 변경되었습니다.');
            $files = ['marked.pdf' => $bytes, 'original.'.$drawing->source_format => $original, 'revision.dxf' => $source['dxf'],
                'markups.json' => json_encode($source['markups'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'workspace.json' => json_encode($source['workspace'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            $references = [];
            foreach ($refs as $ref) {
                $target = $this->targets->resolve($drawing, $ref['type'], $ref['id']);
                $references[] = $target;
                if (in_array($ref['type'], ['document', 'photo'], true)) {
                    $this->addEvidence($drawing, $ref, $files);
                }
                if ($ref['type'] === 'submittal') {
                    foreach ($target['documents'] ?? [] as $document) {
                        $docRef = ['type' => 'document', 'id' => $document['id']];
                        $this->targets->resolve($drawing, 'document', $document['id']);
                        $this->addEvidence($drawing, $docRef, $files);
                    }
                }
            }
            $workflow = $this->workflow->context($drawing, $revision->revision);
            $manifest = ['version' => 1, 'title' => $data['title'], 'drawingId' => $drawing->id, 'revision' => $revision->revision, 'sourceSha256' => $drawing->sha256, 'revisionSha256' => $revision->sha256, 'snapshotToken' => $data['snapshotToken'],
                'createdAt' => now()->toIso8601String(), 'createdBy' => auth()->id(), 'reference' => $drawing->reference, 'references' => $references,
                'locations' => array_map(fn ($l) => ['markupId' => $l['markupId'], 'sourceRevision' => $l['sourceRevision'], 'present' => $l['present'], 'tasks' => $l['tasks']], $workflow['locations']),
                'pdfNotice' => '사용자가 검토한 이미지 기반 PDF. CAD 원본과 개정 데이터는 별도 파일로 보존합니다.', 'files' => []];
            $total = 0;
            foreach ($files as $name => $content) {
                $total += strlen($content);
                $manifest['files'][] = ['name' => $name, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
            }
            abort_if($total > 100000000, 422, '보관본은 압축 전 최대 100MB입니다. 근거 자료를 나누어 보관하세요.');
            $files['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temp = tempnam(sys_get_temp_dir(), 'cad-packet-');
            abort_if($temp === false, 503, '보관 작업 공간을 준비하지 못했습니다.');
            $path = 'cad-packets/'.$data['id'].'/packet.zip';
            $pdfPath = 'cad-packets/'.$data['id'].'/marked.pdf';
            $disk = $drawing->disk;
            $written = false;
            try {
                $zip = new ZipArchive;
                abort_unless($zip->open($temp, ZipArchive::OVERWRITE) === true, 503, '보관 파일을 만들지 못했습니다.');
                foreach ($files as $name => $content) {
                    abort_unless($zip->addFromString($name, $content), 503, '보관 파일을 추가하지 못했습니다.');
                }
                abort_unless($zip->close(), 503, '보관 파일을 완성하지 못했습니다.');
                $archive = file_get_contents($temp);
                abort_unless(is_string($archive), 503);
                $written = true;
                abort_unless(Storage::disk($disk)->put($path, $archive, ['visibility' => 'private']) && Storage::disk($disk)->put($pdfPath, $bytes, ['visibility' => 'private']), 503, '검토본을 저장하지 못했습니다.');
                $packet = CadDrawingPacket::create(['id' => $data['id'], 'cad_drawing_revision_id' => $revision->id, 'title' => $data['title'], 'disk' => $disk, 'path' => $path, 'pdf_path' => $pdfPath, 'sha256' => hash('sha256', $archive), 'fingerprint' => $fingerprint, 'manifest' => $manifest, 'created_by' => auth()->id(), 'created_at' => now()]);
                $this->workflow->event($drawing, null, 'packet_created', ['packetId' => $packet->id, 'revision' => $revision->revision, 'sha256' => $packet->sha256]);

                return $this->row($packet);
            } catch (\Throwable $e) {
                if ($written) {
                    Storage::disk($disk)->delete([$path, $pdfPath]);
                }throw $e;
            } finally {
                @unlink($temp);
            }
        });
    }

    private function addEvidence(CadDrawing $drawing, array $ref, array &$files): void
    {
        $this->targets->resolve($drawing, $ref['type'], $ref['id']);
        if ($ref['type'] === 'photo') {
            $model = WbsPhoto::findOrFail($ref['id']);
            $path = $model->original_path ?: $model->path;
            $expected = $model->original_path ? $model->original_sha256 : null;
            $extension = $model->original_path ? pathinfo($model->original_name ?? '', PATHINFO_EXTENSION) : ($model->mime === 'image/png' ? 'png' : 'jpg');
        } else {
            $model = IntelligentDocument::visibleTo(auth()->user())->findOrFail($ref['id']);
            $path = $model->file_path;
            $expected = $model->sha256;
            $extension = $model->extension;
        }
        $name = 'evidence/'.$ref['type'].'-'.$ref['id'].'.'.(preg_match('/^[a-z0-9]{1,8}$/i', (string) $extension) ? strtolower($extension) : 'bin');
        if (isset($files[$name])) {
            return;
        }
        abort_unless($path && Storage::disk($model->disk)->exists($path), 409, '선택한 근거 자료 원본이 없습니다.');
        abort_if(array_sum(array_map('strlen', $files)) + Storage::disk($model->disk)->size($path) > 100000000, 422, '보관본은 압축 전 최대 100MB입니다.');
        $bytes = Storage::disk($model->disk)->get($path);
        abort_unless(is_string($bytes) && (! $expected || hash_equals($expected, hash('sha256', $bytes))), 409, '근거 자료 원본 해시가 변경되었습니다.');
        $files[$name] = $bytes;
    }

    public function access(CadDrawingPacket $packet): void
    {
        $drawing = $packet->revision->drawing;
        $this->cad->access(auth()->user(), $drawing, false, true);
        foreach ($packet->manifest['references'] ?? [] as $ref) {
            $this->targets->resolve($drawing, $ref['type'], $ref['id']);
            foreach ($ref['documents'] ?? [] as $doc) {
                $this->targets->resolve($drawing, 'document', $doc['id']);
            }
        }
        foreach ($packet->manifest['locations'] ?? [] as $location) {
            foreach ($location['tasks'] ?? [] as $task) {
                foreach ($task['evidence'] ?? [] as $ref) {
                    $this->targets->resolve($drawing, $ref['type'], $ref['id']);
                }
            }
        }
    }

    public function bytes(CadDrawingPacket $packet, bool $pdf = false): string
    {
        $this->access($packet);
        $bytes = Storage::disk($packet->disk)->get($pdf ? $packet->pdf_path : $packet->path);
        $expected = $pdf ? collect($packet->manifest['files'])->firstWhere('name', 'marked.pdf')['sha256'] : $packet->sha256;
        abort_unless(is_string($bytes) && hash_equals($expected, hash('sha256', $bytes)), 409, '보관본의 무결성을 확인하지 못했습니다.');

        return $bytes;
    }

    public function row(CadDrawingPacket $packet): array
    {
        return ['id' => $packet->id, 'title' => $packet->title, 'revision' => $packet->revision->revision, 'sha256' => $packet->sha256, 'createdAt' => $packet->created_at->toIso8601String(),
            'files' => array_map(fn ($f) => ['name' => $f['name'], 'bytes' => $f['bytes'], 'sha256' => $f['sha256']], $packet->manifest['files']),
            'url' => '/admin-api/cad/'.$packet->revision->cad_drawing_id.'/workflow/packets/'.$packet->id.'/download',
            'pdfUrl' => '/admin-api/cad/'.$packet->revision->cad_drawing_id.'/workflow/packets/'.$packet->id.'/download?format=pdf'];
    }
}
