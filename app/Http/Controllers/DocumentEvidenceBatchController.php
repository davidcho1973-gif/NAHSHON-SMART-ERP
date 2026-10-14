<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentEvidenceBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentEvidenceBatchController extends Controller
{
    public function __construct(private readonly DocumentEvidenceBatchService $batches) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->batches->options($request->user()));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id'],
            'contract_id' => ['nullable', 'integer', 'exists:project_contracts,id'], 'upload_session_key' => ['nullable', 'uuid']]);
        $batch = $this->batches->create($request->user(), (int) $data['project_id'], ! empty($data['contract_id']) ? (int) $data['contract_id'] : null, $data['upload_session_key'] ?? null);

        return response()->json(['success' => true, 'batch' => $this->batches->summary($batch)], 201);
    }

    public function upload(Request $request, int $batch): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('document-intelligence.max_upload_kb', 51200)], 'upload_key' => ['required', 'uuid']]);

        return response()->json($this->batches->upload($request->user(), $batch, $request->file('file'), $data['upload_key']), 201);
    }

    public function start(Request $request, int $batch): JsonResponse
    {
        $request->validate(['retry_failed' => ['sometimes', 'boolean'], 'rematch' => ['sometimes', 'boolean'], 'contract_id' => ['nullable', 'integer', 'exists:project_contracts,id']]);

        return response()->json($this->batches->start($request->user(), $batch, $request->boolean('retry_failed'), $request->boolean('rematch'), $request->integer('contract_id') ?: null), 202);
    }

    public function show(Request $request, int $batch): JsonResponse
    {
        return response()->json($this->batches->show($request->user(), $batch, $request->integer('page', 1), $request->boolean('review_only')));
    }
}
