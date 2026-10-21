<?php

namespace App\Http\Controllers;

use App\Models\CadDrawing;
use App\Models\CadDrawingPacket;
use App\Models\CadLocationLink;
use App\Models\CadPurchaseDraft;
use App\Models\CadTask;
use App\Services\Drawings\CadDrawingService;
use App\Services\Drawings\CadPacketService;
use App\Services\Drawings\CadPurchaseDraftService;
use App\Services\Drawings\CadTargetService;
use App\Services\Drawings\CadWorkflowService;
use App\Services\Drawings\DrawingShareService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CadWorkflowController extends Controller
{
    public function context(Request $request, CadDrawing $drawing, CadWorkflowService $workflow)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return response()->json($workflow->context($drawing, $data['revision']));
    }

    public function targets(Request $request, CadDrawing $drawing, CadTargetService $targets)
    {
        $data = $request->validate(['type' => ['required', Rule::in(CadTargetService::TYPES)], 'q' => 'nullable|string|max:120', 'after' => 'nullable|integer|min:0']);

        return response()->json($targets->catalog($drawing, $data['type'], $data['q'] ?? '', (int) ($data['after'] ?? 0)));
    }

    public function link(Request $request, CadDrawing $drawing, CadWorkflowService $workflow)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'markupId' => 'required|string|max:80', 'type' => ['required', Rule::in(CadTargetService::TYPES)], 'targetId' => 'required|integer|min:1']);

        return response()->json($workflow->link($drawing, $data));
    }

    public function retire(Request $request, CadDrawing $drawing, CadLocationLink $link, CadWorkflowService $workflow)
    {
        $workflow->retire($drawing, $link);

        return response()->json(['success' => true]);
    }

    public function backlinks(Request $request, CadDrawing $drawing, CadWorkflowService $workflow)
    {
        $data = $request->validate(['type' => ['required', Rule::in(CadTargetService::TYPES)], 'targetId' => 'required|integer|min:1']);

        return response()->json(['items' => $workflow->backlinks($drawing, $data['type'], $data['targetId'])]);
    }

    public function task(Request $request, CadDrawing $drawing, CadWorkflowService $workflow)
    {
        return response()->json($workflow->createTask($drawing, $request->all()), 201);
    }

    public function updateTask(Request $request, CadDrawing $drawing, CadTask $task, CadWorkflowService $workflow)
    {
        return response()->json($workflow->updateTask($drawing, $task, $request->all()));
    }

    public function basis(Request $request, CadDrawing $drawing, CadPurchaseDraftService $drafts)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'markupId' => 'required|string|max:80']);

        return response()->json($drafts->basis($drawing, $data['revision'], $data['markupId']));
    }

    public function savePurchase(Request $request, CadDrawing $drawing, CadPurchaseDraftService $drafts)
    {
        return response()->json($drafts->save($drawing, $request->all()));
    }

    public function submitPurchase(Request $request, CadDrawing $drawing, CadPurchaseDraft $draft, CadPurchaseDraftService $drafts)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reviewed' => 'required|accepted']);

        return response()->json($drafts->submit($drawing, $draft, (int) $data['version'], true));
    }

    public function packets(Request $request, CadDrawing $drawing, CadPacketService $packets, CadDrawingService $cad)
    {
        $cad->access($request->user(), $drawing, false, true);
        $items = CadDrawingPacket::whereIn('cad_drawing_revision_id', $drawing->revisions()->select('id'))->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['items' => $items->map(function ($p) use ($packets) {
            try {
                $packets->access($p);

                return $packets->row($p);
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }

                return null;
            } catch (ModelNotFoundException) {
                return null;
            }
        })->filter()->values()]);
    }

    public function createPacket(Request $request, CadDrawing $drawing, CadPacketService $packets)
    {
        $data = $request->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'snapshotToken' => 'required|string|size:64', 'title' => 'required|string|max:255', 'reviewed' => 'required|accepted',
            'pdf' => 'required|file|mimetypes:application/pdf|max:20480', 'references' => 'sometimes|array|max:40', 'references.*' => 'required|array:type,id', 'references.*.type' => ['required', Rule::in(CadTargetService::TYPES)], 'references.*.id' => 'required|integer|min:1']);

        return response()->json($packets->create($drawing, $data, $request->file('pdf')), 201);
    }

    public function downloadPacket(Request $request, CadDrawing $drawing, CadDrawingPacket $packet, CadPacketService $packets)
    {
        abort_unless((int) $packet->revision->cad_drawing_id === (int) $drawing->id, 404);
        $data = $request->validate(['format' => 'nullable|in:pdf,zip', 'inline' => 'nullable|boolean']);
        $pdf = ($data['format'] ?? 'zip') === 'pdf';

        return response($packets->bytes($packet, $pdf))->withHeaders(['Content-Type' => $pdf ? 'application/pdf' : 'application/zip', 'Content-Disposition' => ($pdf && $request->boolean('inline') ? 'inline' : 'attachment').'; filename="drawing-'.$drawing->id.'-r'.$packet->revision->revision.'.'.($pdf ? 'pdf' : 'zip').'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function shareOptions(Request $request, CadDrawing $drawing, CadDrawingService $cad, DrawingShareService $sharing)
    {
        $cad->access($request->user(), $drawing, true, true);

        return response()->json($sharing->options($request->user()));
    }

    public function sharePacket(Request $request, CadDrawing $drawing, CadDrawingPacket $packet, CadPacketService $packets, DrawingShareService $sharing)
    {
        abort_unless((int) $packet->revision->cad_drawing_id === (int) $drawing->id, 404);
        $request->validate(['reviewed' => 'required|accepted']);

        return response()->json($sharing->send($request->user(), $packet, $request->all(), $packets->bytes($packet, true), 'application/pdf'));
    }
}
