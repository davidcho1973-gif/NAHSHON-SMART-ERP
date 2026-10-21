<?php

namespace App\Http\Controllers;

use App\Services\Drawings\CadAiSchema;
use App\Services\Drawings\CadAiSessionService;
use Illuminate\Http\Request;

class CadAiSessionController extends Controller
{
    public function __construct(private readonly CadAiSessionService $sessions, private readonly CadAiSchema $schema) {}

    private function token(Request $request): string
    {
        return (string) $request->header('X-CAD-Editor-Token', '');
    }

    private function bounded(Request $request, array $keys): void
    {
        abort_if(strlen($request->getContent()) > 600000, 413, 'Use a smaller CAD selection.');
        $this->schema->keys($request->all(), $keys);
    }

    public function store(Request $request)
    {
        $this->bounded($request, ['drawingId', 'revision', 'context', 'allowEdits']);
        $data = $request->validate(['drawingId' => 'required|integer|min:1', 'revision' => 'required|integer|min:1', 'context' => 'required|array', 'allowEdits' => 'required|boolean']);

        return response()->json($this->sessions->create($request->user(), $data['drawingId'], $data['revision'], $data['context'], (bool) $data['allowEdits']), 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $session)
    {
        $this->bounded($request, ['context', 'allowEdits']);
        $data = $request->validate(['context' => 'required|array', 'allowEdits' => 'required|boolean']);

        return response()->json($this->sessions->update($request->user(), $session, $this->token($request), $data['context'], (bool) $data['allowEdits']))->header('Cache-Control', 'private, no-store');
    }

    public function commands(Request $request, string $session)
    {
        return response()->json($this->sessions->commands($request->user(), $session, $this->token($request)))->header('Cache-Control', 'private, no-store');
    }

    public function result(Request $request, string $session, string $command)
    {
        $this->bounded($request, ['status', 'message', 'context']);
        $data = $request->validate(['status' => 'required|in:applied,rejected,failed', 'message' => 'present|nullable|string|max:1000', 'context' => 'nullable|array']);

        return response()->json($this->sessions->result($request->user(), $session, $this->token($request), $command, $data['status'], (string) ($data['message'] ?? ''), $data['context'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $session)
    {
        return response()->json($this->sessions->stop($request->user(), $session, $this->token($request)))->header('Cache-Control', 'private, no-store');
    }
}
