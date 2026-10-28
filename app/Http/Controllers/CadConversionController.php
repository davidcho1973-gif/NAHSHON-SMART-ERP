<?php

namespace App\Http\Controllers;

use App\Models\CadConversion;
use App\Models\CadDrawing;
use App\Models\Site;
use App\Services\Drawings\CadConversionService;
use App\Services\Drawings\CadDrawingService;
use App\Support\CadFileLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CadConversionController extends Controller
{
    public function export(Request $request, CadDrawing $drawing, CadConversionService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'requestKey' => 'required|uuid']);

        return response()->json($service->status($service->export($request->user(), $drawing, $data['revision'], $data['requestKey'])), 202);
    }

    public function index(Request $request, CadDrawingService $cad, CadConversionService $service)
    {
        $cad->gate($request->user());
        $sites = $cad->sites($request->user())->pluck('id');
        $tasks = CadConversion::where('created_by', $request->user()->id)->whereIn('site_id', $sites)
            ->orderByRaw("CASE WHEN status IN ('queued', 'processing') THEN 0 ELSE 1 END")
            ->latest()->limit(20)->get()->map(fn ($task) => $service->status($task));

        return response()->json(['conversions' => $tasks]);
    }

    public function store(Request $request, CadConversionService $service)
    {
        $data = $request->validate(['file' => 'required|file|max:'.(CadFileLimits::drawingBytes() / 1024),
            'site_id' => 'required|integer', 'title' => 'required|string|max:255', 'requestKey' => 'required|uuid']);
        $task = $service->submit($request->user(), Site::findOrFail($data['site_id']), $request->file('file'), $data['title'], $data['requestKey']);

        return response()->json($service->status($task), 202);
    }

    public function show(Request $request, CadConversion $conversion, CadConversionService $service)
    {
        $service->access($request->user(), $conversion);

        return response()->json($service->status($conversion));
    }

    public function retry(Request $request, CadConversion $conversion, CadConversionService $service)
    {
        $service->access($request->user(), $conversion);
        if ($conversion->operation === 'import') {
            app(CadDrawingService::class)->gate($request->user(), true);
        }

        return response()->json($service->status($service->retry($conversion)), 202);
    }

    public function original(Request $request, CadConversion $conversion, CadConversionService $service, CadDrawingService $cad)
    {
        $service->access($request->user(), $conversion);
        $cad->gate($request->user(), false, true);

        return Storage::disk($conversion->disk)->download($conversion->source_path, $conversion->original_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, CadConversion $conversion, CadConversionService $service)
    {
        $service->access($request->user(), $conversion);
        abort_unless($conversion->operation === 'export' && $conversion->status === 'completed', 409);

        return Storage::disk($conversion->disk)->download($conversion->result['outputPath'], 'drawing-'.$conversion->result['sourceDrawingId'].'.dwg',
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
