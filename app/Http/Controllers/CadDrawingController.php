<?php

namespace App\Http\Controllers;

use App\Models\CadDrawing;
use App\Models\Site;
use App\Services\Drawings\CadClaimDraftService;
use App\Services\Drawings\CadConverter;
use App\Services\Drawings\CadDrawingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        return response()->json($this->converter->capabilities());
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
            ->orderByDesc('updated_at')->limit(500)->get(['id', 'title', 'site_id', 'revision', 'source_format', 'updated_at']);

        return response()->json(['drawings' => $drawings, 'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code]), 'canManage' => $this->cad->canManage($request->user())]);
    }

    public function store(Request $request)
    {
        $this->cad->gate($request->user(), true);
        $data = $request->validate(['file' => 'required|file|max:20480', 'site_id' => 'required|integer', 'title' => 'required|string|max:255']);
        $drawing = $this->cad->import($request->user(), Site::findOrFail($data['site_id']), $request->file('file'), $data['title']);

        return response()->json($this->cad->read($drawing) + ['canManage' => true, 'canExport' => $this->cad->canExport($request->user())], 201);
    }

    public function show(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing);

        return response()->json($this->cad->read($drawing) + ['canManage' => $this->cad->canManage($request->user()), 'canExport' => $this->cad->canExport($request->user())]);
    }

    public function update(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing, true);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'dxf' => 'required|string', 'markups' => 'sometimes|present|array|max:1000']);

        return response()->json($this->cad->save($request->user(), $drawing, $data['revision'], $data['dxf'], $data['markups'] ?? null));
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

    public function download(Request $request, CadDrawing $drawing)
    {
        $this->cad->access($request->user(), $drawing, false, true);
        $data = $request->validate(['format' => 'nullable|in:dwg,dxf,original', 'revision' => 'nullable|integer|min:1']);
        $format = $data['format'] ?? 'dxf';
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
}
