<?php

namespace App\Http\Controllers;

use App\Models\CadDrawing;
use App\Models\DrawingSheet;
use App\Models\Site;
use App\Services\Drawings\CadClaimDraftService;
use App\Services\Drawings\CadConverter;
use App\Services\Drawings\CadDrawingService;
use App\Services\Drawings\DrawingStudioService;
use App\Support\CadFileLimits;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadDrawingController extends Controller
{
    public function __construct(private readonly CadDrawingService $cad, private readonly CadConverter $converter) {}

    public function index(Request $request)
    {
        $this->cad->gate($request->user());

        return view('cad.index');
    }

    public function capabilities(Request $request)
    {
        $this->cad->gate($request->user());

        return response()->json($this->converter->capabilities() + ['limits' => CadFileLimits::capabilities()]);
    }

    public function claimContext(Request $request, CadClaimDraftService $claims)
    {
        $data = $request->validate(['lineId' => 'required|integer|min:1']);

        return response()->json($claims->context($data['lineId']));
    }

    public function claimDraft(Request $request, CadDrawing $drawing, CadClaimDraftService $claims)
    {
        $data = $request->validate(['lineId' => 'required|integer|min:1', 'drawingRevision' => 'required|integer|min:1', 'draftRevision' => 'required|integer|min:0']);

        return response()->json($claims->attach($drawing, $data['lineId'], $data['drawingRevision'], $data['draftRevision']));
    }

    public function drawings(Request $request)
    {
        $sites = $this->cad->sites($request->user());
        $data = $request->validate(['site_id' => 'nullable|integer|min:1']);
        if (isset($data['site_id'])) {
            abort_unless($sites->contains('id', (int) $data['site_id']), 404);
        }
        $drawings = CadDrawing::whereIn('site_id', $sites->pluck('id'))
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->orderByDesc('updated_at')->limit(500)->get(['id', 'title', 'site_id', 'revision', 'source_format', 'updated_at', 'reference'])
            ->filter(function ($drawing) use ($request) {
                try {
                    $this->cad->access($request->user(), $drawing);

                    return true;
                } catch (HttpExceptionInterface $e) {
                    if (! in_array($e->getStatusCode(), [403, 404], true)) {
                        throw $e;
                    }

                    return false;
                } catch (ModelNotFoundException) {
                    return false;
                }
            })->map(fn ($d) => $d->only(['id', 'title', 'site_id', 'revision', 'source_format', 'updated_at']))->values();

        return response()->json(['drawings' => $drawings, 'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code]), 'canManage' => $this->cad->canManage($request->user())]);
    }

    public function store(Request $request)
    {
        $this->cad->gate($request->user(), true);
        $data = $request->validate(['file' => 'required|file|max:'.(CadFileLimits::drawingBytes() / 1024), 'site_id' => 'required|integer', 'title' => 'required|string|max:255',
            'reference' => 'sometimes|array:page,width,height', 'reference.page' => 'required_with:reference|integer|between:1,500',
            'reference.width' => 'required_with:reference|numeric|between:1,100000', 'reference.height' => 'required_with:reference|numeric|between:1,100000'],
            ['file.max' => 'CAD 원본 파일은 최대 '.CadFileLimits::label().'까지 업로드할 수 있습니다.',
                'file.uploaded' => '서버가 파일을 받지 못했습니다. 화면에 표시된 업로드 한도를 확인한 뒤 다시 시도하세요.']);
        $drawing = $this->cad->import($request->user(), Site::findOrFail($data['site_id']), $request->file('file'), $data['title'], $data['reference'] ?? null);

        return response()->json($this->cad->read($drawing) + ['canManage' => true, 'canExport' => $this->cad->canExport($request->user())], 201);
    }

    public function show(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing);

        return response()->json($this->cad->read($drawing) + ['canManage' => $this->cad->canManage($request->user()), 'canExport' => $this->cad->canExport($request->user())]);
    }

    public function sourceSheet(Request $request, DrawingSheet $sheet)
    {
        $this->cad->gate($request->user(), true);
        app(DrawingStudioService::class)->access($request->user(), $sheet);

        return response()->json(['title' => $sheet->sheet_no.' '.($sheet->title ?: $sheet->document->displayTitle()), 'siteId' => $sheet->site_id, 'page' => $sheet->page_no, 'url' => '/admin-api/cad/source-sheets/'.$sheet->id.'/file']);
    }

    public function sourceSheetFile(Request $request, DrawingSheet $sheet)
    {
        $this->sourceSheet($request, $sheet);
        $doc = $sheet->document;
        abort_unless($doc->mime_type === 'application/pdf' && Storage::disk($doc->disk)->exists($doc->file_path), 422);
        abort_if(Storage::disk($doc->disk)->size($doc->file_path) > CadFileLimits::drawingBytes(), 422, '현재 CAD 편집기의 원본 한도는 '.CadFileLimits::label().'입니다.');
        $bytes = Storage::disk($doc->disk)->get($doc->file_path);
        abort_unless(is_string($bytes) && hash_equals($doc->sha256, hash('sha256', $bytes)), 409, '문서 원본 해시가 변경되었습니다.');

        return response($bytes)->withHeaders(['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function importSheet(Request $request, DrawingSheet $sheet)
    {
        $this->sourceSheet($request, $sheet);
        $data = $request->validate(['requestKey' => 'required|uuid', 'title' => 'required|string|max:255', 'reference' => 'required|array:page,width,height', 'reference.page' => 'required|integer|between:1,500', 'reference.width' => 'required|numeric|between:1,100000', 'reference.height' => 'required|numeric|between:1,100000']);
        $drawing = DB::transaction(function () use ($request, $sheet, $data) {
            DrawingSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $prior = CadDrawing::where('reference->sourceSheetId', $sheet->id)->where('reference->importRequestKey', $data['requestKey'])->first();
            if ($prior) {
                $this->cad->access($request->user(), $prior, true);
                abort_unless($prior->title === $data['title'] && (int) $prior->created_by === (int) $request->user()->id, 409);
                foreach (['page', 'width', 'height'] as $key) {
                    abort_unless((float) $prior->reference[$key] === (float) $data['reference'][$key], 409);
                }

                return $prior;
            }
            $bytes = $this->sourceSheetFile($request, $sheet)->getContent();
            $temp = tempnam(sys_get_temp_dir(), 'cad-pdf-');
            try {
                file_put_contents($temp, $bytes);
                $file = new UploadedFile($temp, 'drawing.pdf', 'application/pdf', null, true);

                return $this->cad->import($request->user(), $sheet->site, $file, $data['title'], $data['reference'], $sheet, $data['requestKey']);
            } finally {
                @unlink($temp);
            }
        });

        return response()->json($this->cad->read($drawing) + ['canManage' => true, 'canExport' => $this->cad->canExport($request->user())], 201);
    }

    public function update(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing, true);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'dxf' => 'required|string', 'markups' => 'sometimes|present|array|max:1000', 'workspace' => 'sometimes|present|array']);

        return response()->json($this->cad->save($request->user(), $drawing, $data['revision'], $data['dxf'], $data['markups'] ?? null, $data['workspace'] ?? null));
    }

    public function history(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing);
        $data = $request->validate(['revision' => 'nullable|integer|min:1']);
        if (isset($data['revision'])) {
            return response()->json($this->cad->read($drawing, $data['revision']) + ['canManage' => false, 'canExport' => $this->cad->canExport($request->user())]);
        }

        return response()->json(['revisions' => $drawing->revisions()->orderByDesc('revision')->get(['revision', 'created_by', 'created_at', 'warnings'])]);
    }

    public function copy(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing, true);
        $data = $request->validate(['requestKey' => 'required|uuid', 'revision' => 'required|integer|min:1', 'title' => 'required|string|max:255', 'dxf' => 'required|string',
            'markups' => 'present|array|max:1000', 'workspace' => 'present|array']);

        return response()->json($this->cad->copy($request->user(), $drawing, $data['revision'], $data['title'], $data['dxf'], $data['markups'], $data['workspace'], $data['requestKey']), 201);
    }

    public function download(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing, false, true);
        $data = $request->validate(['format' => 'nullable|in:dwg,dxf,original', 'revision' => 'nullable|integer|min:1']);
        $format = $data['format'] ?? 'dxf';
        abort_if($drawing->reference && $format !== 'original', 422, 'PDF/이미지는 원본 또는 표시 포함 PDF로 출력하세요.');
        $revision = $data['revision'] ?? $drawing->revision;
        if ($format === 'original') {
            abort_unless(Storage::disk($drawing->disk)->exists($drawing->original_path), 404);

            return Storage::disk($drawing->disk)->download($drawing->original_path, 'original-'.$drawing->id.'.'.$drawing->source_format, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        $bytes = $this->cad->read($drawing, $revision)['dxf'];
        $warnings = [];
        if ($format === 'dwg') {
            $result = $this->converter->convert($bytes, 'dxf', 'dwg');
            $bytes = $result['bytes'];
            abort_unless(preg_match('/^AC10[0-9]{2}/', $bytes) === 1, 503, '변환 엔진이 올바른 DWG를 생성하지 못했습니다.');
            $warnings = $result['warnings'];
        }

        // Keep response headers below proxy limits; complete import warnings remain on the revision.
        $headerWarnings = array_map(fn (string $warning) => mb_strcut($warning, 0, 300, 'UTF-8'), array_slice($warnings, 0, 6));
        if (count($warnings) > 6) {
            $headerWarnings[] = '추가 변환 경고가 있습니다. 원래 CAD에서 도면을 검증하세요.';
        }

        return response($bytes)->withHeaders(['Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="drawing-'.$drawing->id.'-r'.$revision.'.'.$format.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'X-CAD-Warnings' => base64_encode(json_encode($headerWarnings, JSON_UNESCAPED_UNICODE))]);
    }

    public function reference(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing);
        abort_unless($drawing->reference && Storage::disk($drawing->disk)->exists($drawing->original_path), 404);

        return response(Storage::disk($drawing->disk)->get($drawing->original_path))->withHeaders([
            'Content-Type' => $drawing->reference['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="drawing.'.$drawing->source_format.'"',
        ]);
    }
}
